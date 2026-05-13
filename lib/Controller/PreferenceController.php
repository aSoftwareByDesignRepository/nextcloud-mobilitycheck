<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Exception\ValidationException;
use OCA\MobilityCheck\Service\AccessControlService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IDBConnection;
use OCP\IRequest;

/**
 * Per-user app preferences (§2.5 onboarding dismiss, notification prefs).
 *
 * Security posture (§8, §13.14):
 *  - Keys are whitelisted; unknown keys are rejected with a stable error
 *    code so the table can never become a generic key/value store.
 *  - Values are coerced to the declared type per key (boolean / int /
 *    string / JSON object) and capped at MAX_VALUE_BYTES once JSON-
 *    encoded, so a malicious user cannot bloat the row.
 *  - GET is read-only and idempotent (CSRF exempt is safe); POST mutates
 *    state and therefore requires the standard CSRF token.
 */
class PreferenceController extends BaseApiController
{
	/**
	 * Hard cap on the JSON-encoded value size per preference. Big enough
	 * for the few JSON objects we actually store (timezone, locale, the
	 * dismissed-onboarding maps) and small enough that no single user
	 * can fill the table.
	 */
	private const MAX_VALUE_BYTES = 4096;

	/**
	 * Allowed preference keys → declared type. Anything outside this map
	 * is rejected. Keep this list authoritative; bump the schema rather
	 * than adding ad-hoc keys.
	 *
	 * @var array<string,'bool'|'int'|'string'|'json'>
	 */
	private const ALLOWED_KEYS = [
		// Onboarding (§2.5)
		'onboarding.driver.dismissed' => 'bool',
		'onboarding.fleet_admin.dismissed' => 'bool',
		'onboarding.line_manager.dismissed' => 'bool',
		'onboarding.workshop.dismissed' => 'bool',

		// Notification digest preferences (§5)
		'notifications.digest.frequency' => 'string',
		'notifications.digest.enabled' => 'bool',
		'notifications.email.enabled' => 'bool',
		'notifications.push.enabled' => 'bool',

		// UI preferences (per-user view defaults)
		'ui.bookings.defaultView' => 'string',
		'ui.dashboard.compact' => 'bool',
		'ui.locale.override' => 'string',
		'ui.timezone.override' => 'string',
	];

	/**
	 * Allowed string enums per key. Absent keys allow any short string.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const STRING_ENUMS = [
		'notifications.digest.frequency' => ['off', 'daily', 'weekly'],
		'ui.bookings.defaultView' => ['mine', 'all', 'pending', 'today'],
	];

	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private IDBConnection $db,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function get(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$qb = $this->db->getQueryBuilder();
			$qb->select('pref_key', 'pref_value')
				->from('mc_user_preferences')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$res = $qb->executeQuery();
			$out = [];
			while (($row = $res->fetch()) !== false) {
				$key = (string)($row['pref_key'] ?? '');
				if ($key === '' || !array_key_exists($key, self::ALLOWED_KEYS)) {
					continue;
				}
				$out[$key] = json_decode((string)($row['pref_value'] ?? 'null'), true);
			}
			$res->closeCursor();
			return $out;
		});
	}

	#[NoAdminRequired]
	public function set(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$key = trim((string)($this->request->getParam('key', '') ?? ''));
			if ($key === '' || !array_key_exists($key, self::ALLOWED_KEYS)) {
				throw new ValidationException('UNKNOWN_PREFERENCE_KEY', 'key');
			}
			$rawValue = $this->request->getParam('value', null);
			$normalised = $this->normalise($key, $rawValue);
			$encoded = json_encode($normalised, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
			if (strlen($encoded) > self::MAX_VALUE_BYTES) {
				throw new ValidationException('PREFERENCE_VALUE_TOO_LARGE', 'value', ['max' => self::MAX_VALUE_BYTES]);
			}
			$now = gmdate('Y-m-d H:i:s');

			$update = $this->db->getQueryBuilder();
			$update->update('mc_user_preferences')
				->set('pref_value', $update->createNamedParameter($encoded))
				->set('updated_at', $update->createNamedParameter($now))
				->where($update->expr()->eq('user_id', $update->createNamedParameter($userId)))
				->andWhere($update->expr()->eq('pref_key', $update->createNamedParameter($key)));
			$rows = $update->executeStatement();
			if ($rows === 0) {
				$insert = $this->db->getQueryBuilder();
				$insert->insert('mc_user_preferences')->values([
					'user_id' => $insert->createNamedParameter($userId),
					'pref_key' => $insert->createNamedParameter($key),
					'pref_value' => $insert->createNamedParameter($encoded),
					'updated_at' => $insert->createNamedParameter($now),
				]);
				$insert->executeStatement();
			}

			return ['key' => $key, 'value' => $normalised];
		});
	}

	/**
	 * Coerce/validate a raw value against the declared type of the
	 * preference. Throws a `ValidationException` for shapes we refuse
	 * to store.
	 */
	private function normalise(string $key, mixed $value): mixed
	{
		$type = self::ALLOWED_KEYS[$key];
		switch ($type) {
			case 'bool':
				if (is_bool($value)) {
					return $value;
				}
				if (is_int($value)) {
					return $value !== 0;
				}
				if (is_string($value)) {
					$lower = strtolower(trim($value));
					if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
						return true;
					}
					if (in_array($lower, ['0', 'false', 'no', 'off', ''], true)) {
						return false;
					}
				}
				throw new ValidationException('INVALID_PREFERENCE_VALUE', 'value', ['expected' => 'bool']);
			case 'int':
				if (is_int($value)) {
					return $value;
				}
				if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
					return (int)$value;
				}
				throw new ValidationException('INVALID_PREFERENCE_VALUE', 'value', ['expected' => 'int']);
			case 'string':
				if (!is_string($value)) {
					throw new ValidationException('INVALID_PREFERENCE_VALUE', 'value', ['expected' => 'string']);
				}
				$s = trim($value);
				if (mb_strlen($s) > 120) {
					throw new ValidationException('PREFERENCE_VALUE_TOO_LARGE', 'value', ['max' => 120]);
				}
				if (isset(self::STRING_ENUMS[$key]) && !in_array($s, self::STRING_ENUMS[$key], true)) {
					throw new ValidationException('INVALID_PREFERENCE_VALUE', 'value', [
						'allowed' => self::STRING_ENUMS[$key],
					]);
				}
				return $s;
			case 'json':
				if (!is_array($value)) {
					throw new ValidationException('INVALID_PREFERENCE_VALUE', 'value', ['expected' => 'object']);
				}
				return $value;
		}
		throw new ValidationException('UNKNOWN_PREFERENCE_TYPE', 'key');
	}
}
