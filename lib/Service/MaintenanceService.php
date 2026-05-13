<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * §4.9 — Maintenance schedules.
 *
 * Each schedule is per vehicle with optional calendar interval, optional
 * odometer interval, and an `is_blocking` flag. Blocking schedules that
 * become overdue cause new bookings to be rejected (§11.8) by
 * {@see ComplianceService::hasBlockingMaintenance()}.
 *
 * Completion records the actual date / km and auto-rolls the next due
 * date and odometer.
 */
class MaintenanceService
{
	public const TRIGGER_CALENDAR = 'calendar';
	public const TRIGGER_ODOMETER = 'odometer';
	public const TRIGGER_BOTH = 'both';

	public const TRIGGERS = [
		self::TRIGGER_CALENDAR,
		self::TRIGGER_ODOMETER,
		self::TRIGGER_BOTH,
	];

	public function __construct(
		private IDBConnection $db,
		private VehicleService $vehicles,
		private AccessControlService $access,
		private AuditLogService $audit,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function list(array $filters = []): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_maintenance_schedules')->orderBy('next_due_date', 'ASC');
		if (!empty($filters['vehicleId'])) {
			$qb->andWhere($qb->expr()->eq('vehicle_id', $qb->createNamedParameter((int)$filters['vehicleId'], IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filters['activeOnly'])) {
			$qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filters['blockingOnly'])) {
			$qb->andWhere($qb->expr()->eq('is_blocking', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		}
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = $this->hydrate($r);
		}
		$res->closeCursor();
		return $this->decorateWithVehicle($out);
	}

	public function get(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_maintenance_schedules')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if (!$row) {
			throw new NotFoundException('MAINTENANCE_NOT_FOUND');
		}
		$decorated = $this->decorateWithVehicle([$this->hydrate($row)]);
		return $decorated[0];
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return list<array<string,mixed>>
	 */
	private function decorateWithVehicle(array $rows): array
	{
		if ($rows === []) {
			return $rows;
		}
		$ids = array_map(static fn ($r) => (int)($r['vehicle_id'] ?? 0), $rows);
		$summaries = $this->vehicles->summariesByIds($ids);
		foreach ($rows as &$r) {
			$v = $summaries[(int)($r['vehicle_id'] ?? 0)] ?? null;
			$r['vehicle_internal_name'] = $v['internal_name'] ?? null;
			$r['vehicle_licence_plate'] = $v['licence_plate'] ?? null;
			$r['vehicle_status'] = $v['status'] ?? null;
		}
		return $rows;
	}

	/** @param array<string,mixed> $payload */
	public function create(array $payload, string $performedBy): array
	{
		$this->access->requireFleetAdminOrManager($performedBy);
		$data = $this->validate($payload);
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('mc_maintenance_schedules')->values([
			'vehicle_id' => $qb->createNamedParameter($data['vehicle_id'], IQueryBuilder::PARAM_INT),
			'name' => $qb->createNamedParameter($data['name']),
			'trigger_type' => $qb->createNamedParameter($data['trigger_type']),
			'calendar_interval_months' => $data['calendar_interval_months'] !== null
				? $qb->createNamedParameter($data['calendar_interval_months'], IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'odometer_interval_km' => $data['odometer_interval_km'] !== null
				? $qb->createNamedParameter($data['odometer_interval_km'], IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'next_due_date' => $qb->createNamedParameter($data['next_due_date']),
			'next_due_odometer_km' => $data['next_due_odometer_km'] !== null
				? $qb->createNamedParameter($data['next_due_odometer_km'], IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'is_blocking' => $qb->createNamedParameter($data['is_blocking'] ? 1 : 0, IQueryBuilder::PARAM_INT),
			'is_active' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'created_by_user_id' => $qb->createNamedParameter($performedBy),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
		]);
		$qb->executeStatement();
		$id = (int)$this->db->lastInsertId('mc_maintenance_schedules');
		$this->audit->log('maintenance_schedule', $id, 'create', $performedBy, [
			'vehicle_id' => $data['vehicle_id'],
			'name' => $data['name'],
		]);
		return $this->get($id);
	}

	/** @param array<string,mixed> $payload */
	public function update(int $id, array $payload, string $performedBy): array
	{
		$this->access->requireFleetAdminOrManager($performedBy);
		$current = $this->get($id);
		$data = $this->validate(array_merge($current, $payload));
		$isActive = isset($payload['isActive']) ? (bool)$payload['isActive'] : $current['is_active'];
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_maintenance_schedules')
			->set('name', $qb->createNamedParameter($data['name']))
			->set('trigger_type', $qb->createNamedParameter($data['trigger_type']))
			->set('calendar_interval_months', $data['calendar_interval_months'] !== null
				? $qb->createNamedParameter($data['calendar_interval_months'], IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('odometer_interval_km', $data['odometer_interval_km'] !== null
				? $qb->createNamedParameter($data['odometer_interval_km'], IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('next_due_date', $qb->createNamedParameter($data['next_due_date']))
			->set('next_due_odometer_km', $data['next_due_odometer_km'] !== null
				? $qb->createNamedParameter($data['next_due_odometer_km'], IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('is_blocking', $qb->createNamedParameter($data['is_blocking'] ? 1 : 0, IQueryBuilder::PARAM_INT))
			->set('is_active', $qb->createNamedParameter($isActive ? 1 : 0, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('maintenance_schedule', $id, 'update', $performedBy, [
			'name' => [$current['name'], $data['name']],
			'next_due_date' => [$current['next_due_date'], $data['next_due_date']],
		]);
		return $this->get($id);
	}

	/**
	 * §4.9 — record completion: set `last_completed_*` and roll the
	 * next due date/odometer according to the trigger type.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function complete(int $id, array $payload, string $performedBy): array
	{
		$this->access->requireFleetAdminOrManager($performedBy);
		$current = $this->get($id);
		$completedDate = trim((string)($payload['completedDate'] ?? $payload['completed_date'] ?? gmdate('Y-m-d')));
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $completedDate)) {
			throw new ValidationException('DATE_INVALID', 'completed_date');
		}
		$completedOdometer = $payload['completedOdometerKm'] ?? $payload['completed_odometer_km'] ?? null;
		$completedOdometer = ($completedOdometer !== null && $completedOdometer !== '') ? (int)$completedOdometer : null;
		if ($completedOdometer !== null && $completedOdometer < 0) {
			throw new ValidationException('ODOMETER_NEGATIVE', 'completed_odometer_km');
		}
		// Roll next due date.
		$nextDueDate = $current['next_due_date'];
		if ($current['trigger_type'] === self::TRIGGER_CALENDAR || $current['trigger_type'] === self::TRIGGER_BOTH) {
			if ($current['calendar_interval_months'] !== null) {
				$baseDate = new \DateTimeImmutable($completedDate, new \DateTimeZone('UTC'));
				$next = $baseDate->modify('+' . (int)$current['calendar_interval_months'] . ' months');
				$nextDueDate = $next->format('Y-m-d');
			}
		}
		$nextDueOdometer = $current['next_due_odometer_km'];
		if (($current['trigger_type'] === self::TRIGGER_ODOMETER || $current['trigger_type'] === self::TRIGGER_BOTH)
			&& $current['odometer_interval_km'] !== null
			&& $completedOdometer !== null) {
			$nextDueOdometer = $completedOdometer + (int)$current['odometer_interval_km'];
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_maintenance_schedules')
			->set('last_completed_date', $qb->createNamedParameter($completedDate))
			->set('last_completed_odometer_km', $completedOdometer !== null
				? $qb->createNamedParameter($completedOdometer, IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('next_due_date', $qb->createNamedParameter($nextDueDate))
			->set('next_due_odometer_km', $nextDueOdometer !== null
				? $qb->createNamedParameter($nextDueOdometer, IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('maintenance_schedule', $id, 'complete', $performedBy, [
			'completed_date' => $completedDate,
			'completed_odometer_km' => $completedOdometer,
			'next_due_date' => [$current['next_due_date'], $nextDueDate],
		]);
		return $this->get($id);
	}

	/** @return list<array<string,mixed>> */
	public function overdue(?int $vehicleId = null): array
	{
		$today = gmdate('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_maintenance_schedules')
			->where($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->andX(
				$qb->expr()->isNotNull('next_due_date'),
				$qb->expr()->lte('next_due_date', $qb->createNamedParameter($today)),
			))
			->orderBy('next_due_date', 'ASC');
		if ($vehicleId !== null) {
			$qb->andWhere($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
		}
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = $this->hydrate($r);
		}
		$res->closeCursor();
		return $this->decorateWithVehicle($out);
	}

	private function validate(array $p): array
	{
		$vehicleId = (int)($p['vehicleId'] ?? $p['vehicle_id'] ?? 0);
		if ($vehicleId <= 0) {
			throw new ValidationException('VEHICLE_REQUIRED', 'vehicle_id');
		}
		$this->vehicles->get($vehicleId);
		$name = trim((string)($p['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 120) {
			throw new ValidationException('NAME_INVALID', 'name');
		}
		$trigger = (string)($p['triggerType'] ?? $p['trigger_type'] ?? '');
		if (!in_array($trigger, self::TRIGGERS, true)) {
			throw new ValidationException('TRIGGER_INVALID', 'trigger_type');
		}
		$calMonths = $p['calendarIntervalMonths'] ?? $p['calendar_interval_months'] ?? null;
		$odoKm = $p['odometerIntervalKm'] ?? $p['odometer_interval_km'] ?? null;
		$calMonths = ($calMonths !== null && $calMonths !== '') ? (int)$calMonths : null;
		$odoKm = ($odoKm !== null && $odoKm !== '') ? (int)$odoKm : null;
		if ($trigger === self::TRIGGER_CALENDAR && $calMonths === null) {
			throw new ValidationException('CALENDAR_INTERVAL_REQUIRED', 'calendar_interval_months');
		}
		if ($trigger === self::TRIGGER_ODOMETER && $odoKm === null) {
			throw new ValidationException('ODOMETER_INTERVAL_REQUIRED', 'odometer_interval_km');
		}
		if ($trigger === self::TRIGGER_BOTH && ($calMonths === null || $odoKm === null)) {
			throw new ValidationException('BOTH_INTERVALS_REQUIRED');
		}
		if ($calMonths !== null && ($calMonths < 1 || $calMonths > 120)) {
			throw new ValidationException('CALENDAR_INTERVAL_INVALID', 'calendar_interval_months');
		}
		if ($odoKm !== null && ($odoKm < 1 || $odoKm > 500_000)) {
			throw new ValidationException('ODOMETER_INTERVAL_INVALID', 'odometer_interval_km');
		}
		$nextDueDate = $p['nextDueDate'] ?? $p['next_due_date'] ?? null;
		if ($nextDueDate === null || $nextDueDate === '') {
			throw new ValidationException('NEXT_DUE_DATE_REQUIRED', 'next_due_date');
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$nextDueDate)) {
			throw new ValidationException('DATE_INVALID', 'next_due_date');
		}
		$nextDueOdometer = $p['nextDueOdometerKm'] ?? $p['next_due_odometer_km'] ?? null;
		$nextDueOdometer = ($nextDueOdometer !== null && $nextDueOdometer !== '') ? (int)$nextDueOdometer : null;
		if ($nextDueOdometer !== null && $nextDueOdometer < 0) {
			throw new ValidationException('ODOMETER_NEGATIVE', 'next_due_odometer_km');
		}
		$isBlocking = (bool)($p['isBlocking'] ?? $p['is_blocking'] ?? false);
		return [
			'vehicle_id' => $vehicleId,
			'name' => $name,
			'trigger_type' => $trigger,
			'calendar_interval_months' => $calMonths,
			'odometer_interval_km' => $odoKm,
			'next_due_date' => (string)$nextDueDate,
			'next_due_odometer_km' => $nextDueOdometer,
			'is_blocking' => $isBlocking,
		];
	}

	private function hydrate(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'vehicle_id' => (int)$row['vehicle_id'],
			'name' => (string)$row['name'],
			'trigger_type' => (string)$row['trigger_type'],
			'calendar_interval_months' => $row['calendar_interval_months'] !== null ? (int)$row['calendar_interval_months'] : null,
			'odometer_interval_km' => $row['odometer_interval_km'] !== null ? (int)$row['odometer_interval_km'] : null,
			'next_due_date' => $row['next_due_date'] !== null ? substr((string)$row['next_due_date'], 0, 10) : null,
			'next_due_odometer_km' => $row['next_due_odometer_km'] !== null ? (int)$row['next_due_odometer_km'] : null,
			'is_blocking' => (int)$row['is_blocking'] === 1,
			'last_completed_date' => $row['last_completed_date'] !== null ? substr((string)$row['last_completed_date'], 0, 10) : null,
			'last_completed_odometer_km' => $row['last_completed_odometer_km'] !== null ? (int)$row['last_completed_odometer_km'] : null,
			'is_active' => (int)$row['is_active'] === 1,
			'created_by_user_id' => (string)$row['created_by_user_id'],
			'created_at' => (string)$row['created_at'],
			'updated_at' => (string)$row['updated_at'],
		];
	}
}
