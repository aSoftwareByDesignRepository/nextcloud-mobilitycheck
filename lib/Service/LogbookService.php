<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Appendix A3 — Fahrtenbuch entries (draft → confirm → immutable / amend).
 */
class LogbookService
{
	public const TRIP_BUSINESS = 'business';
	public const TRIP_COMMUTE = 'commute';
	public const TRIP_PRIVATE = 'private';

	public const TRIPS = [self::TRIP_BUSINESS, self::TRIP_COMMUTE, self::TRIP_PRIVATE];

	public function __construct(
		private IDBConnection $db,
		private SettingsService $settings,
		private AuditLogService $audit,
		private AccessControlService $access,
		private VehicleAssignmentService $assignments,
	) {
	}

	/** @param array<string,mixed> $filters */
	public function list(array $filters, string $viewerId): array
	{
		$this->access->requireAnyAppRole($viewerId);
		$this->access->requireNotWorkshopOnly($viewerId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('e.*')->from('mc_logbook_entries', 'e')->orderBy('e.trip_date', 'DESC')->addOrderBy('e.id', 'DESC');
		if (!$this->access->isFleetAdminOrManager($viewerId) && !$this->access->isAuditor($viewerId)) {
			$qb->andWhere($qb->expr()->eq('e.driver_user_id', $qb->createNamedParameter($viewerId)));
		} elseif (!empty($filters['driverUserId'])) {
			$qb->andWhere($qb->expr()->eq('e.driver_user_id', $qb->createNamedParameter((string)$filters['driverUserId'])));
		}
		if (!empty($filters['vehicleId'])) {
			$qb->andWhere($qb->expr()->eq('e.vehicle_id', $qb->createNamedParameter((int)$filters['vehicleId'], IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filters['from'])) {
			$qb->andWhere($qb->expr()->gte('e.trip_date', $qb->createNamedParameter((string)$filters['from'])));
		}
		if (!empty($filters['to'])) {
			$qb->andWhere($qb->expr()->lte('e.trip_date', $qb->createNamedParameter((string)$filters['to'])));
		}
		if (!empty($filters['bookingId'])) {
			$qb->andWhere($qb->expr()->eq('e.booking_id', $qb->createNamedParameter((int)$filters['bookingId'], IQueryBuilder::PARAM_INT)));
		}
		if (isset($filters['confirmedOnly']) && $filters['confirmedOnly']) {
			$qb->andWhere($qb->expr()->isNotNull('e.confirmed_at'));
		}
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = $this->hydrate($r);
		}
		$res->closeCursor();
		return $out;
	}

	public function get(int $id, string $viewerId): array
	{
		$row = $this->fetchRow($id);
		$this->assertCanReadEntry($viewerId, $row);
		return $this->hydrate($row);
	}

	/**
	 * §A3.3 — Called after booking check-in completes when logbook module is enabled.
	 */
	public function createDraftFromCompletedBooking(int $bookingId, string $performedBy): ?array
	{
		if (!$this->settings->logbookEnabled()) {
			return null;
		}
		$b = $this->fetchBooking($bookingId);
		if (($b['status'] ?? '') !== BookingService::STATUS_COMPLETED) {
			return null;
		}
		$vehicleId = (int)$b['vehicle_id'];
		$a = $this->assignments->getActiveAssignment($vehicleId);
		if ($a === null) {
			return null;
		}
		if (($a['assignment_mode'] ?? '') === VehicleAssignmentService::MODE_DEDICATED) {
			return null;
		}
		$checkout = $this->fetchCheckoutEvent($bookingId, 'checkout');
		$checkin = $this->fetchCheckoutEvent($bookingId, 'checkin');
		if ($checkout === null || $checkin === null) {
			return null;
		}
		$startKm = (int)$checkout['odometer_km'];
		$endKm = (int)$checkin['odometer_km'];
		if ($endKm < $startKm) {
			return null;
		}
		$tripDate = substr((string)$checkin['recorded_at'], 0, 10);
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'c')->from('mc_logbook_entries')
			->where($qb->expr()->eq('booking_id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)));
		if ((int)$qb->executeQuery()->fetchOne() > 0) {
			return null;
		}

		$now = gmdate('Y-m-d H:i:s');
		$purpose = trim((string)($b['purpose'] ?? ''));
		if (mb_strlen($purpose) < 4) {
			$purpose = '—';
		}
		$late = $this->isLateEntry($tripDate, $now);

		$ins = $this->db->getQueryBuilder();
		$ins->insert('mc_logbook_entries')->values([
			'vehicle_id' => $ins->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT),
			'assignment_id' => $ins->createNamedParameter((int)$a['id'], IQueryBuilder::PARAM_INT),
			'booking_id' => $ins->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT),
			'driver_user_id' => $ins->createNamedParameter((string)$b['driver_user_id']),
			'trip_type' => $ins->createNamedParameter(self::TRIP_BUSINESS),
			'trip_date' => $ins->createNamedParameter($tripDate),
			'departure_time' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'arrival_time' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'start_address' => $ins->createNamedParameter(''),
			'end_address' => $ins->createNamedParameter(''),
			'odometer_start_km' => $ins->createNamedParameter($startKm, IQueryBuilder::PARAM_INT),
			'odometer_end_km' => $ins->createNamedParameter($endKm, IQueryBuilder::PARAM_INT),
			'distance_km' => $ins->createNamedParameter($endKm - $startKm, IQueryBuilder::PARAM_INT),
			'purpose' => $ins->createNamedParameter($purpose),
			'client_or_contact' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'project_reference' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'is_round_trip' => $ins->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'late_entry' => $ins->createNamedParameter($late ? 1 : 0, IQueryBuilder::PARAM_INT),
			'confirmed_at' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'confirmed_by_user_id' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'amendment_of_entry_id' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'amendment_reason' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'is_superseded' => $ins->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'created_at' => $ins->createNamedParameter($now),
			'updated_at' => $ins->createNamedParameter($now),
		]);
		$ins->executeStatement();
		$id = (int)$this->db->lastInsertId('mc_logbook_entries');
		$this->audit->log('logbook_entry', $id, 'draft_from_booking', $performedBy, ['booking_id' => $bookingId]);
		return $this->get($id, $performedBy);
	}

	/** @param array<string,mixed> $payload */
	public function createManual(array $payload, string $userId): array
	{
		$this->requireLogbookFeature();
		$this->access->requireDriver($userId);
		$vehicleId = (int)($payload['vehicleId'] ?? $payload['vehicle_id'] ?? 0);
		if ($vehicleId <= 0) {
			throw new ValidationException('VEHICLE_REQUIRED', 'vehicle_id');
		}
		$this->assignments->assertDedicatedDriver($userId, $vehicleId);
		$a = $this->assignments->getActiveAssignment($vehicleId);
		if ($a === null || ($a['tax_treatment'] ?? '') !== VehicleAssignmentService::TAX_LOGBOOK) {
			throw new ValidationException('LOGBOOK_MANUAL_REQUIRES_LOGBOOK_ASSIGNMENT');
		}
		if (($a['assigned_user_id'] ?? '') !== $userId && !$this->access->isFleetAdminOrManager($userId)) {
			throw new ForbiddenException('NOT_ASSIGNMENT_DRIVER');
		}
		$row = $this->normaliseWritablePayload($payload, true);
		$row['vehicle_id'] = $vehicleId;
		$row['assignment_id'] = (int)$a['id'];
		$row['booking_id'] = null;
		$row['driver_user_id'] = (string)($a['assigned_user_id'] ?? $userId);
		$row['distance_km'] = (int)$row['odometer_end_km'] - (int)$row['odometer_start_km'];
		if ($row['distance_km'] < 0) {
			throw new ValidationException('ODOMETER_DISTANCE_INVALID');
		}
		return $this->insertDraft($row, $userId);
	}

	/** @param array<string,mixed> $payload */
	public function updateDraft(int $id, array $payload, string $userId): array
	{
		$this->requireLogbookFeature();
		$existing = $this->fetchRow($id);
		if ($existing['confirmed_at'] !== null) {
			throw new ValidationException('LOGBOOK_CONFIRMED_READONLY');
		}
		if (($existing['driver_user_id'] ?? '') !== $userId && !$this->access->isFleetAdminOrManager($userId)) {
			throw new ForbiddenException('CANNOT_EDIT_LOGBOOK_ENTRY');
		}
		$base = $this->hydrate($existing);
		$merged = array_merge([
			'trip_type' => $base['trip_type'],
			'trip_date' => $base['trip_date'],
			'departure_time' => $base['departure_time'],
			'arrival_time' => $base['arrival_time'],
			'start_address' => $base['start_address'],
			'end_address' => $base['end_address'],
			'odometer_start_km' => $base['odometer_start_km'],
			'odometer_end_km' => $base['odometer_end_km'],
			'purpose' => $base['purpose'],
			'client_or_contact' => $base['client_or_contact'],
			'project_reference' => $base['project_reference'],
			'is_round_trip' => $base['is_round_trip'],
		], $payload);
		$row = $this->normaliseWritablePayload($merged, false);
		$row['distance_km'] = (int)$row['odometer_end_km'] - (int)$row['odometer_start_km'];
		if ($row['distance_km'] < 0) {
			throw new ValidationException('ODOMETER_DISTANCE_INVALID');
		}
		$late = $this->isLateEntry((string)$row['trip_date'], gmdate('Y-m-d H:i:s'));
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_logbook_entries')
			->set('trip_type', $qb->createNamedParameter((string)$row['trip_type']))
			->set('trip_date', $qb->createNamedParameter((string)$row['trip_date']))
			->set('departure_time', $qb->createNamedParameter($row['departure_time']))
			->set('arrival_time', $qb->createNamedParameter($row['arrival_time']))
			->set('start_address', $qb->createNamedParameter((string)$row['start_address']))
			->set('end_address', $qb->createNamedParameter((string)$row['end_address']))
			->set('odometer_start_km', $qb->createNamedParameter((int)$row['odometer_start_km'], IQueryBuilder::PARAM_INT))
			->set('odometer_end_km', $qb->createNamedParameter((int)$row['odometer_end_km'], IQueryBuilder::PARAM_INT))
			->set('distance_km', $qb->createNamedParameter((int)$row['distance_km'], IQueryBuilder::PARAM_INT))
			->set('purpose', $qb->createNamedParameter($row['purpose']))
			->set('client_or_contact', $qb->createNamedParameter($row['client_or_contact']))
			->set('project_reference', $qb->createNamedParameter($row['project_reference']))
			->set('is_round_trip', $qb->createNamedParameter(!empty($row['is_round_trip']) ? 1 : 0, IQueryBuilder::PARAM_INT))
			->set('late_entry', $qb->createNamedParameter($late ? 1 : 0, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('logbook_entry', $id, 'update_draft', $userId, []);
		return $this->get($id, $userId);
	}

	public function confirm(int $id, string $userId, bool $attestConfirmed): array
	{
		$this->requireLogbookFeature();
		if (!$attestConfirmed) {
			throw new ValidationException('LOGBOOK_ATTEST_REQUIRED');
		}
		$existing = $this->fetchRow($id);
		if ($existing['confirmed_at'] !== null) {
			throw new ValidationException('LOGBOOK_ALREADY_CONFIRMED');
		}
		if (($existing['driver_user_id'] ?? '') !== $userId && !$this->access->isFleetAdminOrManager($userId)) {
			throw new ForbiddenException('CANNOT_CONFIRM_LOGBOOK_ENTRY');
		}
		$data = $this->hydrate($existing);
		$this->validateBusinessRules($data, true);
		$this->validateOdometerChain($data);

		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_logbook_entries')
			->set('confirmed_at', $qb->createNamedParameter($now))
			->set('confirmed_by_user_id', $qb->createNamedParameter($userId))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('logbook_entry', $id, 'confirm', $userId, []);
		return $this->get($id, $userId);
	}

	/** @param array<string,mixed> $payload */
	public function amend(int $id, string $reason, array $payload, string $userId): array
	{
		$this->requireLogbookFeature();
		$reason = trim($reason);
		if ($reason === '') {
			throw new ValidationException('AMENDMENT_REASON_REQUIRED');
		}
		$existing = $this->fetchRow($id);
		if ($existing['confirmed_at'] === null) {
			throw new ValidationException('LOGBOOK_AMEND_ONLY_CONFIRMED');
		}
		if (($existing['driver_user_id'] ?? '') !== $userId && !$this->access->isFleetAdminOrManager($userId)) {
			throw new ForbiddenException('CANNOT_AMEND_LOGBOOK_ENTRY');
		}

		$row = $this->normaliseWritablePayload($payload, true);
		$row['distance_km'] = (int)$row['odometer_end_km'] - (int)$row['odometer_start_km'];
		if ($row['distance_km'] < 0) {
			throw new ValidationException('ODOMETER_DISTANCE_INVALID');
		}
		$row['vehicle_id'] = (int)$existing['vehicle_id'];
		$row['assignment_id'] = (int)$existing['assignment_id'];
		$row['booking_id'] = $existing['booking_id'] !== null ? (int)$existing['booking_id'] : null;
		$row['driver_user_id'] = (string)$existing['driver_user_id'];

		$newEntry = $this->insertDraft(array_merge($row, [
			'amendment_of_entry_id' => $id,
			'amendment_reason' => $reason,
		]), $userId);

		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_logbook_entries')
			->set('is_superseded', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		return $this->get((int)$newEntry['id'], $userId);
	}

	/** @return array<string,mixed> */
	public function gaps(int $vehicleId, string $from, string $to, string $viewerId): array
	{
		$this->access->requireFleetAdminOrManager($viewerId);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
			throw new ValidationException('DATE_RANGE_INVALID');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->sum('distance_km'), 'logged_km')
			->from('mc_logbook_entries')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('confirmed_at'))
			->andWhere($qb->expr()->gte('trip_date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('trip_date', $qb->createNamedParameter($to)));
		$loggedKm = (int)($qb->executeQuery()->fetchOne() ?: 0);

		$qb2 = $this->db->getQueryBuilder();
		$qb2->select('reading_km', 'reading_date')->from('mc_odometer_readings')
			->where($qb2->expr()->eq('vehicle_id', $qb2->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb2->expr()->gte('reading_date', $qb2->createNamedParameter($from)))
			->andWhere($qb2->expr()->lte('reading_date', $qb2->createNamedParameter($to)))
			->orderBy('reading_date', 'ASC')->addOrderBy('id', 'ASC');
		$res = $qb2->executeQuery();
		$first = null;
		$last = null;
		while (($r = $res->fetch()) !== false) {
			if ($first === null) {
				$first = (int)$r['reading_km'];
			}
			$last = (int)$r['reading_km'];
		}
		$res->closeCursor();
		$delta = ($first !== null && $last !== null) ? max(0, $last - $first) : 0;

		return [
			'vehicleId' => $vehicleId,
			'from' => $from,
			'to' => $to,
			'loggedKmConfirmed' => $loggedKm,
			'odometerDeltaKm' => $delta,
			'gapKm' => max(0, $delta - $loggedKm),
		];
	}

	// ─── internals ──────────────────────────────────────────────────────

	private function requireLogbookFeature(): void
	{
		if (!$this->settings->logbookEnabled()) {
			throw new ValidationException('LOGBOOK_MODULE_DISABLED');
		}
	}

	private function isLateEntry(string $tripDate, string $savedAtUtc): bool
	{
		$grace = max(0, $this->settings->logbookGraceDays());
		$tripTs = strtotime($tripDate . ' UTC');
		$deadline = $tripTs !== false ? ($tripTs + ($grace + 1) * 86400) : null;
		return $deadline !== null && strtotime($savedAtUtc . ' UTC') > $deadline;
	}

	/** @param array<string,mixed> $data */
	private function assertCanReadEntry(string $viewerId, array $data): void
	{
		if ($this->access->isFleetAdminOrManager($viewerId) || $this->access->isAuditor($viewerId)) {
			return;
		}
		if (($data['driver_user_id'] ?? '') !== $viewerId) {
			throw new ForbiddenException('CANNOT_VIEW_LOGBOOK_ENTRY');
		}
	}

	private function fetchRow(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_logbook_entries')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$r = $qb->executeQuery()->fetch();
		if (!$r) {
			throw new NotFoundException('LOGBOOK_ENTRY_NOT_FOUND');
		}
		return $r;
	}

	/** @return array<string,mixed> */
	private function fetchBooking(int $bookingId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_bookings')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)));
		$r = $qb->executeQuery()->fetch();
		if (!$r) {
			throw new NotFoundException('BOOKING_NOT_FOUND');
		}
		return $r;
	}

	/** @return ?array<string,mixed> */
	private function fetchCheckoutEvent(int $bookingId, string $eventType): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_checkout_logs')
			->where($qb->expr()->eq('booking_id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('event_type', $qb->createNamedParameter($eventType)))
			->orderBy('id', 'DESC')->setMaxResults(1);
		$r = $qb->executeQuery()->fetch();
		return $r ?: null;
	}

	/** @param array<string,mixed> $row */
	private function insertDraft(array $row, string $userId): array
	{
		if (!isset($row['distance_km'])) {
			$row['distance_km'] = (int)$row['odometer_end_km'] - (int)$row['odometer_start_km'];
		}
		$this->validateBusinessRules($row, false);
		$now = gmdate('Y-m-d H:i:s');
		$tripDate = (string)$row['trip_date'];
		$late = $this->isLateEntry($tripDate, $now);
		$ins = $this->db->getQueryBuilder();
		$ins->insert('mc_logbook_entries')->values([
			'vehicle_id' => $ins->createNamedParameter((int)$row['vehicle_id'], IQueryBuilder::PARAM_INT),
			'assignment_id' => $ins->createNamedParameter((int)$row['assignment_id'], IQueryBuilder::PARAM_INT),
			'booking_id' => $row['booking_id'] !== null
				? $ins->createNamedParameter((int)$row['booking_id'], IQueryBuilder::PARAM_INT)
				: $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'driver_user_id' => $ins->createNamedParameter((string)$row['driver_user_id']),
			'trip_type' => $ins->createNamedParameter((string)$row['trip_type']),
			'trip_date' => $ins->createNamedParameter($tripDate),
			'departure_time' => $ins->createNamedParameter($row['departure_time']),
			'arrival_time' => $ins->createNamedParameter($row['arrival_time']),
			'start_address' => $ins->createNamedParameter((string)$row['start_address']),
			'end_address' => $ins->createNamedParameter((string)$row['end_address']),
			'odometer_start_km' => $ins->createNamedParameter((int)$row['odometer_start_km'], IQueryBuilder::PARAM_INT),
			'odometer_end_km' => $ins->createNamedParameter((int)$row['odometer_end_km'], IQueryBuilder::PARAM_INT),
			'distance_km' => $ins->createNamedParameter((int)$row['distance_km'], IQueryBuilder::PARAM_INT),
			'purpose' => $ins->createNamedParameter($row['purpose']),
			'client_or_contact' => $ins->createNamedParameter($row['client_or_contact']),
			'project_reference' => $ins->createNamedParameter($row['project_reference']),
			'is_round_trip' => $ins->createNamedParameter(!empty($row['is_round_trip']) ? 1 : 0, IQueryBuilder::PARAM_INT),
			'late_entry' => $ins->createNamedParameter($late ? 1 : 0, IQueryBuilder::PARAM_INT),
			'confirmed_at' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'confirmed_by_user_id' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'amendment_of_entry_id' => isset($row['amendment_of_entry_id']) && $row['amendment_of_entry_id'] !== null
				? $ins->createNamedParameter((int)$row['amendment_of_entry_id'], IQueryBuilder::PARAM_INT)
				: $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'amendment_reason' => isset($row['amendment_reason'])
				? $ins->createNamedParameter((string)$row['amendment_reason'])
				: $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'is_superseded' => $ins->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'created_at' => $ins->createNamedParameter($now),
			'updated_at' => $ins->createNamedParameter($now),
		]);
		$ins->executeStatement();
		$id = (int)$this->db->lastInsertId('mc_logbook_entries');
		$this->audit->log('logbook_entry', $id, 'create_draft', $userId, []);
		return $this->get($id, $userId);
	}

	/** @param array<string,mixed> $payload */
	private function normaliseWritablePayload(array $payload, bool $requireAll): array
	{
		$tripType = (string)($payload['tripType'] ?? $payload['trip_type'] ?? '');
		if ($tripType === '' && $requireAll) {
			throw new ValidationException('TRIP_TYPE_REQUIRED');
		}
		if ($tripType !== '' && !in_array($tripType, self::TRIPS, true)) {
			throw new ValidationException('TRIP_TYPE_INVALID');
		}
		$tripDate = trim((string)($payload['tripDate'] ?? $payload['trip_date'] ?? ''));
		if ($tripDate === '' && $requireAll) {
			throw new ValidationException('TRIP_DATE_REQUIRED');
		}
		if ($tripDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tripDate)) {
			throw new ValidationException('TRIP_DATE_INVALID');
		}
		$dep = $this->optionalTime($payload['departureTime'] ?? $payload['departure_time'] ?? null);
		$arr = $this->optionalTime($payload['arrivalTime'] ?? $payload['arrival_time'] ?? null);
		$startAddr = trim((string)($payload['startAddress'] ?? $payload['start_address'] ?? ''));
		$endAddr = trim((string)($payload['endAddress'] ?? $payload['end_address'] ?? ''));
		$oStart = (int)($payload['odometerStartKm'] ?? $payload['odometer_start_km'] ?? -1);
		$oEnd = (int)($payload['odometerEndKm'] ?? $payload['odometer_end_km'] ?? -1);
		$purpose = trim((string)($payload['purpose'] ?? ''));
		$client = $this->nullableTrim($payload['clientOrContact'] ?? $payload['client_or_contact'] ?? null, 250);
		$proj = $this->nullableTrim($payload['projectReference'] ?? $payload['project_reference'] ?? null, 120);
		$round = !empty($payload['isRoundTrip'] ?? $payload['is_round_trip'] ?? false);

		return [
			'trip_type' => $tripType,
			'trip_date' => $tripDate,
			'departure_time' => $dep,
			'arrival_time' => $arr,
			'start_address' => $startAddr,
			'end_address' => $endAddr,
			'odometer_start_km' => $oStart,
			'odometer_end_km' => $oEnd,
			'purpose' => $purpose !== '' ? $purpose : null,
			'client_or_contact' => $client,
			'project_reference' => $proj,
			'is_round_trip' => $round,
		];
	}

	private function optionalTime(mixed $v): ?string
	{
		if ($v === null || $v === '') {
			return null;
		}
		$s = trim((string)$v);
		if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $s)) {
			throw new ValidationException('TIME_INVALID');
		}
		return strlen($s) === 5 ? $s . ':00' : $s;
	}

	private function nullableTrim(mixed $v, int $max): ?string
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

	/** @param array<string,mixed> $row */
	private function validateBusinessRules(array $row, bool $confirming): void
	{
		$type = (string)($row['trip_type'] ?? '');
		if (!in_array($type, self::TRIPS, true)) {
			throw new ValidationException('TRIP_TYPE_INVALID');
		}
		if ($confirming || ($row['purpose'] ?? '') === null || $row['purpose'] === '') {
			// drafts may omit purpose until confirm for booking-origin drafts
		}
		if ($confirming) {
			if (mb_strlen(trim((string)($row['start_address'] ?? ''))) < 2) {
				throw new ValidationException('START_ADDRESS_REQUIRED');
			}
			if (mb_strlen(trim((string)($row['end_address'] ?? ''))) < 2) {
				throw new ValidationException('END_ADDRESS_REQUIRED');
			}
			if (($row['purpose'] ?? '') === null || trim((string)$row['purpose']) === '') {
				if ($type !== self::TRIP_PRIVATE) {
					throw new ValidationException('PURPOSE_REQUIRED');
				}
			}
			if ($type === self::TRIP_BUSINESS) {
				$c = trim((string)($row['client_or_contact'] ?? ''));
				if ($c === '') {
					throw new ValidationException('CLIENT_OR_CONTACT_REQUIRED');
				}
				if (($row['departure_time'] ?? null) === null || ($row['arrival_time'] ?? null) === null) {
					throw new ValidationException('BUSINESS_TIMES_REQUIRED');
				}
			}
			if ($type === self::TRIP_COMMUTE) {
				if (($row['purpose'] ?? '') === null || trim((string)$row['purpose']) === '') {
					throw new ValidationException('COMMUTE_PURPOSE_REQUIRED');
				}
			}
		}
		if ((int)($row['odometer_end_km'] ?? 0) < (int)($row['odometer_start_km'] ?? 0)) {
			throw new ValidationException('ODOMETER_DISTANCE_INVALID');
		}
		$dist = (int)($row['odometer_end_km'] ?? 0) - (int)($row['odometer_start_km'] ?? 0);
		if (($row['distance_km'] ?? null) !== null && (int)$row['distance_km'] !== $dist) {
			throw new ValidationException('DISTANCE_MISMATCH');
		}
	}

	/** @param array<string,mixed> $candidate hydrated row */
	private function validateOdometerChain(array $candidate): void
	{
		$vehicleId = (int)$candidate['vehicle_id'];
		$tripDate = (string)$candidate['trip_date'];
		$id = (int)$candidate['id'];
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_logbook_entries')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('confirmed_at'))
			->andWhere($qb->expr()->eq('is_superseded', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->lt('trip_date', $qb->createNamedParameter($tripDate)),
				$qb->expr()->andX(
					$qb->expr()->eq('trip_date', $qb->createNamedParameter($tripDate)),
					$qb->expr()->lt('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
				),
			))
			->orderBy('trip_date', 'DESC')->addOrderBy('id', 'DESC')
			->setMaxResults(1);
		$pred = $qb->executeQuery()->fetch();
		if ($pred !== false && $pred !== null) {
			$prevEnd = (int)$pred['odometer_end_km'];
			if ((int)$candidate['odometer_start_km'] < $prevEnd) {
				throw new ValidationException('ODOMETER_CHAIN_BROKEN', null, [
					'conflictsWithEntryId' => (int)$pred['id'],
					'previousEndKm' => $prevEnd,
				]);
			}
		}
	}

	/** @param array<string,mixed> $row */
	private function hydrate(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'vehicle_id' => (int)$row['vehicle_id'],
			'assignment_id' => (int)$row['assignment_id'],
			'booking_id' => isset($row['booking_id']) && $row['booking_id'] !== null ? (int)$row['booking_id'] : null,
			'driver_user_id' => (string)$row['driver_user_id'],
			'trip_type' => (string)$row['trip_type'],
			'trip_date' => (string)$row['trip_date'],
			'departure_time' => $row['departure_time'] !== null ? (string)$row['departure_time'] : null,
			'arrival_time' => $row['arrival_time'] !== null ? (string)$row['arrival_time'] : null,
			'start_address' => (string)$row['start_address'],
			'end_address' => (string)$row['end_address'],
			'odometer_start_km' => (int)$row['odometer_start_km'],
			'odometer_end_km' => (int)$row['odometer_end_km'],
			'distance_km' => (int)$row['distance_km'],
			'purpose' => $row['purpose'] !== null ? (string)$row['purpose'] : null,
			'client_or_contact' => $row['client_or_contact'] !== null ? (string)$row['client_or_contact'] : null,
			'project_reference' => $row['project_reference'] !== null ? (string)$row['project_reference'] : null,
			'is_round_trip' => !empty((int)$row['is_round_trip']),
			'late_entry' => !empty((int)$row['late_entry']),
			'confirmed_at' => $row['confirmed_at'] !== null ? (string)$row['confirmed_at'] : null,
			'confirmed_by_user_id' => $row['confirmed_by_user_id'] !== null ? (string)$row['confirmed_by_user_id'] : null,
			'amendment_of_entry_id' => isset($row['amendment_of_entry_id']) && $row['amendment_of_entry_id'] !== null ? (int)$row['amendment_of_entry_id'] : null,
			'amendment_reason' => $row['amendment_reason'] !== null ? (string)$row['amendment_reason'] : null,
			'is_superseded' => !empty((int)$row['is_superseded']),
			'created_at' => (string)$row['created_at'],
			'updated_at' => (string)$row['updated_at'],
		];
	}
}
