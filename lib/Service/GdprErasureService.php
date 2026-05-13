<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ValidationException;
use OCA\MobilityCheck\Util\GdprFreeTextPseudonymizer;
use OCA\MobilityCheck\Util\GdprJsonPseudonymizer;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * §1.15 / §8.10 / §13.40 — GDPR right-to-erasure on immutable evidence
 * records. The personal identifier (`user_id`) is replaced by the
 * tombstone constant `__erased__`; free-text fields that may carry the
 * leaver's display name are masked with a deterministic short hash so
 * downstream queries still group correctly without re-identifying the
 * person. Hard-delete is **never** used here — the immutable evidence
 * chain stays intact for tax + audit purposes (Art. 17(3)(b)+(e) GDPR
 * derogation).
 *
 * JSON columns (`mc_bookings.passenger_user_ids`,
 * `mc_bookings.approval_chain_snapshot_json`,
 * `mc_stations.notification_recipient_user_ids`) are rewritten in PHP so
 * the same logic runs on MySQL, PostgreSQL, and SQLite without JSON SQL
 * dialects. The free-text `mc_bookings.passengers` column is scrubbed for
 * the leaver's user id (token-safe) and, when resolvable, display name.
 */
class GdprErasureService
{
	public const TOMBSTONE = '__erased__';

	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private AuditLogService $audit,
		private LoggerInterface $logger,
		private IUserManager $userManager,
	) {
	}

	/**
	 * Pseudonymise every reference to `$leaverUserId` on the configured
	 * immutable / quasi-immutable tables. Returns a per-table count map.
	 *
	 * @return array<string,int>
	 */
	public function eraseUser(string $leaverUserId, string $performedBy): array
	{
		$this->access->requireFleetAdmin($performedBy);
		$leaver = trim($leaverUserId);
		if ($leaver === '' || $leaver === self::TOMBSTONE) {
			throw new ValidationException('USER_REQUIRED', 'user_id');
		}
		if ($leaver === $performedBy) {
			throw new ValidationException('CANNOT_ERASE_SELF', 'user_id');
		}
		$counts = [];
		$columns = [
			// (table, list-of-user-id-columns) — every column carrying a
			// Nextcloud user-id reference that points at the leaver. Tables
			// without such a column are simply absent. `mc_licence_verifications`
			// + `mc_instruction_records` reference a driver via `driver_profile_id`
			// (FK → mc_driver_profiles.id), not directly via user-id, so the
			// pseudonymisation of `mc_driver_profiles.user_id` covers them
			// transitively.
			['mc_audit_log', ['performed_by_user_id']],
			['mc_booking_approvals', ['approver_user_id']],
			['mc_bookings', ['driver_user_id', 'approved_by_user_id', 'created_by_user_id']],
			['mc_booking_reassignment_suggestions', ['resolved_by_user_id']],
			['mc_checkout_logs', ['recorded_by_user_id']],
			['mc_cost_centres', ['owner_user_id']],
			['mc_cost_entries', ['created_by_user_id', 'charge_driver_user_id']],
			['mc_damage_reports', ['reported_by_user_id', 'chargeable_to_user_id']],
			['mc_damage_photos', ['uploaded_by_user_id']],
			['mc_driver_profiles', ['user_id']],
			['mc_export_downloads', ['user_id']],
			['mc_instruction_records', ['recorded_by_user_id']],
			['mc_licence_verifications', ['verified_by_user_id']],
			['mc_line_manager_assignments', ['driver_user_id', 'line_manager_user_id', 'created_by_user_id']],
			['mc_logbook_entries', ['driver_user_id', 'confirmed_by_user_id']],
			['mc_notification_log', ['recipient_user_id']],
			['mc_rate_limits', ['user_id']],
			['mc_relocation_tasks', ['assigned_to_user_id']],
			['mc_reimbursement_claims', ['driver_user_id', 'reviewed_by_user_id']],
			['mc_repair_jobs', ['assigned_workshop_user_id', 'created_by_user_id']],
			['mc_search_profiles', ['user_id']],
			['mc_user_roles', ['user_id']],
			['mc_vehicle_assignments', ['assigned_user_id', 'created_by_user_id']],
			['mc_vehicle_group_members', ['user_id']],
		];
		$failures = [];
		foreach ($columns as [$table, $cols]) {
			try {
				foreach ($cols as $col) {
					$qb = $this->db->getQueryBuilder();
					$qb->update($table)
						->set($col, $qb->createNamedParameter(self::TOMBSTONE))
						->where($qb->expr()->eq($col, $qb->createNamedParameter($leaver)));
					$affected = (int)$qb->executeStatement();
					if ($affected > 0) {
						$counts[$table] = ($counts[$table] ?? 0) + $affected;
					}
				}
			} catch (\Throwable $e) {
				// Optional tables (e.g. a future module not present in this
				// install) must not break the erasure. The failure is still
				// recorded so the auditor can confirm coverage; an admin can
				// then re-run after the schema drift is reconciled.
				$failures[$table] = substr($e->getMessage(), 0, 200);
				$this->logger->warning(
					'MobilityCheck: GDPR erasure on {table} failed: {error}',
					[
						'app' => 'mobilitycheck',
						'table' => $table,
						'error' => $e->getMessage(),
						'exception' => $e,
					],
				);
			}
		}
		$this->eraseJsonEmbeddedUserIds($leaver, $counts, $failures);
		$ctx = [
			'leaver_user_id_hash' => substr(hash('sha256', $leaver), 0, 16),
			'counts' => $counts,
		];
		if ($failures !== []) {
			$ctx['table_failures'] = $failures;
		}
		$this->audit->log('gdpr_erasure', 0, 'erase_user', $performedBy, $ctx);
		return $counts;
	}

	/**
	 * Strip or pseudonymise user ids in JSON TEXT columns and free-text
	 * passenger notes on bookings.
	 *
	 * @param array<string,int> $counts
	 * @param array<string,string> $failures
	 */
	private function eraseJsonEmbeddedUserIds(string $leaver, array &$counts, array &$failures): void
	{
		try {
			$n = $this->eraseBookingJsonColumns($leaver);
			if ($n > 0) {
				$counts['mc_bookings'] = ($counts['mc_bookings'] ?? 0) + $n;
			}
		} catch (\Throwable $e) {
			$failures['mc_bookings_json'] = substr($e->getMessage(), 0, 200);
			$this->logger->warning(
				'MobilityCheck: GDPR JSON erasure on mc_bookings failed: {error}',
				['app' => 'mobilitycheck', 'error' => $e->getMessage(), 'exception' => $e],
			);
		}
		try {
			$n = $this->eraseStationNotificationRecipientsJson($leaver);
			if ($n > 0) {
				$counts['mc_stations'] = ($counts['mc_stations'] ?? 0) + $n;
			}
		} catch (\Throwable $e) {
			$failures['mc_stations_json'] = substr($e->getMessage(), 0, 200);
			$this->logger->warning(
				'MobilityCheck: GDPR JSON erasure on mc_stations failed: {error}',
				['app' => 'mobilitycheck', 'error' => $e->getMessage(), 'exception' => $e],
			);
		}
		try {
			$n = $this->eraseBookingPassengersFreeText($leaver);
			if ($n > 0) {
				$counts['mc_bookings'] = ($counts['mc_bookings'] ?? 0) + $n;
			}
		} catch (\Throwable $e) {
			$failures['mc_bookings_passengers'] = substr($e->getMessage(), 0, 200);
			$this->logger->warning(
				'MobilityCheck: GDPR passengers text erasure on mc_bookings failed: {error}',
				['app' => 'mobilitycheck', 'error' => $e->getMessage(), 'exception' => $e],
			);
		}
	}

	private function eraseBookingJsonColumns(string $leaver): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'passenger_user_ids', 'approval_chain_snapshot_json')
			->from('mc_bookings')
			->where($qb->expr()->orX(
				$qb->expr()->andX(
					$qb->expr()->isNotNull('passenger_user_ids'),
					$qb->expr()->neq('passenger_user_ids', $qb->createNamedParameter('')),
				),
				$qb->expr()->andX(
					$qb->expr()->isNotNull('approval_chain_snapshot_json'),
					$qb->expr()->neq('approval_chain_snapshot_json', $qb->createNamedParameter('')),
				),
			));
		$res = $qb->executeQuery();
		$updated = 0;
		while (($row = $res->fetch()) !== false) {
			$id = (int)$row['id'];
			$passRaw = $row['passenger_user_ids'] !== null && $row['passenger_user_ids'] !== ''
				? (string)$row['passenger_user_ids'] : null;
			$chainRaw = $row['approval_chain_snapshot_json'] !== null && $row['approval_chain_snapshot_json'] !== ''
				? (string)$row['approval_chain_snapshot_json'] : null;
			[$pChanged, $pNew] = GdprJsonPseudonymizer::scrubJsonStringUidList($passRaw, $leaver);
			[$cChanged, $cNew] = GdprJsonPseudonymizer::scrubNestedUidStrings($chainRaw, $leaver, self::TOMBSTONE);
			if (!$pChanged && !$cChanged) {
				continue;
			}
			$upd = $this->db->getQueryBuilder();
			$upd->update('mc_bookings')
				->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			if ($pChanged) {
				if ($pNew === null) {
					$upd->set('passenger_user_ids', $upd->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
				} else {
					$upd->set('passenger_user_ids', $upd->createNamedParameter($pNew));
				}
			}
			if ($cChanged) {
				if ($cNew === null) {
					$upd->set('approval_chain_snapshot_json', $upd->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
				} else {
					$upd->set('approval_chain_snapshot_json', $upd->createNamedParameter($cNew));
				}
			}
			$upd->executeStatement();
			$updated++;
		}
		$res->closeCursor();
		return $updated;
	}

	private function eraseStationNotificationRecipientsJson(string $leaver): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'notification_recipient_user_ids')
			->from('mc_stations')
			->where($qb->expr()->isNotNull('notification_recipient_user_ids'))
			->andWhere($qb->expr()->neq('notification_recipient_user_ids', $qb->createNamedParameter('')));
		$res = $qb->executeQuery();
		$updated = 0;
		while (($row = $res->fetch()) !== false) {
			$id = (int)$row['id'];
			$raw = (string)$row['notification_recipient_user_ids'];
			[$changed, $new] = GdprJsonPseudonymizer::scrubJsonStringUidList($raw, $leaver);
			if (!$changed) {
				continue;
			}
			$upd = $this->db->getQueryBuilder();
			$upd->update('mc_stations')
				->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			if ($new === null) {
				$upd->set('notification_recipient_user_ids', $upd->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
			} else {
				$upd->set('notification_recipient_user_ids', $upd->createNamedParameter($new));
			}
			$upd->executeStatement();
			$updated++;
		}
		$res->closeCursor();
		return $updated;
	}

	/**
	 * Redact the leaver's Nextcloud user id and (when still resolvable)
	 * display name inside the `passengers` free-text column.
	 */
	private function eraseBookingPassengersFreeText(string $leaver): int
	{
		$display = '';
		$user = $this->userManager->get($leaver);
		if ($user !== null) {
			$display = trim($user->getDisplayName());
			if ($display === $leaver) {
				$display = '';
			}
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'passengers')
			->from('mc_bookings')
			->where($qb->expr()->isNotNull('passengers'))
			->andWhere($qb->expr()->neq('passengers', $qb->createNamedParameter('')));
		$res = $qb->executeQuery();
		$updated = 0;
		while (($row = $res->fetch()) !== false) {
			$raw = (string)$row['passengers'];
			$new = GdprFreeTextPseudonymizer::redactNextcloudUid($raw, $leaver, self::TOMBSTONE);
			if ($display !== '') {
				$ph = GdprFreeTextPseudonymizer::displayNamePlaceholder($leaver, $display);
				$new = GdprFreeTextPseudonymizer::redactDisplayName($new, $display, $ph);
			}
			if ($new === $raw) {
				continue;
			}
			$id = (int)$row['id'];
			$upd = $this->db->getQueryBuilder();
			$upd->update('mc_bookings')
				->set('passengers', $upd->createNamedParameter($new))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$upd->executeStatement();
			$updated++;
		}
		$res->closeCursor();
		return $updated;
	}
}
