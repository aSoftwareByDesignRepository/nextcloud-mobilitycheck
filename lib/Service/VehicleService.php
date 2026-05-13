<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/**
 * §4.2 vehicle lifecycle (create, update, decommission). All writes
 * audit through {@see AuditLogService}, all inserts go via
 * {@see IQueryBuilder} (no raw SQL).
 */
class VehicleService
{
	public const STATUS_AVAILABLE = 'available';
	public const STATUS_BOOKED = 'booked';
	public const STATUS_IN_USE = 'in_use';
	public const STATUS_IN_MAINTENANCE = 'in_maintenance';
	public const STATUS_DECOMMISSIONED = 'decommissioned';

	public const FUEL_TYPES = ['petrol', 'diesel', 'electric', 'hybrid_petrol', 'hybrid_diesel', 'lpg'];
	public const TRANSMISSIONS = ['manual', 'automatic'];
	public const STATUSES = [
		self::STATUS_AVAILABLE,
		self::STATUS_BOOKED,
		self::STATUS_IN_USE,
		self::STATUS_IN_MAINTENANCE,
		self::STATUS_DECOMMISSIONED,
	];
	public const FUEL_MINIMUM_VALUES = ['empty', 'quarter', 'half', 'three_quarter', 'full'];

	public function __construct(
		private IDBConnection $db,
		private AuditLogService $audit,
		private ?ISecureRandom $random = null,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function list(array $filters = []): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_vehicles')->orderBy('internal_name', 'ASC');
		if (!empty($filters['activeOnly'])) {
			$qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filters['status'])) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter((string)$filters['status'])));
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
		$qb->select('*')->from('mc_vehicles')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if (!$row) {
			throw new NotFoundException('VEHICLE_NOT_FOUND');
		}
		return $this->hydrate($row);
	}

	/**
	 * Batch lookup for "vehicle name / plate / status" used by every list
	 * endpoint that joins to mc_vehicles for display. Returns a map keyed
	 * by vehicle id; missing ids are simply absent (the caller decides
	 * whether to show '—' or hide the row).
	 *
	 * @param list<int> $ids
	 * @return array<int, array{id:int,internal_name:string,licence_plate:string,status:string,base_location:?string,odometer_km:int}>
	 */
	public function summariesByIds(array $ids): array
	{
		$ids = array_values(array_unique(array_map('intval', array_filter($ids, static fn ($v) => (int)$v > 0))));
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'internal_name', 'licence_plate', 'status', 'base_location', 'odometer_km')
			->from('mc_vehicles')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		$out = [];
		$res = $qb->executeQuery();
		while (($r = $res->fetch()) !== false) {
			$out[(int)$r['id']] = [
				'id' => (int)$r['id'],
				'internal_name' => (string)$r['internal_name'],
				'licence_plate' => (string)$r['licence_plate'],
				'status' => (string)$r['status'],
				'base_location' => $r['base_location'] !== null ? (string)$r['base_location'] : null,
				'odometer_km' => (int)$r['odometer_km'],
			];
		}
		$res->closeCursor();
		return $out;
	}

	/**
	 * §4.5 / §13.28 — Pickup-location hint. Returns the latest `return_location_note`
	 * recorded by the previous driver for this vehicle, plus a small head of
	 * preceding ones so the next driver gets a useful trail even if the latest
	 * note is missing. Pool / group vehicles must surface this card before
	 * check-out; a missing return note is a soft signal — the card still
	 * renders with an explicit "no recent return note" line.
	 *
	 * @return array{hasPriorCheckin:bool,lastReturn:?array{note:?string,recordedAt:string,driverHash:string},history:list<array{note:string,recordedAt:string,driverHash:string}>}
	 */
	public function lastReturnInfo(int $vehicleId, int $limit = 5): array
	{
		$limit = max(1, min(20, $limit));
		$priorQb = $this->db->getQueryBuilder();
		$priorQb->select('cl.id')
			->from('mc_checkout_logs', 'cl')
			->innerJoin('cl', 'mc_bookings', 'b', $priorQb->expr()->eq('b.id', 'cl.booking_id'))
			->where($priorQb->expr()->eq('b.vehicle_id', $priorQb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($priorQb->expr()->eq('cl.event_type', $priorQb->createNamedParameter('checkin')))
			->setMaxResults(1);
		$hasPriorCheckin = $priorQb->executeQuery()->fetchOne() !== false;
		$qb = $this->db->getQueryBuilder();
		$qb->select('cl.return_location_note', 'cl.recorded_at', 'cl.recorded_by_user_id', 'b.driver_user_id')
			->from('mc_checkout_logs', 'cl')
			->leftJoin('cl', 'mc_bookings', 'b', 'b.id = cl.booking_id')
			->where($qb->expr()->eq('b.vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('cl.event_type', $qb->createNamedParameter('checkin')))
			->orderBy('cl.recorded_at', 'DESC')
			->setMaxResults($limit);
		$res = $qb->executeQuery();
		$rows = [];
		while (($r = $res->fetch()) !== false) {
			$note = $r['return_location_note'] !== null ? (string)$r['return_location_note'] : null;
			$rows[] = [
				'note' => $note,
				'recordedAt' => (string)($r['recorded_at'] ?? ''),
				// hash the user id so the hint card never leaks identities (§8 privacy)
				'driverHash' => substr(hash('sha256', (string)($r['driver_user_id'] ?? '')), 0, 12),
			];
		}
		$res->closeCursor();
		$last = null;
		foreach ($rows as $row) {
			if ($row['note'] !== null && trim($row['note']) !== '') {
				$last = $row;
				break;
			}
		}
		$history = [];
		foreach ($rows as $row) {
			if ($row['note'] !== null && trim($row['note']) !== '') {
				$history[] = ['note' => (string)$row['note'], 'recordedAt' => $row['recordedAt'], 'driverHash' => $row['driverHash']];
			}
		}
		return [
			'hasPriorCheckin' => $hasPriorCheckin,
			'lastReturn' => $last,
			'history' => $history,
		];
	}

	/** @param array<string,mixed> $payload */
	public function create(array $payload, string $performedBy): array
	{
		$data = $this->validate($payload, isCreate: true);
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('mc_vehicles')->values([
			'internal_name' => $qb->createNamedParameter($data['internal_name']),
			'make' => $qb->createNamedParameter($data['make']),
			'model' => $qb->createNamedParameter($data['model']),
			'year' => $qb->createNamedParameter($data['year'], IQueryBuilder::PARAM_INT),
			'licence_plate' => $qb->createNamedParameter($data['licence_plate']),
			'colour' => $qb->createNamedParameter($data['colour']),
			'fuel_type' => $qb->createNamedParameter($data['fuel_type']),
			'transmission' => $qb->createNamedParameter($data['transmission']),
			'seating_capacity' => $qb->createNamedParameter($data['seating_capacity'], IQueryBuilder::PARAM_INT),
			'required_licence_class' => $qb->createNamedParameter($data['required_licence_class']),
			'base_location' => $qb->createNamedParameter($data['base_location']),
			'status' => $qb->createNamedParameter($data['status']),
			'odometer_km' => $qb->createNamedParameter($data['odometer_km'], IQueryBuilder::PARAM_INT),
			'insurance_policy_number' => $qb->createNamedParameter($data['insurance_policy_number']),
			'insurance_expiry_date' => $qb->createNamedParameter($data['insurance_expiry_date']),
			'road_tax_expiry_date' => $qb->createNamedParameter($data['road_tax_expiry_date']),
			'next_service_date' => $qb->createNamedParameter($data['next_service_date']),
			'next_service_odometer_km' => $data['next_service_odometer_km'] !== null
				? $qb->createNamedParameter($data['next_service_odometer_km'], IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'photo_file_id' => $qb->createNamedParameter($data['photo_file_id']),
			'notes' => $qb->createNamedParameter($data['notes']),
			'lease_start_date' => $data['lease_start_date'] !== null
				? $qb->createNamedParameter($data['lease_start_date'])
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'lease_end_date' => $data['lease_end_date'] !== null
				? $qb->createNamedParameter($data['lease_end_date'])
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'lease_included_km' => $data['lease_included_km'] !== null
				? $qb->createNamedParameter($data['lease_included_km'], IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'lease_reference' => $qb->createNamedParameter($data['lease_reference']),
			'do_not_auto_allocate' => $qb->createNamedParameter((int)$data['do_not_auto_allocate'], IQueryBuilder::PARAM_INT),
			'is_active' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'created_by' => $qb->createNamedParameter($performedBy),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
		]);
		try {
			$qb->executeStatement();
		} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
			throw new ValidationException('LICENCE_PLATE_TAKEN', 'licence_plate');
		}
		$id = (int)$this->db->lastInsertId('mc_vehicles');
		$this->audit->log('vehicle', $id, 'create', $performedBy, ['internal_name' => $data['internal_name']]);
		$this->ensureAppendixPoolAssignment($id, $performedBy);
		return $this->get($id);
	}

	public function update(int $id, array $payload, string $performedBy): array
	{
		$existing = $this->get($id);
		$data = $this->validate(array_merge($existing, $payload), isCreate: false);
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_vehicles')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->set('internal_name', $qb->createNamedParameter($data['internal_name']))
			->set('make', $qb->createNamedParameter($data['make']))
			->set('model', $qb->createNamedParameter($data['model']))
			->set('year', $qb->createNamedParameter($data['year'], IQueryBuilder::PARAM_INT))
			->set('licence_plate', $qb->createNamedParameter($data['licence_plate']))
			->set('colour', $qb->createNamedParameter($data['colour']))
			->set('fuel_type', $qb->createNamedParameter($data['fuel_type']))
			->set('transmission', $qb->createNamedParameter($data['transmission']))
			->set('seating_capacity', $qb->createNamedParameter($data['seating_capacity'], IQueryBuilder::PARAM_INT))
			->set('required_licence_class', $qb->createNamedParameter($data['required_licence_class']))
			->set('base_location', $qb->createNamedParameter($data['base_location']))
			->set('odometer_km', $qb->createNamedParameter($data['odometer_km'], IQueryBuilder::PARAM_INT))
			->set('insurance_policy_number', $qb->createNamedParameter($data['insurance_policy_number']))
			->set('insurance_expiry_date', $qb->createNamedParameter($data['insurance_expiry_date']))
			->set('road_tax_expiry_date', $qb->createNamedParameter($data['road_tax_expiry_date']))
			->set('next_service_date', $qb->createNamedParameter($data['next_service_date']))
			->set('next_service_odometer_km', $data['next_service_odometer_km'] !== null
				? $qb->createNamedParameter($data['next_service_odometer_km'], IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('photo_file_id', $qb->createNamedParameter($data['photo_file_id']))
			->set('notes', $qb->createNamedParameter($data['notes']))
			->set('lease_start_date', $data['lease_start_date'] !== null
				? $qb->createNamedParameter($data['lease_start_date'])
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('lease_end_date', $data['lease_end_date'] !== null
				? $qb->createNamedParameter($data['lease_end_date'])
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('lease_included_km', $data['lease_included_km'] !== null
				? $qb->createNamedParameter($data['lease_included_km'], IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('lease_reference', $qb->createNamedParameter($data['lease_reference']))
			->set('do_not_auto_allocate', $qb->createNamedParameter((int)$data['do_not_auto_allocate'], IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now));
		try {
			$qb->executeStatement();
		} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
			throw new ValidationException('LICENCE_PLATE_TAKEN', 'licence_plate');
		}
		$diff = [];
		foreach ([
			'internal_name', 'licence_plate', 'status', 'insurance_expiry_date', 'road_tax_expiry_date', 'required_licence_class',
			'lease_start_date', 'lease_end_date', 'lease_included_km', 'lease_reference', 'do_not_auto_allocate',
		] as $field) {
			$ov = $existing[$field] ?? null;
			$nv = $data[$field] ?? null;
			if ($field === 'do_not_auto_allocate') {
				$ov = !empty($ov) ? 1 : 0;
				$nv = !empty($nv) ? 1 : 0;
			}
			if ($ov !== $nv) {
				$diff[$field] = [$existing[$field] ?? null, $data[$field] ?? null];
			}
		}
		if ($diff !== []) {
			$this->audit->log('vehicle', $id, 'update', $performedBy, $diff);
		}
		return $this->get($id);
	}

	/**
	 * §4.2 decommission. Future bookings (status approved/pending)
	 * are cancelled in the same transaction; an audit row records
	 * each cancellation and a notification fires from the caller.
	 *
	 * @param string|null $reason Raw reason; empty or whitespace-only is rejected.
	 *
	 * @return array{vehicle:array<string,mixed>,cancelledBookingIds:list<int>}
	 */
	public function decommission(int $id, string $performedBy, ?string $reason): array
	{
		$r = $reason === null ? '' : trim($reason);
		if ($r === '') {
			throw new ValidationException('DECOMMISSION_REASON_REQUIRED', 'reason');
		}
		$maxChars = 8000;
		$len = function_exists('mb_strlen') ? mb_strlen($r, 'UTF-8') : strlen($r);
		if ($len > $maxChars) {
			throw new ValidationException('DECOMMISSION_REASON_TOO_LONG', 'reason', ['max' => $maxChars]);
		}
		$vehicle = $this->get($id);
		if ($vehicle['status'] === self::STATUS_DECOMMISSIONED) {
			throw new ValidationException('VEHICLE_ALREADY_DECOMMISSIONED');
		}
		$cancelled = [];
		$this->db->beginTransaction();
		try {
			$now = gmdate('Y-m-d H:i:s');
			$update = $this->db->getQueryBuilder();
			$update->update('mc_vehicles')
				->set('status', $update->createNamedParameter(self::STATUS_DECOMMISSIONED))
				->set('is_active', $update->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->set('updated_at', $update->createNamedParameter($now))
				->where($update->expr()->eq('id', $update->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$update->executeStatement();

			$find = $this->db->getQueryBuilder();
			$find->select('id')->from('mc_bookings')
				->where($find->expr()->eq('vehicle_id', $find->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($find->expr()->in('status', $find->createNamedParameter([
					BookingService::STATUS_PENDING_FLEET,
					BookingService::STATUS_PENDING_LINE_MANAGER,
					BookingService::STATUS_APPROVED,
				], IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($find->expr()->gte('end_datetime', $find->createNamedParameter($now)));
			$res = $find->executeQuery();
			while (($row = $res->fetch()) !== false) {
				$cancelled[] = (int)$row['id'];
			}
			$res->closeCursor();
			foreach ($cancelled as $bid) {
				$cancel = $this->db->getQueryBuilder();
				$cancel->update('mc_bookings')
					->set('status', $cancel->createNamedParameter('cancelled'))
					->set('cancellation_reason', $cancel->createNamedParameter($r))
					->set('updated_at', $cancel->createNamedParameter($now))
					->where($cancel->expr()->eq('id', $cancel->createNamedParameter($bid, IQueryBuilder::PARAM_INT)));
				$cancel->executeStatement();
				$this->audit->log('booking', $bid, 'cancel_by_decommission', $performedBy, [], $r);
			}
			$this->audit->log('vehicle', $id, 'decommission', $performedBy, [
				'status' => [$vehicle['status'], self::STATUS_DECOMMISSIONED],
			], $r);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return [
			'vehicle' => $this->get($id),
			'cancelledBookingIds' => $cancelled,
		];
	}

	public function setStatus(int $id, string $status, string $performedBy): void
	{
		if (!in_array($status, self::STATUSES, true)) {
			throw new ValidationException('STATUS_INVALID');
		}
		$now = gmdate('Y-m-d H:i:s');
		$prev = $this->get($id);
		if ($prev['status'] === $status) {
			return;
		}
		// §4.2 — decommission is a terminal state. Auto-driven lifecycle
		// transitions (damage assess, check-out/in, etc.) must never
		// resurrect a retired vehicle. Use {@see decommission} for the
		// one allowed entry into this state; reversing it is forbidden
		// because the audit chain assumes a strictly forward lifecycle.
		if ($prev['status'] === self::STATUS_DECOMMISSIONED) {
			throw new ValidationException('VEHICLE_DECOMMISSIONED_TERMINAL');
		}
		// §4.2 — explicit decommission has its own audited entry point;
		// `setStatus` is for the automatic in_use / in_maintenance /
		// available cycle only. Refuse to write `decommissioned` here so
		// callers cannot bypass the decommission cancellation cascade.
		if ($status === self::STATUS_DECOMMISSIONED) {
			throw new ValidationException('USE_DECOMMISSION_ENDPOINT');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_vehicles')
			->set('status', $qb->createNamedParameter($status))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('vehicle', $id, 'status_change', $performedBy, [
			'status' => [$prev['status'], $status],
		]);
	}

	public function setOdometer(int $id, int $km, string $performedBy): void
	{
		$prev = $this->get($id);
		if ($km < $prev['odometer_km']) {
			// §4.6 — check-in odometer < checkout is rejected at the booking
			// service. Here we forbid odometer regression for the vehicle row
			// itself so manual updates can't undermine the fahrtenbuch chain.
			throw new ValidationException('ODOMETER_REGRESSION', 'odometer_km', [
				'current' => $prev['odometer_km'],
				'attempted' => $km,
			]);
		}
		if ($km === $prev['odometer_km']) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_vehicles')
			->set('odometer_km', $qb->createNamedParameter($km, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('vehicle', $id, 'odometer_update', $performedBy, [
			'odometer_km' => [$prev['odometer_km'], $km],
		]);
	}

	/**
	 * §7.2 vehicles availability — returns booked windows for a vehicle.
	 *
	 * @return list<array{start:string,end:string,bookingId:int,status:string,driverUserId:string}>
	 */
	public function availability(int $id, string $from, string $to): array
	{
		$this->get($id);
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'start_datetime', 'end_datetime', 'status', 'driver_user_id')
			->from('mc_bookings')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter([
				BookingService::STATUS_PENDING_FLEET,
				BookingService::STATUS_PENDING_LINE_MANAGER,
				BookingService::STATUS_APPROVED,
				BookingService::STATUS_ACTIVE,
			], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lte('start_datetime', $qb->createNamedParameter($to)))
			->andWhere($qb->expr()->gte('end_datetime', $qb->createNamedParameter($from)))
			->orderBy('start_datetime', 'ASC');
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = [
				'start' => (string)$r['start_datetime'],
				'end' => (string)$r['end_datetime'],
				'bookingId' => (int)$r['id'],
				'status' => (string)$r['status'],
				'driverUserId' => (string)$r['driver_user_id'],
			];
		}
		$res->closeCursor();
		return $out;
	}

	private function validate(array $p, bool $isCreate): array
	{
		$name = trim((string)($p['internal_name'] ?? ''));
		$make = trim((string)($p['make'] ?? ''));
		$model = trim((string)($p['model'] ?? ''));
		$plate = trim(strtoupper((string)($p['licence_plate'] ?? '')));
		if ($name === '' || strlen($name) > 120) throw new ValidationException('NAME_INVALID', 'internal_name');
		if ($make === '' || strlen($make) > 80) throw new ValidationException('MAKE_INVALID', 'make');
		if ($model === '' || strlen($model) > 80) throw new ValidationException('MODEL_INVALID', 'model');
		if (!preg_match('/^[A-Z0-9 -]{2,20}$/', $plate)) throw new ValidationException('LICENCE_PLATE_INVALID', 'licence_plate');
		$fuel = (string)($p['fuel_type'] ?? 'petrol');
		if (!in_array($fuel, self::FUEL_TYPES, true)) throw new ValidationException('FUEL_TYPE_INVALID', 'fuel_type');
		$transmission = (string)($p['transmission'] ?? 'manual');
		if (!in_array($transmission, self::TRANSMISSIONS, true)) throw new ValidationException('TRANSMISSION_INVALID', 'transmission');
		$status = (string)($p['status'] ?? self::STATUS_AVAILABLE);
		if (!in_array($status, self::STATUSES, true)) throw new ValidationException('STATUS_INVALID', 'status');
		$seats = (int)($p['seating_capacity'] ?? 5);
		if ($seats < 1 || $seats > 50) throw new ValidationException('SEATS_INVALID', 'seating_capacity');
		$class = trim((string)($p['required_licence_class'] ?? 'B'));
		if ($class === '' || strlen($class) > 20) throw new ValidationException('LICENCE_CLASS_INVALID', 'required_licence_class');
		$year = (int)($p['year'] ?? (int)date('Y'));
		if ($year < 1900 || $year > (int)date('Y') + 2) throw new ValidationException('YEAR_INVALID', 'year');
		$odo = (int)($p['odometer_km'] ?? 0);
		if ($odo < 0) throw new ValidationException('ODOMETER_NEGATIVE', 'odometer_km');
		$nextServiceOdo = $p['next_service_odometer_km'] ?? null;
		if ($nextServiceOdo !== null && $nextServiceOdo !== '') {
			$nextServiceOdo = (int)$nextServiceOdo;
			if ($nextServiceOdo < 0) throw new ValidationException('ODOMETER_NEGATIVE', 'next_service_odometer_km');
		} else {
			$nextServiceOdo = null;
		}
		$leaseStartRaw = $p['lease_start_date'] ?? null;
		$leaseEndRaw = $p['lease_end_date'] ?? null;
		$leaseStart = ($leaseStartRaw === null || $leaseStartRaw === '') ? null : $this->dateOrNull($leaseStartRaw);
		$leaseEnd = ($leaseEndRaw === null || $leaseEndRaw === '') ? null : $this->dateOrNull($leaseEndRaw);
		if ($leaseStart !== null && $leaseEnd !== null && $leaseStart > $leaseEnd) {
			throw new ValidationException('LEASE_RANGE_INVALID', 'lease_end_date');
		}
		$leaseKmRaw = $p['lease_included_km'] ?? null;
		$leaseIncludedKm = null;
		if ($leaseKmRaw !== null && $leaseKmRaw !== '') {
			$leaseIncludedKm = (int)$leaseKmRaw;
			if ($leaseIncludedKm < 0) {
				throw new ValidationException('LEASE_KM_INVALID', 'lease_included_km');
			}
		}
		$leaseReference = $this->orNull($p['lease_reference'] ?? null, 120);
		$dna = $p['do_not_auto_allocate'] ?? false;
		$doNotAuto = $dna === true || $dna === 1 || $dna === '1' || $dna === 'on';

		return [
			'internal_name' => $name,
			'make' => $make,
			'model' => $model,
			'year' => $year,
			'licence_plate' => $plate,
			'colour' => $this->orNull($p['colour'] ?? null, 40),
			'fuel_type' => $fuel,
			'transmission' => $transmission,
			'seating_capacity' => $seats,
			'required_licence_class' => $class,
			'base_location' => $this->orNull($p['base_location'] ?? null, 120),
			'status' => $status,
			'odometer_km' => $odo,
			'insurance_policy_number' => $this->orNull($p['insurance_policy_number'] ?? null, 80),
			'insurance_expiry_date' => $this->dateOrNull($p['insurance_expiry_date'] ?? null),
			'road_tax_expiry_date' => $this->dateOrNull($p['road_tax_expiry_date'] ?? null),
			'next_service_date' => $this->dateOrNull($p['next_service_date'] ?? null),
			'next_service_odometer_km' => $nextServiceOdo,
			'photo_file_id' => $this->orNull($p['photo_file_id'] ?? null, 128),
			'notes' => $p['notes'] !== null && $p['notes'] !== '' ? (string)$p['notes'] : null,
			'lease_start_date' => $leaseStart,
			'lease_end_date' => $leaseEnd,
			'lease_included_km' => $leaseIncludedKm,
			'lease_reference' => $leaseReference,
			'do_not_auto_allocate' => $doNotAuto ? 1 : 0,
		];
	}

	private function orNull(mixed $v, int $maxLen): ?string
	{
		if ($v === null) return null;
		$s = trim((string)$v);
		if ($s === '') return null;
		if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
		return $s;
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
			'internal_name' => (string)$row['internal_name'],
			'make' => (string)$row['make'],
			'model' => (string)$row['model'],
			'year' => (int)$row['year'],
			'licence_plate' => (string)$row['licence_plate'],
			'colour' => $row['colour'] !== null ? (string)$row['colour'] : null,
			'fuel_type' => (string)$row['fuel_type'],
			'transmission' => (string)$row['transmission'],
			'seating_capacity' => (int)$row['seating_capacity'],
			'required_licence_class' => (string)$row['required_licence_class'],
			'base_location' => $row['base_location'] !== null ? (string)$row['base_location'] : null,
			'status' => (string)$row['status'],
			'odometer_km' => (int)$row['odometer_km'],
			'insurance_policy_number' => $row['insurance_policy_number'] !== null ? (string)$row['insurance_policy_number'] : null,
			'insurance_expiry_date' => $row['insurance_expiry_date'] !== null ? substr((string)$row['insurance_expiry_date'], 0, 10) : null,
			'road_tax_expiry_date' => $row['road_tax_expiry_date'] !== null ? substr((string)$row['road_tax_expiry_date'], 0, 10) : null,
			'next_service_date' => $row['next_service_date'] !== null ? substr((string)$row['next_service_date'], 0, 10) : null,
			'next_service_odometer_km' => $row['next_service_odometer_km'] !== null ? (int)$row['next_service_odometer_km'] : null,
			'photo_file_id' => $row['photo_file_id'] !== null ? (string)$row['photo_file_id'] : null,
			'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
			'is_active' => (int)$row['is_active'] === 1,
			'created_by' => (string)$row['created_by'],
			'created_at' => (string)$row['created_at'],
			'updated_at' => (string)$row['updated_at'],
			// §4.2a stations + §4.6.4 photo evidence + §4.6.8 fuel minimum + §4.6.9 QR
			'station_id' => isset($row['station_id']) && $row['station_id'] !== null ? (int)$row['station_id'] : null,
			'self_service_enabled' => isset($row['self_service_enabled']) ? ((int)$row['self_service_enabled'] === 1) : false,
			'photo_evidence_required_at_checkout' => isset($row['photo_evidence_required_at_checkout']) ? ((int)$row['photo_evidence_required_at_checkout'] === 1) : false,
			'photo_evidence_required_at_checkin' => isset($row['photo_evidence_required_at_checkin']) ? ((int)$row['photo_evidence_required_at_checkin'] === 1) : false,
			'photo_evidence_minimum_count' => isset($row['photo_evidence_minimum_count']) ? max(1, (int)$row['photo_evidence_minimum_count']) : 4,
			'fuel_minimum_at_return' => isset($row['fuel_minimum_at_return']) && $row['fuel_minimum_at_return'] !== null ? (string)$row['fuel_minimum_at_return'] : null,
			'charge_minimum_at_return_percent' => isset($row['charge_minimum_at_return_percent']) && $row['charge_minimum_at_return_percent'] !== null ? (int)$row['charge_minimum_at_return_percent'] : null,
			'qr_token_rotated_at' => isset($row['qr_token_rotated_at']) && $row['qr_token_rotated_at'] !== null ? (string)$row['qr_token_rotated_at'] : null,
			'has_qr_token' => isset($row['qr_token_hash']) && (string)$row['qr_token_hash'] !== '',
			'lease_start_date' => isset($row['lease_start_date']) && $row['lease_start_date'] !== null ? substr((string)$row['lease_start_date'], 0, 10) : null,
			'lease_end_date' => isset($row['lease_end_date']) && $row['lease_end_date'] !== null ? substr((string)$row['lease_end_date'], 0, 10) : null,
			'lease_included_km' => isset($row['lease_included_km']) && $row['lease_included_km'] !== null ? (int)$row['lease_included_km'] : null,
			'lease_reference' => isset($row['lease_reference']) && $row['lease_reference'] !== null ? (string)$row['lease_reference'] : null,
			'do_not_auto_allocate' => isset($row['do_not_auto_allocate']) ? ((int)$row['do_not_auto_allocate'] === 1) : false,
		];
	}

	/**
	 * Appendix A — ensure each fleet vehicle has an assignment row so
	 * booking rules resolve deterministically even before admins tailor modes.
	 */
	private function ensureAppendixPoolAssignment(int $vehicleId, string $by): void
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->selectAlias($qb->func()->count('*'), 'c')->from('mc_vehicle_assignments')
				->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
			if ((int)$qb->executeQuery()->fetchOne() > 0) {
				return;
			}
			$now = gmdate('Y-m-d H:i:s');
			$ins = $this->db->getQueryBuilder();
			$ins->insert('mc_vehicle_assignments')->values([
				'vehicle_id' => $ins->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT),
				'assignment_mode' => $ins->createNamedParameter('pool'),
				'assigned_user_id' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'assigned_group_id' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'valid_from' => $ins->createNamedParameter('1970-01-01'),
				'valid_until' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'tax_treatment' => $ins->createNamedParameter('business_only'),
				'monthly_gross_list_price_minor' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'notes' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'created_by_user_id' => $ins->createNamedParameter($by),
				'created_at' => $ins->createNamedParameter($now),
				'updated_at' => $ins->createNamedParameter($now),
			]);
			$ins->executeStatement();
		} catch (\Throwable) {
			// Table absent until appendix migration ran — ignore safely.
		}
	}

	/**
	 * §4.2a — Set the vehicle's home station. `null` removes the binding.
	 */
	public function setStation(int $id, ?int $stationId, string $performedBy): array
	{
		$prev = $this->get($id);
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_vehicles')
			->set('station_id', $stationId !== null
				? $qb->createNamedParameter($stationId, IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('vehicle', $id, 'set_station', $performedBy, [
			'station_id' => [$prev['station_id'] ?? null, $stationId],
		]);
		return $this->get($id);
	}

	/**
	 * §4.6.4 — Configure photo-evidence policy at handover.
	 *
	 * @param array{
	 *   atCheckout?:bool,
	 *   atCheckin?:bool,
	 *   minimumCount?:int,
	 * } $policy
	 */
	public function setPhotoEvidencePolicy(int $id, array $policy, string $performedBy): array
	{
		$this->get($id);
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_vehicles')->set('updated_at', $qb->createNamedParameter($now));
		if (array_key_exists('atCheckout', $policy)) {
			$qb->set('photo_evidence_required_at_checkout', $qb->createNamedParameter($policy['atCheckout'] ? 1 : 0, IQueryBuilder::PARAM_INT));
		}
		if (array_key_exists('atCheckin', $policy)) {
			$qb->set('photo_evidence_required_at_checkin', $qb->createNamedParameter($policy['atCheckin'] ? 1 : 0, IQueryBuilder::PARAM_INT));
		}
		if (array_key_exists('minimumCount', $policy)) {
			$min = max(1, min(20, (int)$policy['minimumCount']));
			$qb->set('photo_evidence_minimum_count', $qb->createNamedParameter($min, IQueryBuilder::PARAM_INT));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('vehicle', $id, 'set_photo_policy', $performedBy, $policy);
		return $this->get($id);
	}

	/**
	 * §4.6.8 — Configure fuel/charge return-minimum policy. Pass `null` for both
	 * to disable the policy on this vehicle.
	 */
	public function setFuelMinimumPolicy(int $id, ?string $minimum, ?int $chargeMinPercent, string $performedBy): array
	{
		$this->get($id);
		if ($minimum !== null && !in_array($minimum, self::FUEL_MINIMUM_VALUES, true)) {
			throw new ValidationException('FUEL_MINIMUM_INVALID', 'fuel_minimum_at_return');
		}
		if ($chargeMinPercent !== null && ($chargeMinPercent < 0 || $chargeMinPercent > 100)) {
			throw new ValidationException('CHARGE_PERCENT_INVALID', 'charge_minimum_at_return_percent');
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_vehicles')
			->set('fuel_minimum_at_return', $minimum !== null
				? $qb->createNamedParameter($minimum)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('charge_minimum_at_return_percent', $chargeMinPercent !== null
				? $qb->createNamedParameter($chargeMinPercent, IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('vehicle', $id, 'set_fuel_minimum', $performedBy, [
			'fuel_minimum_at_return' => $minimum,
			'charge_minimum_at_return_percent' => $chargeMinPercent,
		]);
		return $this->get($id);
	}

	/**
	 * §4.6.9 — Rotate the QR token for this vehicle. Returns the new plaintext
	 * token (caller prints it on the sticker / dashboard); only the SHA-256
	 * hash is stored in the DB. Rotating invalidates any previously printed
	 * sticker immediately (§13.37).
	 */
	public function rotateQrToken(int $id, string $performedBy): string
	{
		$this->get($id);
		if ($this->random === null) {
			throw new \RuntimeException('QR rotation requires ISecureRandom');
		}
		$plaintext = bin2hex($this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC));
		$hash = hash('sha256', $plaintext);
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_vehicles')
			->set('qr_token_hash', $qb->createNamedParameter($hash))
			->set('qr_token_rotated_at', $qb->createNamedParameter($now))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('vehicle', $id, 'rotate_qr_token', $performedBy, ['rotated_at' => $now]);
		return $plaintext;
	}

	/**
	 * §4.6.9 — Verify a presented QR token against the stored SHA-256 hash.
	 * Constant-time comparison. Returns true only when a non-empty token is
	 * configured AND matches.
	 */
	public function verifyQrToken(int $id, string $presented): bool
	{
		if ($presented === '') {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('qr_token_hash')->from('mc_vehicles')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$stored = $qb->executeQuery()->fetchOne();
		if ($stored === false || $stored === null || (string)$stored === '') {
			return false;
		}
		return hash_equals((string)$stored, hash('sha256', $presented));
	}

	/**
	 * §4.6.4 — Effective photo-evidence policy for a vehicle (per-vehicle override
	 * → falls back to global setting). Returns three integers/booleans.
	 *
	 * @return array{atCheckout:bool,atCheckin:bool,minimumCount:int}
	 */
	public function effectivePhotoPolicy(array $vehicle, bool $globalAtCheckout, bool $globalAtCheckin, int $globalMinimum): array
	{
		return [
			'atCheckout' => (bool)($vehicle['photo_evidence_required_at_checkout'] ?? false) || $globalAtCheckout,
			'atCheckin' => (bool)($vehicle['photo_evidence_required_at_checkin'] ?? false) || $globalAtCheckin,
			'minimumCount' => max(
				(int)($vehicle['photo_evidence_minimum_count'] ?? 4),
				$globalMinimum,
			),
		];
	}
}
