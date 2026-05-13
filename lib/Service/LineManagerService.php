<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Supervision assignments: which line manager approves which driver (§6.16).
 */
class LineManagerService
{
	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private IUserManager $userManager,
		private AuditLogService $audit,
	) {
	}

	public function isActiveLineManagerForDriver(string $lineManagerUserId, string $driverUserId): bool
	{
		if ($lineManagerUserId === '' || $driverUserId === '') {
			return false;
		}
		$today = gmdate('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('mc_line_manager_assignments')
			->where($qb->expr()->eq('driver_user_id', $qb->createNamedParameter($driverUserId)))
			->andWhere($qb->expr()->eq('line_manager_user_id', $qb->createNamedParameter($lineManagerUserId)))
			->andWhere($qb->expr()->lte('valid_from', $qb->createNamedParameter($today)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('valid_until'),
				$qb->expr()->gte('valid_until', $qb->createNamedParameter($today)),
			))
			->setMaxResults(1);
		return $qb->executeQuery()->fetchOne() !== false;
	}

	/** Active line manager user id for driver, or null. */
	public function getActiveLineManagerUserIdForDriver(string $driverUserId): ?string
	{
		$today = gmdate('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->select('line_manager_user_id')->from('mc_line_manager_assignments')
			->where($qb->expr()->eq('driver_user_id', $qb->createNamedParameter($driverUserId)))
			->andWhere($qb->expr()->lte('valid_from', $qb->createNamedParameter($today)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('valid_until'),
				$qb->expr()->gte('valid_until', $qb->createNamedParameter($today)),
			))
			->orderBy('valid_from', 'DESC')
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetchOne();
		return $row !== false ? (string)$row : null;
	}

	/**
	 * @param array{driverUserId?:string,driver_user_id?:string,lineManagerUserId?:string,line_manager_user_id?:string,validFrom?:string,valid_from?:string,validUntil?:string,valid_until?:string,notes?:string} $payload
	 * @return array<string,mixed>
	 */
	public function createAssignment(array $payload, string $performedBy): array
	{
		$this->access->requireFleetAdminOrManager($performedBy);
		$driver = trim((string)($payload['driverUserId'] ?? $payload['driver_user_id'] ?? ''));
		$lm = trim((string)($payload['lineManagerUserId'] ?? $payload['line_manager_user_id'] ?? ''));
		if ($driver === '' || $lm === '') {
			throw new ValidationException('LINE_MANAGER_FIELDS_REQUIRED');
		}
		if ($driver === $lm) {
			throw new ValidationException('LINE_MANAGER_SELF');
		}
		$this->assertUserExists($driver);
		$this->assertUserExists($lm);
		$from = trim((string)($payload['validFrom'] ?? $payload['valid_from'] ?? gmdate('Y-m-d')));
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
			throw new ValidationException('DATETIME_INVALID', 'valid_from');
		}
		$untilRaw = $payload['validUntil'] ?? $payload['valid_until'] ?? null;
		$until = $untilRaw !== null && $untilRaw !== '' ? trim((string)$untilRaw) : null;
		if ($until !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) {
			throw new ValidationException('DATETIME_INVALID', 'valid_until');
		}
		$notes = isset($payload['notes']) ? trim((string)$payload['notes']) : null;
		if ($notes === '') {
			$notes = null;
		}
		$this->assertNoOverlappingAssignment($driver, $from, $until, null);
		$this->assertNoCircularSupervision($driver, $lm, $from, $until);
		$now = gmdate('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('mc_line_manager_assignments')->values([
			'driver_user_id' => $ins->createNamedParameter($driver),
			'line_manager_user_id' => $ins->createNamedParameter($lm),
			'valid_from' => $ins->createNamedParameter($from),
			'valid_until' => $until !== null
				? $ins->createNamedParameter($until)
				: $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'notes' => $notes !== null
				? $ins->createNamedParameter($notes)
				: $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'created_by_user_id' => $ins->createNamedParameter($performedBy),
			'created_at' => $ins->createNamedParameter($now),
		]);
		$ins->executeStatement();
		$id = (int)$this->db->lastInsertId('mc_line_manager_assignments');
		$this->audit->log('line_manager_assignment', $id, 'create', $performedBy, [
			'driver_user_id' => $driver,
			'line_manager_user_id' => $lm,
		]);
		return $this->getAssignment($id);
	}

	public function closeAssignment(int $id, string $performedBy): array
	{
		$this->access->requireFleetAdminOrManager($performedBy);
		$row = $this->getAssignment($id);
		$today = gmdate('Y-m-d');
		$upd = $this->db->getQueryBuilder();
		$upd->update('mc_line_manager_assignments')
			->set('valid_until', $upd->createNamedParameter($today))
			->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$upd->executeStatement();
		$this->audit->log('line_manager_assignment', $id, 'close', $performedBy, []);
		return $this->getAssignment($id);
	}

	/** @return list<array<string,mixed>> */
	public function listAssignments(?string $driverUserId = null): array
	{
		$this->access->requireFleetAdminOrManager($this->access->currentUserId());
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_line_manager_assignments')->orderBy('valid_from', 'DESC');
		if ($driverUserId !== null && $driverUserId !== '') {
			$qb->andWhere($qb->expr()->eq('driver_user_id', $qb->createNamedParameter($driverUserId)));
		}
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = $this->hydrateAssignment($r);
		}
		$res->closeCursor();
		return $out;
	}

	/**
	 * Distinct driver user ids with an active line-manager assignment to this user (§2.2 read scope).
	 *
	 * @return list<string>
	 */
	public function listSupervisedDriverUserIds(string $lineManagerUserId): array
	{
		if ($lineManagerUserId === '') {
			return [];
		}
		$today = gmdate('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->select('driver_user_id')
			->from('mc_line_manager_assignments')
			->where($qb->expr()->eq('line_manager_user_id', $qb->createNamedParameter($lineManagerUserId)))
			->andWhere($qb->expr()->lte('valid_from', $qb->createNamedParameter($today)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('valid_until'),
				$qb->expr()->gte('valid_until', $qb->createNamedParameter($today)),
			))
			->groupBy('driver_user_id')
			->orderBy('driver_user_id', 'ASC');
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$uid = (string)($r['driver_user_id'] ?? '');
			if ($uid !== '' && !in_array($uid, $out, true)) {
				$out[] = $uid;
			}
		}
		$res->closeCursor();
		return $out;
	}

	/**
	 * Vehicle ids that appear on any booking for the given drivers (cost / report read scope for line managers).
	 *
	 * @param list<string> $driverUserIds
	 * @return list<int>
	 */
	public function listVehicleIdsFromBookingsForDrivers(array $driverUserIds): array
	{
		$driverUserIds = array_values(array_filter($driverUserIds, static fn ($id) => is_string($id) && $id !== ''));
		if ($driverUserIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('vehicle_id')
			->from('mc_bookings')
			->where($qb->expr()->in('driver_user_id', $qb->createNamedParameter($driverUserIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->groupBy('vehicle_id');
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = (int)$r['vehicle_id'];
		}
		$res->closeCursor();
		return $out;
	}

	/** @return array<string,mixed> */
	public function getAssignment(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_line_manager_assignments')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$r = $qb->executeQuery()->fetch();
		if (!$r) {
			throw new NotFoundException('ASSIGNMENT_NOT_FOUND');
		}
		return $this->hydrateAssignment($r);
	}

	private function assertUserExists(string $uid): void
	{
		if ($this->userManager->get($uid) === null) {
			throw new ValidationException('INVALID_USER_ID');
		}
	}

	private function assertNoOverlappingAssignment(string $driver, string $from, ?string $until, ?int $excludeId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'valid_from', 'valid_until')->from('mc_line_manager_assignments')
			->where($qb->expr()->eq('driver_user_id', $qb->createNamedParameter($driver)));
		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)));
		}
		$res = $qb->executeQuery();
		while (($r = $res->fetch()) !== false) {
			if ($this->dateRangesOverlap($from, $until, (string)$r['valid_from'], $r['valid_until'] !== null ? (string)$r['valid_until'] : null)) {
				$res->closeCursor();
				throw new ValidationException('LINE_MANAGER_OVERLAP');
			}
		}
		$res->closeCursor();
	}

	private function dateRangesOverlap(string $aFrom, ?string $aUntil, string $bFrom, ?string $bUntil): bool
	{
		$aEnd = $aUntil ?? '9999-12-31';
		$bEnd = $bUntil ?? '9999-12-31';
		return $aFrom <= $bEnd && $bFrom <= $aEnd;
	}

	/**
	 * §6.16 invariant #3 — "No circular supervision at the same time:
	 * if (driver=A, lm=B) is active, then (driver=B, lm=A) must not be active."
	 *
	 * When we're about to insert `(driver, lineManager)` for the window
	 * `[from, until]`, check whether there is any *inverted* assignment
	 * `(driver=lineManager, lm=driver)` whose validity window overlaps the
	 * requested one. If so, reject with **`LINE_MANAGER_CIRCULAR`** (422).
	 *
	 * Closed (`valid_until < from`) assignments are fine — the chain has
	 * already been broken. This intentionally mirrors the same overlap
	 * semantics used by {@see assertNoOverlappingAssignment}.
	 */
	private function assertNoCircularSupervision(string $driver, string $lineManager, string $from, ?string $until): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'valid_from', 'valid_until')->from('mc_line_manager_assignments')
			->where($qb->expr()->eq('driver_user_id', $qb->createNamedParameter($lineManager)))
			->andWhere($qb->expr()->eq('line_manager_user_id', $qb->createNamedParameter($driver)));
		$res = $qb->executeQuery();
		while (($r = $res->fetch()) !== false) {
			if ($this->dateRangesOverlap(
				$from,
				$until,
				(string)$r['valid_from'],
				$r['valid_until'] !== null ? (string)$r['valid_until'] : null,
			)) {
				$res->closeCursor();
				throw new ValidationException('LINE_MANAGER_CIRCULAR');
			}
		}
		$res->closeCursor();
	}

	/** @param array<string,mixed> $r */
	private function hydrateAssignment(array $r): array
	{
		return [
			'id' => (int)$r['id'],
			'driverUserId' => (string)$r['driver_user_id'],
			'lineManagerUserId' => (string)$r['line_manager_user_id'],
			'validFrom' => (string)$r['valid_from'],
			'validUntil' => $r['valid_until'] !== null ? (string)$r['valid_until'] : null,
			'notes' => $r['notes'] !== null ? (string)$r['notes'] : null,
			'createdByUserId' => (string)$r['created_by_user_id'],
			'createdAt' => (string)$r['created_at'],
		];
	}
}
