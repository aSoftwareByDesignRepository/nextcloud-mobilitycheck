<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;

/**
 * Appendix A — vehicle assignment modes (pool / group / dedicated) and tax flags.
 */
class VehicleAssignmentService
{
	public const MODE_POOL = 'pool';
	public const MODE_GROUP = 'group';
	public const MODE_DEDICATED = 'dedicated';

	public const TAX_BUSINESS_ONLY = 'business_only';
	public const TAX_ONE_PERCENT = 'one_percent_rule';
	public const TAX_LOGBOOK = 'logbook_method';

	public const MODES = [self::MODE_POOL, self::MODE_GROUP, self::MODE_DEDICATED];
	public const TAX_MODES = [self::TAX_BUSINESS_ONLY, self::TAX_ONE_PERCENT, self::TAX_LOGBOOK];

	public function __construct(
		private IDBConnection $db,
		private AuditLogService $audit,
		private AccessControlService $access,
		private IGroupManager $groupManager,
		private VehicleService $vehicles,
	) {
	}

	public function getActiveAssignment(int $vehicleId): ?array
	{
		return $this->getAssignmentCoveringDate($vehicleId, gmdate('Y-m-d'));
	}

	/**
	 * Assignment row valid on a calendar day (UTC date), newest `valid_from` wins.
	 *
	 * @throws ValidationException when $dateYmd is not YYYY-MM-DD
	 */
	public function getAssignmentCoveringDate(int $vehicleId, string $dateYmd): ?array
	{
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
			throw new ValidationException('DATE_INVALID', 'date');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_vehicle_assignments')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('valid_from', $qb->createNamedParameter($dateYmd)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('valid_until'),
				$qb->expr()->gte('valid_until', $qb->createNamedParameter($dateYmd)),
			))
			->orderBy('valid_from', 'DESC')
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		return $row ? $this->hydrate($row) : null;
	}

	/** @return list<array<string,mixed>> */
	public function listHistory(int $vehicleId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_vehicle_assignments')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->orderBy('valid_from', 'DESC');
		$out = [];
		$res = $qb->executeQuery();
		while (($r = $res->fetch()) !== false) {
			$out[] = $this->hydrate($r);
		}
		$res->closeCursor();
		return $out;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function create(int $vehicleId, array $payload, string $by): array
	{
		$this->access->requireFleetAdminOrManager($by);
		$this->vehicles->get($vehicleId);

		$mode = (string)($payload['assignmentMode'] ?? $payload['assignment_mode'] ?? '');
		if (!in_array($mode, self::MODES, true)) {
			throw new ValidationException('ASSIGNMENT_MODE_INVALID', 'assignment_mode');
		}
		$tax = (string)($payload['taxTreatment'] ?? $payload['tax_treatment'] ?? self::TAX_BUSINESS_ONLY);
		if (!in_array($tax, self::TAX_MODES, true)) {
			throw new ValidationException('TAX_TREATMENT_INVALID', 'tax_treatment');
		}
		$validFrom = trim((string)($payload['validFrom'] ?? $payload['valid_from'] ?? gmdate('Y-m-d')));
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom)) {
			throw new ValidationException('DATE_INVALID', 'valid_from');
		}
		$assignedUser = $this->nullStr($payload['assignedUserId'] ?? $payload['assigned_user_id'] ?? null, 64);
		$assignedGroup = $this->nullStr($payload['assignedGroupId'] ?? $payload['assigned_group_id'] ?? null, 64);
		$listPrice = $payload['monthlyGrossListPriceMinor'] ?? $payload['monthly_gross_list_price_minor'] ?? null;
		$listPriceMinor = $listPrice !== null && $listPrice !== '' ? (int)$listPrice : null;
		if ($tax === self::TAX_ONE_PERCENT && ($listPriceMinor === null || $listPriceMinor <= 0)) {
			throw new ValidationException('LIST_PRICE_REQUIRED_FOR_ONE_PERCENT', 'monthly_gross_list_price_minor');
		}
		if ($tax !== self::TAX_ONE_PERCENT) {
			$listPriceMinor = null;
		}
		if ($mode === self::MODE_DEDICATED && ($assignedUser === null || $assignedUser === '')) {
			throw new ValidationException('DEDICATED_USER_REQUIRED', 'assigned_user_id');
		}
		if ($mode === self::MODE_DEDICATED && $assignedUser !== null && $assignedUser !== '' && !$this->access->isDriver($assignedUser)) {
			throw new ValidationException('DEDICATED_USER_NOT_DRIVER', 'assigned_user_id');
		}
		if ($mode !== self::MODE_DEDICATED) {
			$assignedUser = null;
		}
		if ($mode !== self::MODE_GROUP) {
			$assignedGroup = null;
		} elseif ($assignedGroup === null || $assignedGroup === '') {
			throw new ValidationException('GROUP_ID_REQUIRED', 'assigned_group_id');
		} elseif (!$this->groupManager->groupExists($assignedGroup)) {
			throw new ValidationException('GROUP_NOT_FOUND', 'assigned_group_id');
		}

		$this->assertNewAssignmentDoesNotConflictWithOpenPeriod($vehicleId, $validFrom);

		$notes = $this->nullStr($payload['notes'] ?? null, 4000);
		$now = gmdate('Y-m-d H:i:s');

		$this->db->beginTransaction();
		try {
			// Close any open-ended assignment on this vehicle starting before new valid_from
			$this->closeOpenAssignments($vehicleId, $validFrom, $by);
			$ins = $this->db->getQueryBuilder();
			$ins->insert('mc_vehicle_assignments')->values([
				'vehicle_id' => $ins->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT),
				'assignment_mode' => $ins->createNamedParameter($mode),
				'assigned_user_id' => $ins->createNamedParameter($assignedUser),
				'assigned_group_id' => $ins->createNamedParameter($assignedGroup),
				'valid_from' => $ins->createNamedParameter($validFrom),
				'valid_until' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'tax_treatment' => $ins->createNamedParameter($tax),
				'monthly_gross_list_price_minor' => $listPriceMinor !== null
					? $ins->createNamedParameter($listPriceMinor, IQueryBuilder::PARAM_INT)
					: $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'notes' => $ins->createNamedParameter($notes),
				'created_by_user_id' => $ins->createNamedParameter($by),
				'created_at' => $ins->createNamedParameter($now),
				'updated_at' => $ins->createNamedParameter($now),
			]);
			$ins->executeStatement();
			$id = (int)$this->db->lastInsertId('mc_vehicle_assignments');
			$this->audit->log('vehicle_assignment', $id, 'create', $by, [
				'vehicle_id' => $vehicleId,
				'mode' => $mode,
				'tax' => $tax,
			]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $this->get($id);
	}

	public function close(int $id, string $closeDate, string $by): array
	{
		$this->access->requireFleetAdminOrManager($by);
		$row = $this->get($id);
		if ($row['valid_until'] !== null && $row['valid_until'] !== '') {
			throw new ValidationException('ASSIGNMENT_ALREADY_CLOSED', 'valid_until');
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $closeDate)) {
			throw new ValidationException('DATE_INVALID', 'valid_until');
		}
		$vf = (string)($row['valid_from'] ?? '');
		if ($vf !== '' && $closeDate < $vf) {
			throw new ValidationException('VALID_UNTIL_BEFORE_ASSIGNMENT_START', 'valid_until');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_vehicle_assignments')
			->set('valid_until', $qb->createNamedParameter($closeDate))
			->set('updated_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('vehicle_assignment', $id, 'close', $by, ['valid_until' => $closeDate]);
		return $this->get($id);
	}

	public function get(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_vehicle_assignments')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$r = $qb->executeQuery()->fetch();
		if (!$r) {
			throw new NotFoundException('VEHICLE_ASSIGNMENT_NOT_FOUND');
		}
		return $this->hydrate($r);
	}

	public function userMaySeeVehicle(string $userId, int $vehicleId): bool
	{
		if ($this->access->isFleetAdminOrManager($userId) || $this->access->isAuditor($userId)) {
			return true;
		}
		$a = $this->getActiveAssignment($vehicleId);
		if ($a === null) {
			return true;
		}
		return $this->userMatchesAssignment($userId, $a);
	}

	public function assertUserMayBookVehicle(string $userId, int $vehicleId): void
	{
		$a = $this->getActiveAssignment($vehicleId);
		if ($a === null) {
			return;
		}
		if (!$this->userMatchesAssignment($userId, $a)) {
			throw new ForbiddenException('VEHICLE_NOT_BOOKABLE_FOR_USER');
		}
		if (($a['assignment_mode'] ?? '') === self::MODE_DEDICATED) {
			throw new ForbiddenException('DEDICATED_USE_BOOKING_DISABLED');
		}
	}

	public function assertDedicatedDriver(string $userId, int $vehicleId): void
	{
		$a = $this->getActiveAssignment($vehicleId);
		if ($a === null || ($a['assignment_mode'] ?? '') !== self::MODE_DEDICATED) {
			throw new ValidationException('VEHICLE_NOT_DEDICATED');
		}
		if (($a['assigned_user_id'] ?? '') !== $userId && !$this->access->isFleetAdminOrManager($userId)) {
			throw new ForbiddenException('NOT_DEDICATED_DRIVER');
		}
	}

	/** @param array<string,mixed> $assignment */
	public function userMatchesAssignment(string $userId, array $assignment): bool
	{
		$mode = (string)($assignment['assignment_mode'] ?? '');
		if ($mode === self::MODE_POOL) {
			return true;
		}
		if ($mode === self::MODE_DEDICATED) {
			return ($assignment['assigned_user_id'] ?? '') === $userId;
		}
		if ($mode === self::MODE_GROUP) {
			$gid = (string)($assignment['assigned_group_id'] ?? '');
			if ($gid !== '' && $this->groupManager->isInGroup($userId, $gid)) {
				return true;
			}
			return $this->userInVehicleMemberTable($userId, (int)$assignment['vehicle_id']);
		}
		return false;
	}

	private function userInVehicleMemberTable(string $userId, int $vehicleId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('mc_vehicle_group_members')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	/**
	 * Prevents creating a new period that would close an open row with a
	 * valid_until date before that row's valid_from (ISO Y-m-d strings).
	 *
	 * Example blocked case: open assignment from 2025-06-01 with no end date,
	 * new period starting 2024-01-01 would set valid_until = 2023-12-31.
	 */
	private function assertNewAssignmentDoesNotConflictWithOpenPeriod(int $vehicleId, string $validFrom): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('mc_vehicle_assignments')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('valid_until'))
			->andWhere($qb->expr()->gte('valid_from', $qb->createNamedParameter($validFrom)));
		if ((int)$qb->executeQuery()->fetchOne() > 0) {
			throw new ValidationException('ASSIGNMENT_VALID_FROM_CONFLICT', 'valid_from');
		}
	}

	private function closeOpenAssignments(int $vehicleId, string $newValidFrom, string $by): void
	{
		// valid_until = day before new assignment starts
		$ts = strtotime($newValidFrom . ' UTC');
		if ($ts === false) {
			return;
		}
		$prev = gmdate('Y-m-d', $ts - 86400);
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('mc_vehicle_assignments')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('valid_until'));
		$res = $qb->executeQuery();
		while (($row = $res->fetch()) !== false) {
			$id = (int)$row['id'];
			$u = $this->db->getQueryBuilder();
			$u->update('mc_vehicle_assignments')
				->set('valid_until', $u->createNamedParameter($prev))
				->set('updated_at', $u->createNamedParameter(gmdate('Y-m-d H:i:s')))
				->where($u->expr()->eq('id', $u->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$u->executeStatement();
			$this->audit->log('vehicle_assignment', $id, 'supersede_close', $by, ['valid_until' => $prev]);
		}
		$res->closeCursor();
	}

	/** @param array<string,mixed> $row */
	private function hydrate(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'vehicle_id' => (int)$row['vehicle_id'],
			'assignment_mode' => (string)$row['assignment_mode'],
			'assigned_user_id' => $row['assigned_user_id'] !== null ? (string)$row['assigned_user_id'] : null,
			'assigned_group_id' => $row['assigned_group_id'] !== null ? (string)$row['assigned_group_id'] : null,
			'valid_from' => (string)$row['valid_from'],
			'valid_until' => $row['valid_until'] !== null ? (string)$row['valid_until'] : null,
			'tax_treatment' => (string)$row['tax_treatment'],
			'monthly_gross_list_price_minor' => isset($row['monthly_gross_list_price_minor']) && $row['monthly_gross_list_price_minor'] !== null
				? (int)$row['monthly_gross_list_price_minor'] : null,
			'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
			'created_by_user_id' => (string)$row['created_by_user_id'],
			'created_at' => (string)$row['created_at'],
			'updated_at' => (string)$row['updated_at'],
		];
	}

	private function nullStr(mixed $v, int $max): ?string
	{
		if ($v === null) {
			return null;
		}
		$s = trim((string)$v);
		if ($s === '') {
			return null;
		}
		return mb_substr($s, 0, $max);
	}
}
