<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * §4.3 driver onboarding + licence verification log.
 *
 * `mc_driver_profiles` is per-user. The verification log
 * `mc_licence_verifications` is **append-only** — a manual
 * re-verification creates a new row. The driver's
 * `compliance_status` is recomputed on every write so the
 * UI can rely on it without re-running the matrix.
 */
class DriverService
{
	public const STATUS_NOT_PROVIDED = 'not_provided';
	public const STATUS_UPLOADED_PENDING = 'uploaded_pending_verification';
	public const STATUS_VERIFIED = 'verified';
	public const STATUS_EXPIRED = 'expired';
	public const STATUS_REJECTED = 'rejected';

	public const COMPLIANCE_ACTIVE = 'active';
	public const COMPLIANCE_INSTRUCTIONS_PENDING = 'instructions_pending';
	public const COMPLIANCE_BLOCKED = 'blocked';

	public function __construct(
		private IDBConnection $db,
		private AuditLogService $audit,
		private IUserManager $userManager,
	) {
	}

	/**
	 * @param list<string>|null $userIdsOnly When set, only profiles whose {@see user_id} is in this list.
	 * @return list<array<string,mixed>>
	 */
	public function list(?array $userIdsOnly = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_driver_profiles')->orderBy('user_id', 'ASC');
		if ($userIdsOnly !== null) {
			$ids = array_values(array_filter($userIdsOnly, static fn ($id) => is_string($id) && $id !== ''));
			if ($ids === []) {
				return [];
			}
			$qb->andWhere($qb->expr()->in('user_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		$res = $qb->executeQuery();
		$rows = [];
		while (($r = $res->fetch()) !== false) {
			$rows[] = $this->hydrate($r);
		}
		$res->closeCursor();
		return $rows;
	}

	public function get(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_driver_profiles')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if (!$row) {
			throw new NotFoundException('DRIVER_NOT_FOUND');
		}
		return $this->hydrate($row);
	}

	public function getByUserId(string $userId): ?array
	{
		if ($userId === '') return null;
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_driver_profiles')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$row = $qb->executeQuery()->fetch();
		return $row ? $this->hydrate($row) : null;
	}

	public function ensureProfileForUser(string $userId, string $performedBy): array
	{
		$existing = $this->getByUserId($userId);
		if ($existing !== null) {
			return $existing;
		}
		if ($this->userManager->get($userId) === null) {
			throw new ValidationException('INVALID_USER_ID');
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('mc_driver_profiles')->values([
			'user_id' => $qb->createNamedParameter($userId),
			'licence_status' => $qb->createNamedParameter(self::STATUS_NOT_PROVIDED),
			'compliance_status' => $qb->createNamedParameter(self::COMPLIANCE_BLOCKED),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
		]);
		$qb->executeStatement();
		$id = (int)$this->db->lastInsertId('mc_driver_profiles');
		$this->audit->log('driver_profile', $id, 'create', $performedBy, ['user_id' => $userId]);
		return $this->get($id);
	}

	/** @param array<string,mixed> $payload */
	public function update(int $id, array $payload, string $performedBy, bool $isOwnProfile): array
	{
		$current = $this->get($id);
		// Drivers may edit their own profile but cannot flip status or set
		// commute_distance_km without going through a manager (audit-by-design).
		$licenceNumber = $payload['licence_number'] ?? $current['licence_number'];
		$licenceClasses = $payload['licence_classes'] ?? $current['licence_classes'];
		$issueDate = $this->dateOrNull($payload['licence_issue_date'] ?? $current['licence_issue_date']);
		$expiryDate = $this->dateOrNull($payload['licence_expiry_date'] ?? $current['licence_expiry_date']);
		$authority = $payload['licence_authority'] ?? $current['licence_authority'];
		$commute = $payload['commute_distance_km'] ?? $current['commute_distance_km'];
		$notes = $payload['notes'] ?? $current['notes'];
		$homeStationId = isset($current['home_station_id']) && $current['home_station_id'] !== null ? (int)$current['home_station_id'] : null;
		if (!$isOwnProfile && (array_key_exists('home_station_id', $payload) || array_key_exists('homeStationId', $payload))) {
			$hs = $payload['home_station_id'] ?? $payload['homeStationId'] ?? null;
			if ($hs === null || $hs === '') {
				$homeStationId = null;
			} else {
				$hid = (int)$hs;
				if ($hid <= 0) {
					throw new ValidationException('HOME_STATION_INVALID', 'home_station_id');
				}
				if (!$this->stationExistsAndActive($hid)) {
					throw new ValidationException('STATION_NOT_FOUND', 'home_station_id');
				}
				$homeStationId = $hid;
			}
		}

		if ($licenceNumber !== null && strlen((string)$licenceNumber) > 64) {
			throw new ValidationException('LICENCE_NUMBER_TOO_LONG', 'licence_number');
		}
		if ($licenceClasses !== null && !preg_match('/^[A-Z0-9, ]{0,80}$/', (string)$licenceClasses)) {
			throw new ValidationException('LICENCE_CLASSES_INVALID', 'licence_classes');
		}
		if ($commute !== null && $commute !== '') {
			$commute = (int)$commute;
			if ($commute < 0 || $commute > 5000) {
				throw new ValidationException('COMMUTE_INVALID', 'commute_distance_km');
			}
		} else {
			$commute = null;
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_driver_profiles')
			->set('licence_number', $qb->createNamedParameter($licenceNumber))
			->set('licence_classes', $qb->createNamedParameter($licenceClasses))
			->set('licence_issue_date', $qb->createNamedParameter($issueDate))
			->set('licence_expiry_date', $qb->createNamedParameter($expiryDate))
			->set('licence_authority', $qb->createNamedParameter($authority))
			->set('commute_distance_km', $commute !== null
				? $qb->createNamedParameter($commute, IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('home_station_id', $homeStationId !== null
				? $qb->createNamedParameter($homeStationId, IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('notes', $qb->createNamedParameter($notes))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$this->audit->log('driver_profile', $id, $isOwnProfile ? 'self_update' : 'update', $performedBy, [
			'licence_number' => [$current['licence_number'], $licenceNumber],
			'licence_expiry_date' => [$current['licence_expiry_date'], $expiryDate],
			'commute_distance_km' => [$current['commute_distance_km'], $commute],
			'home_station_id' => [$current['home_station_id'] ?? null, $homeStationId],
		]);
		return $this->get($id);
	}

	public function attachLicenceScan(int $id, string $fileNodeId, string $performedBy): array
	{
		$this->get($id);
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_driver_profiles')
			->set('licence_scan_file_id', $qb->createNamedParameter($fileNodeId))
			->set('licence_status', $qb->createNamedParameter(self::STATUS_UPLOADED_PENDING))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('driver_profile', $id, 'licence_upload', $performedBy, ['file_id' => $fileNodeId]);
		$this->recomputeCompliance($id);
		return $this->get($id);
	}

	public function verifyLicence(int $id, string $verifiedBy, ?string $note): array
	{
		$current = $this->get($id);
		if ($current['licence_expiry_date'] === null) {
			throw new ValidationException('LICENCE_EXPIRY_REQUIRED');
		}
		$n = $note === null ? '' : trim($note);
		if ($n === '') {
			$n = null;
		} else {
			$maxNote = 8000;
			$len = function_exists('mb_strlen') ? mb_strlen($n, 'UTF-8') : strlen($n);
			if ($len > $maxNote) {
				throw new ValidationException('LICENCE_VERIFICATION_NOTE_TOO_LONG', 'note', ['max' => $maxNote]);
			}
		}
		$now = gmdate('Y-m-d H:i:s');
		$this->db->beginTransaction();
		try {
			$ins = $this->db->getQueryBuilder();
			$ins->insert('mc_licence_verifications')->values([
				'driver_profile_id' => $ins->createNamedParameter($id, IQueryBuilder::PARAM_INT),
				'verified_by_user_id' => $ins->createNamedParameter($verifiedBy),
				'verified_at' => $ins->createNamedParameter($now),
				'licence_expiry_at_verification' => $ins->createNamedParameter($current['licence_expiry_date']),
				'notes' => $ins->createNamedParameter($n),
			]);
			$ins->executeStatement();
			$upd = $this->db->getQueryBuilder();
			$upd->update('mc_driver_profiles')
				->set('licence_status', $upd->createNamedParameter(self::STATUS_VERIFIED))
				->set('updated_at', $upd->createNamedParameter($now))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$upd->executeStatement();
			$this->audit->log('driver_profile', $id, 'licence_verified', $verifiedBy, [
				'licence_status' => [$current['licence_status'], self::STATUS_VERIFIED],
			], $n);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->recomputeCompliance($id);
		return $this->get($id);
	}

	public function rejectLicence(int $id, string $performedBy, string $reason): array
	{
		$r = trim($reason);
		if ($r === '') {
			throw new ValidationException('LICENCE_REJECT_REASON_REQUIRED', 'reason');
		}
		$maxReason = 8000;
		$len = function_exists('mb_strlen') ? mb_strlen($r, 'UTF-8') : strlen($r);
		if ($len > $maxReason) {
			throw new ValidationException('LICENCE_REJECT_REASON_TOO_LONG', 'reason', ['max' => $maxReason]);
		}
		$current = $this->get($id);
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_driver_profiles')
			->set('licence_status', $qb->createNamedParameter(self::STATUS_REJECTED))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('driver_profile', $id, 'licence_rejected', $performedBy, [
			'licence_status' => [$current['licence_status'], self::STATUS_REJECTED],
		], $r);
		$this->recomputeCompliance($id);
		return $this->get($id);
	}

	/**
	 * Recompute the cached `compliance_status` for one profile.
	 * Logic mirrors {@see ComplianceService::evaluate()} but is kept here
	 * to avoid a circular service dependency (DriverService is used by
	 * ComplianceService internally).
	 */
	public function recomputeCompliance(int $id): void
	{
		$current = $this->get($id);
		$status = $this->computeCompliance($current);
		if ($status === $current['compliance_status']) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_driver_profiles')
			->set('compliance_status', $qb->createNamedParameter($status))
			->set('updated_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function computeCompliance(array $driver): string
	{
		$today = gmdate('Y-m-d');
		// Licence must be VERIFIED and not expired.
		if ($driver['licence_status'] !== self::STATUS_VERIFIED) {
			return self::COMPLIANCE_BLOCKED;
		}
		if ($driver['licence_expiry_date'] !== null && $driver['licence_expiry_date'] < $today) {
			// Auto-flip the licence row to expired so the UI reflects reality.
			$qb = $this->db->getQueryBuilder();
			$qb->update('mc_driver_profiles')
				->set('licence_status', $qb->createNamedParameter(self::STATUS_EXPIRED))
				->set('updated_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$driver['id'], IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
			return self::COMPLIANCE_BLOCKED;
		}
		// Current-year instruction (§4.4).
		$year = (int)gmdate('Y');
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('mc_instruction_records')
			->where($qb->expr()->eq('driver_profile_id', $qb->createNamedParameter((int)$driver['id'], IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		$hasInstruction = $qb->executeQuery()->fetchOne() !== false;
		return $hasInstruction ? self::COMPLIANCE_ACTIVE : self::COMPLIANCE_INSTRUCTIONS_PENDING;
	}

	/**
	 * §7.2 GET /api/drivers/{id}/compliance — combines licence + instruction state.
	 */
	public function complianceDetail(int $id): array
	{
		$d = $this->get($id);
		$today = gmdate('Y-m-d');
		$daysToExpiry = null;
		if ($d['licence_expiry_date'] !== null) {
			$diff = (strtotime($d['licence_expiry_date']) - strtotime($today)) / 86400;
			$daysToExpiry = (int)floor($diff);
		}
		$year = (int)gmdate('Y');
		$qb = $this->db->getQueryBuilder();
		$qb->select('calendar_year', 'completed_date', 'recorded_by_user_id', 'reference')
			->from('mc_instruction_records')
			->where($qb->expr()->eq('driver_profile_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->orderBy('calendar_year', 'DESC');
		$res = $qb->executeQuery();
		$instructions = [];
		while (($r = $res->fetch()) !== false) {
			$instructions[] = [
				'year' => (int)$r['calendar_year'],
				'completedDate' => substr((string)$r['completed_date'], 0, 10),
				'recordedBy' => (string)$r['recorded_by_user_id'],
				'reference' => $r['reference'] !== null ? (string)$r['reference'] : null,
			];
		}
		$res->closeCursor();
		$qb2 = $this->db->getQueryBuilder();
		$qb2->select('id', 'verified_by_user_id', 'verified_at', 'licence_expiry_at_verification', 'notes')
			->from('mc_licence_verifications')
			->where($qb2->expr()->eq('driver_profile_id', $qb2->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->orderBy('verified_at', 'DESC');
		$res2 = $qb2->executeQuery();
		$verifications = [];
		while (($r = $res2->fetch()) !== false) {
			$verifications[] = [
				'id' => (int)$r['id'],
				'verifiedBy' => (string)$r['verified_by_user_id'],
				'verifiedAt' => (string)$r['verified_at'],
				'licenceExpiryAtVerification' => $r['licence_expiry_at_verification'] !== null ? substr((string)$r['licence_expiry_at_verification'], 0, 10) : null,
				'notes' => $r['notes'] !== null ? (string)$r['notes'] : null,
			];
		}
		$res2->closeCursor();
		return [
			'driver' => $d,
			'currentYear' => $year,
			'daysToExpiry' => $daysToExpiry,
			'instructions' => $instructions,
			'verifications' => $verifications,
			'currentYearInstructionComplete' => array_filter($instructions, fn ($i) => $i['year'] === $year) !== [],
		];
	}

	private function stationExistsAndActive(int $id): bool
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->selectAlias($qb->func()->count('*'), 'c')->from('mc_stations')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
			return (int)($qb->executeQuery()->fetchOne() ?: 0) > 0;
		} catch (\Throwable) {
			return false;
		}
	}

	private function dateOrNull(mixed $v): ?string
	{
		if ($v === null || $v === '') return null;
		$s = trim((string)$v);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
			throw new ValidationException('DATE_INVALID');
		}
		[$y, $m, $d] = array_map('intval', explode('-', $s));
		if (!checkdate($m, $d, $y)) {
			throw new ValidationException('DATE_INVALID');
		}
		return $s;
	}

	private function hydrate(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'user_id' => (string)$row['user_id'],
			'licence_number' => $row['licence_number'] !== null ? (string)$row['licence_number'] : null,
			'licence_classes' => $row['licence_classes'] !== null ? (string)$row['licence_classes'] : null,
			'licence_issue_date' => $row['licence_issue_date'] !== null ? substr((string)$row['licence_issue_date'], 0, 10) : null,
			'licence_expiry_date' => $row['licence_expiry_date'] !== null ? substr((string)$row['licence_expiry_date'], 0, 10) : null,
			'licence_authority' => $row['licence_authority'] !== null ? (string)$row['licence_authority'] : null,
			'licence_scan_file_id' => $row['licence_scan_file_id'] !== null ? (string)$row['licence_scan_file_id'] : null,
			'licence_status' => (string)$row['licence_status'],
			'compliance_status' => (string)$row['compliance_status'],
			'commute_distance_km' => $row['commute_distance_km'] !== null ? (int)$row['commute_distance_km'] : null,
			'home_station_id' => isset($row['home_station_id']) && $row['home_station_id'] !== null ? (int)$row['home_station_id'] : null,
			'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
			'created_at' => (string)$row['created_at'],
			'updated_at' => (string)$row['updated_at'],
		];
	}
}
