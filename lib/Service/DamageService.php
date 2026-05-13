<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * §4.7 — Damage report lifecycle.
 *
 * Reports are immutable once persisted (§1.10): the `amend()` method
 * creates a new row whose `amendment_of_report_id` points at the
 * superseded row. The original is never updated except for its status
 * which moves through the documented state machine; the legally
 * binding fields (description, severity, photos, discovery_datetime)
 * never change in place.
 *
 * `safety_critical` reports auto-block the vehicle (§4.7).
 */
class DamageService
{
	public const STATUS_REPORTED = 'reported';
	public const STATUS_UNDER_ASSESSMENT = 'under_assessment';
	public const STATUS_REPAIR_SCHEDULED = 'repair_scheduled';
	public const STATUS_IN_REPAIR = 'in_repair';
	public const STATUS_REPAIRED = 'repaired';
	public const STATUS_CLOSED_NO_ACTION = 'closed_no_action';

	public const STATUSES = [
		self::STATUS_REPORTED,
		self::STATUS_UNDER_ASSESSMENT,
		self::STATUS_REPAIR_SCHEDULED,
		self::STATUS_IN_REPAIR,
		self::STATUS_REPAIRED,
		self::STATUS_CLOSED_NO_ACTION,
	];

	public const SEVERITY_COSMETIC = 'cosmetic';
	public const SEVERITY_MINOR = 'minor';
	public const SEVERITY_MAJOR = 'major';
	public const SEVERITY_SAFETY_CRITICAL = 'safety_critical';

	public const SEVERITIES = [
		self::SEVERITY_COSMETIC,
		self::SEVERITY_MINOR,
		self::SEVERITY_MAJOR,
		self::SEVERITY_SAFETY_CRITICAL,
	];

	public const ZONES = ['front', 'rear', 'left', 'right', 'roof', 'interior', 'underbody'];

	/**
	 * Allowed status transitions. Closing a safety-critical report as
	 * `closed_no_action` requires an explicit override + reason (§4.7).
	 *
	 * @var array<string,list<string>>
	 */
	private const TRANSITIONS = [
		self::STATUS_REPORTED => [self::STATUS_UNDER_ASSESSMENT, self::STATUS_CLOSED_NO_ACTION, self::STATUS_REPAIR_SCHEDULED],
		self::STATUS_UNDER_ASSESSMENT => [self::STATUS_REPAIR_SCHEDULED, self::STATUS_CLOSED_NO_ACTION],
		self::STATUS_REPAIR_SCHEDULED => [self::STATUS_IN_REPAIR, self::STATUS_CLOSED_NO_ACTION],
		self::STATUS_IN_REPAIR => [self::STATUS_REPAIRED, self::STATUS_CLOSED_NO_ACTION],
		self::STATUS_REPAIRED => [],
		self::STATUS_CLOSED_NO_ACTION => [],
	];

	public function __construct(
		private IDBConnection $db,
		private VehicleService $vehicles,
		private AccessControlService $access,
		private AuditLogService $audit,
		private LineManagerService $lineManagers,
		private BookingService $bookings,
	) {
	}

	/** @return list<array<string,mixed>> */
	/**
	 * @param array<string,mixed> $filters Supported keys:
	 *   - vehicleId (int)
	 *   - status (string)            single status
	 *   - statusIn (list<string>)    any of these statuses (overrides `status`)
	 *   - severity (string)
	 *   - reportedByUserId (string)
	 *   - openOnly (bool)            excludes repaired/closed_no_action
	 *   - includeAmendments (bool)   include rows that supersede an earlier one
	 *   - limit (int, 1..500)
	 *   - viewerUserId (string)      current user — applies driver / line-manager visibility (§2.2)
	 *
	 * @return list<array<string,mixed>>
	 */
	public function list(array $filters = []): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('dr.*')->from('mc_damage_reports', 'dr')->orderBy('dr.discovery_datetime', 'DESC');
		if (empty($filters['includeAmendments'])) {
			$qb->andWhere($qb->expr()->isNull('dr.amendment_of_report_id'));
		}
		if (!empty($filters['vehicleId'])) {
			$qb->andWhere($qb->expr()->eq('dr.vehicle_id', $qb->createNamedParameter((int)$filters['vehicleId'], IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filters['statusIn']) && is_array($filters['statusIn'])) {
			$qb->andWhere($qb->expr()->in('dr.status', $qb->createNamedParameter(array_values(array_filter($filters['statusIn'], 'is_string')), IQueryBuilder::PARAM_STR_ARRAY)));
		} elseif (!empty($filters['status'])) {
			$qb->andWhere($qb->expr()->eq('dr.status', $qb->createNamedParameter((string)$filters['status'])));
		}
		if (!empty($filters['severity'])) {
			$qb->andWhere($qb->expr()->eq('dr.severity', $qb->createNamedParameter((string)$filters['severity'])));
		}
		if (!empty($filters['reportedByUserId'])) {
			$qb->andWhere($qb->expr()->eq('dr.reported_by_user_id', $qb->createNamedParameter((string)$filters['reportedByUserId'])));
		}
		if (!empty($filters['openOnly'])) {
			$qb->andWhere($qb->expr()->notIn('dr.status', $qb->createNamedParameter([self::STATUS_REPAIRED, self::STATUS_CLOSED_NO_ACTION], IQueryBuilder::PARAM_STR_ARRAY)));
		}
		if (!empty($filters['viewerUserId'])) {
			$this->applyDamageListVisibility($qb, 'dr', (string)$filters['viewerUserId']);
		}
		$qb->setMaxResults(min(500, max(1, (int)($filters['limit'] ?? 200))));
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = $this->hydrate($r);
		}
		$res->closeCursor();
		return $this->decorateWithVehicle($out);
	}

	/**
	 * §2.2 — View gate for a single damage row (list uses the same rules via join).
	 *
	 * @param array<string,mixed> $report Hydrated damage row from {@see get()} or list.
	 */
	public function assertUserMayViewDamageReport(string $viewerId, array $report): void
	{
		if ($this->access->isFleetAdminOrManager($viewerId) || $this->access->isAuditor($viewerId) || $this->access->isAppAdmin($viewerId)) {
			return;
		}
		if ($this->access->isWorkshopOnly($viewerId)) {
			return;
		}
		if ((string)($report['reported_by_user_id'] ?? '') === $viewerId) {
			return;
		}
		$bid = $report['booking_id'] ?? null;
		if ($bid !== null && (int)$bid > 0) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('driver_user_id')->from('mc_bookings')
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$bid, IQueryBuilder::PARAM_INT)));
			$row = $qb->executeQuery()->fetch();
			$driver = $row ? (string)$row['driver_user_id'] : '';
			if ($driver !== '' && $driver === $viewerId) {
				return;
			}
			if ($driver !== '' && $this->access->isLineManager($viewerId)
				&& $this->lineManagers->isActiveLineManagerForDriver($viewerId, $driver)) {
				return;
			}
		}
		throw new ForbiddenException('INSUFFICIENT_ROLE');
	}

	/**
	 * Drivers and line managers only see damage linked to themselves or supervised drivers.
	 */
	private function applyDamageListVisibility(IQueryBuilder $qb, string $alias, string $viewerId): void
	{
		if ($viewerId === '') {
			return;
		}
		if ($this->access->isFleetAdminOrManager($viewerId) || $this->access->isAuditor($viewerId) || $this->access->isAppAdmin($viewerId)) {
			return;
		}
		if ($this->access->isWorkshopOnly($viewerId)) {
			return;
		}
		$allowed = [$viewerId];
		if ($this->access->isLineManager($viewerId)) {
			$allowed = array_values(array_unique(array_merge(
				$this->lineManagers->listSupervisedDriverUserIds($viewerId),
				[$viewerId]
			)));
		}
		$reportCol = $alias . '.reported_by_user_id';
		$bookingCol = $alias . '.booking_id';
		$qb->leftJoin($alias, 'mc_bookings', 'dmg_vis_booking', $qb->expr()->eq('dmg_vis_booking.id', $bookingCol));
		$param = $qb->createNamedParameter($allowed, IQueryBuilder::PARAM_STR_ARRAY);
		$qb->andWhere($qb->expr()->orX(
			$qb->expr()->in($reportCol, $param),
			$qb->expr()->in('dmg_vis_booking.driver_user_id', $param),
		));
	}

	public function get(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_damage_reports')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if (!$row) {
			throw new NotFoundException('DAMAGE_REPORT_NOT_FOUND');
		}
		$decorated = $this->decorateWithVehicle([$this->hydrate($row)]);
		return $decorated[0];
	}

	/**
	 * Attach a small read-only vehicle summary to each damage row so the
	 * frontend can render "Pool 1 · AB-CD-123" without an extra round-trip.
	 *
	 * @param list<array<string,mixed>> $reports
	 * @return list<array<string,mixed>>
	 */
	private function decorateWithVehicle(array $reports): array
	{
		if ($reports === []) {
			return $reports;
		}
		$ids = array_map(static fn ($r) => (int)($r['vehicle_id'] ?? 0), $reports);
		$summaries = $this->vehicles->summariesByIds($ids);
		foreach ($reports as &$r) {
			$v = $summaries[(int)($r['vehicle_id'] ?? 0)] ?? null;
			$r['vehicle_internal_name'] = $v['internal_name'] ?? null;
			$r['vehicle_licence_plate'] = $v['licence_plate'] ?? null;
			$r['vehicle_status'] = $v['status'] ?? null;
		}
		return $reports;
	}

	/**
	 * §4.7 — Create a damage report. Photos are attached afterwards via
	 * the dedicated upload endpoint; severity ≥ minor requires at least
	 * one photo before the report is treated as complete (enforced at
	 * status-transition time).
	 *
	 * @param array<string,mixed> $payload
	 */
	public function create(array $payload, string $performedBy): array
	{
		$vehicleId = (int)($payload['vehicleId'] ?? $payload['vehicle_id'] ?? 0);
		if ($vehicleId <= 0) {
			throw new ValidationException('VEHICLE_REQUIRED', 'vehicle_id');
		}
		$vehicle = $this->vehicles->get($vehicleId);
		$bookingId = $payload['bookingId'] ?? $payload['booking_id'] ?? null;
		$bookingId = ($bookingId !== null && $bookingId !== '') ? (int)$bookingId : null;
		$discovery = trim((string)($payload['discoveryDatetime'] ?? $payload['discovery_datetime'] ?? ''));
		if ($discovery === '') {
			throw new ValidationException('DISCOVERY_DATETIME_REQUIRED', 'discovery_datetime');
		}
		// Accept HTML datetime-local ("2026-05-15T09:00"), ISO 8601 with "Z" suffix
		// ("2026-05-15T07:00:00Z" — what the JS client sends) and ISO 8601 with
		// explicit timezone offset. Convert to UTC and re-format for storage.
		if (!preg_match(
			'/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?$/',
			$discovery,
		)) {
			throw new ValidationException('DATETIME_INVALID', 'discovery_datetime');
		}
		try {
			$discoveryDt = new \DateTimeImmutable($discovery, new \DateTimeZone('UTC'));
		} catch (\Throwable) {
			throw new ValidationException('DATETIME_INVALID', 'discovery_datetime');
		}
		$discovery = $discoveryDt->format('Y-m-d H:i:s');
		$description = trim((string)($payload['description'] ?? ''));
		if (mb_strlen($description) < 5) {
			throw new ValidationException('DESCRIPTION_TOO_SHORT', 'description');
		}
		if (mb_strlen($description) > 4000) {
			throw new ValidationException('DESCRIPTION_TOO_LONG', 'description');
		}
		$zone = (string)($payload['zone'] ?? '');
		if (!in_array($zone, self::ZONES, true)) {
			throw new ValidationException('ZONE_INVALID', 'zone');
		}
		$severity = (string)($payload['severity'] ?? '');
		if (!in_array($severity, self::SEVERITIES, true)) {
			throw new ValidationException('SEVERITY_INVALID', 'severity');
		}
		$isDriveable = (bool)($payload['isDriveable'] ?? $payload['is_driveable'] ?? true);

		// Drivers may only report damage for vehicles they have an active
		// booking on (§2.2 "Submit damage report — Driver: Own (checked out)").
		// Managers + admins may report on any vehicle.
		$isManager = $this->access->isFleetAdminOrManager($performedBy);
		if (!$isManager) {
			$hasActiveBooking = $this->driverHasActiveBookingOnVehicle($performedBy, $vehicleId);
			if (!$hasActiveBooking) {
				throw new ForbiddenException('DRIVER_NO_ACTIVE_BOOKING_ON_VEHICLE');
			}
		}

		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('mc_damage_reports')->values([
			'vehicle_id' => $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT),
			'booking_id' => $bookingId !== null
				? $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'reported_by_user_id' => $qb->createNamedParameter($performedBy),
			'discovery_datetime' => $qb->createNamedParameter($discovery),
			'description' => $qb->createNamedParameter($description),
			'zone' => $qb->createNamedParameter($zone),
			'severity' => $qb->createNamedParameter($severity),
			'is_driveable' => $qb->createNamedParameter($isDriveable ? 1 : 0, IQueryBuilder::PARAM_INT),
			'status' => $qb->createNamedParameter(self::STATUS_REPORTED),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
		]);
		$qb->executeStatement();
		$id = (int)$this->db->lastInsertId('mc_damage_reports');
		$this->audit->log('damage_report', $id, 'create', $performedBy, [
			'vehicle_id' => $vehicleId,
			'severity' => $severity,
			'zone' => $zone,
		]);
		// Safety-critical → block the vehicle.
		if ($severity === self::SEVERITY_SAFETY_CRITICAL && $vehicle['status'] !== VehicleService::STATUS_DECOMMISSIONED) {
			$this->vehicles->setStatus($vehicleId, VehicleService::STATUS_IN_MAINTENANCE, $performedBy);
		}
		return $this->get($id);
	}

	public function attachPhoto(int $id, string $fileNodeId, string $performedBy): array
	{
		$report = $this->get($id);
		// §2.2 — only the original reporter or a fleet admin/manager may
		// attach further photos to a report. Drivers must not be able to
		// drop photos into a colleague's report (IDOR guard, §8).
		// Workshop users may also attach photos to a damage report whose
		// repair job is assigned to them; that lookup belongs in the
		// dedicated repair flow, not here, so we keep this check tight.
		$isReporter = ($report['reported_by_user_id'] ?? '') === $performedBy;
		$isManager = $this->access->isFleetAdminOrManager($performedBy);
		if (!$isReporter && !$isManager) {
			throw new ForbiddenException('CANNOT_ATTACH_PHOTO');
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('mc_damage_photos')->values([
			'damage_report_id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT),
			'vehicle_id' => $qb->createNamedParameter((int)$report['vehicle_id'], IQueryBuilder::PARAM_INT),
			'booking_id' => isset($report['booking_id']) && $report['booking_id'] !== null
				? $qb->createNamedParameter((int)$report['booking_id'], IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'evidence_type' => $qb->createNamedParameter('damage'),
			'file_id' => $qb->createNamedParameter($fileNodeId),
			'uploaded_by_user_id' => $qb->createNamedParameter($performedBy),
			'uploaded_at' => $qb->createNamedParameter($now),
		]);
		$qb->executeStatement();
		$photoId = (int)$this->db->lastInsertId('mc_damage_photos');
		$this->audit->log('damage_report', $id, 'photo_added', $performedBy, ['photo_id' => $photoId, 'file_id' => $fileNodeId]);
		return $this->fullDetail($id);
	}

	/**
	 * §4.6.4 — Attach a handover (pre-trip / post-trip) photo to a booking.
	 * Reuses `mc_damage_photos` with `damage_report_id = NULL` and the
	 * appropriate `evidence_type`. Permission: only the driver of the
	 * booking, a fleet manager/admin, or — for an `active` trip — anyone
	 * with `currentDriverHas` write access via the booking flow.
	 *
	 * @return array<string,mixed>
	 */
	public function attachHandoverPhoto(int $bookingId, string $fileNodeId, string $evidenceType, string $performedBy): array
	{
		if ($evidenceType !== 'pre_trip' && $evidenceType !== 'post_trip') {
			throw new ValidationException('EVIDENCE_TYPE_INVALID', 'evidenceType');
		}
		$booking = $this->bookings->get($bookingId);
		$this->bookings->assertUserMayViewBooking($booking, $performedBy);
		$isDriver = ($booking['driver_user_id'] ?? '') === $performedBy;
		$isManager = $this->access->isFleetAdminOrManager($performedBy);
		if (!$isDriver && !$isManager) {
			throw new ForbiddenException('CANNOT_ATTACH_HANDOVER_PHOTO');
		}
		// Pre-trip photos may only be attached while the booking is still
		// `approved` (handover window); post-trip photos require an active
		// trip (between checkout and check-in). After `completed` the trip
		// is closed and the evidence chain is frozen.
		$status = (string)($booking['status'] ?? '');
		if ($evidenceType === 'pre_trip' && $status !== 'approved') {
			throw new ValidationException('HANDOVER_PHOTO_WINDOW_CLOSED', 'evidenceType', [
				'expected_status' => 'approved',
				'actual_status' => $status,
			]);
		}
		if ($evidenceType === 'post_trip' && $status !== 'active') {
			throw new ValidationException('HANDOVER_PHOTO_WINDOW_CLOSED', 'evidenceType', [
				'expected_status' => 'active',
				'actual_status' => $status,
			]);
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('mc_damage_photos')->values([
			'damage_report_id' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'vehicle_id' => $qb->createNamedParameter((int)$booking['vehicle_id'], IQueryBuilder::PARAM_INT),
			'booking_id' => $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT),
			'evidence_type' => $qb->createNamedParameter($evidenceType),
			'file_id' => $qb->createNamedParameter($fileNodeId),
			'uploaded_by_user_id' => $qb->createNamedParameter($performedBy),
			'uploaded_at' => $qb->createNamedParameter($now),
		]);
		$qb->executeStatement();
		$photoId = (int)$this->db->lastInsertId('mc_damage_photos');
		$this->audit->log('booking', $bookingId, 'handover_photo_added', $performedBy, [
			'photo_id' => $photoId,
			'file_id' => $fileNodeId,
			'evidence_type' => $evidenceType,
		]);
		return $this->listHandoverPhotos($bookingId, $performedBy);
	}

	/**
	 * §4.6.4 — List pre-trip / post-trip handover photos for a booking.
	 *
	 * @return array<string,mixed>
	 */
	public function listHandoverPhotos(int $bookingId, string $viewerId): array
	{
		$booking = $this->bookings->get($bookingId);
		$this->bookings->assertUserMayViewBooking($booking, $viewerId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'evidence_type', 'file_id', 'uploaded_by_user_id', 'uploaded_at')
			->from('mc_damage_photos')
			->where($qb->expr()->eq('booking_id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('evidence_type', $qb->createNamedParameter(['pre_trip', 'post_trip'], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('uploaded_at', 'ASC');
		$res = $qb->executeQuery();
		$pre = [];
		$post = [];
		while (($r = $res->fetch()) !== false) {
			$row = [
				'id' => (int)$r['id'],
				'fileId' => (string)$r['file_id'],
				'uploadedBy' => (string)$r['uploaded_by_user_id'],
				'uploadedAt' => (string)$r['uploaded_at'],
			];
			if (($r['evidence_type'] ?? '') === 'pre_trip') {
				$pre[] = $row;
			} elseif (($r['evidence_type'] ?? '') === 'post_trip') {
				$post[] = $row;
			}
		}
		$res->closeCursor();
		return [
			'bookingId' => $bookingId,
			'preTrip' => $pre,
			'postTrip' => $post,
		];
	}

	public function updateStatus(int $id, string $status, string $performedBy, ?string $reason, bool $safetyCriticalClosureAcknowledged = false): array
	{
		if (!in_array($status, self::STATUSES, true)) {
			throw new ValidationException('STATUS_INVALID', 'status');
		}
		$report = $this->get($id);
		if (!in_array($status, self::TRANSITIONS[$report['status']] ?? [], true)) {
			throw new ValidationException('STATUS_TRANSITION_INVALID', 'status', [
				'from' => $report['status'],
				'to' => $status,
			]);
		}
		// §4.7 — photos are mandatory once severity is at or above `minor`.
		// We enforce this at the *first* transition out of `reported` so the
		// reporter still has a window to attach photos right after creation
		// without the report being blocked.
		if ($report['status'] === self::STATUS_REPORTED
			&& in_array($report['severity'], [self::SEVERITY_MINOR, self::SEVERITY_MAJOR, self::SEVERITY_SAFETY_CRITICAL], true)
			&& $status !== self::STATUS_REPORTED
			&& !$this->reportHasPhotos($id)) {
			throw new ValidationException('PHOTOS_REQUIRED', 'photos', [
				'severity' => $report['severity'],
			]);
		}
		// §2a.4 / §4.7 — closing safety-critical as "no action" requires a
		// substantive reason **and** an explicit API acknowledgement that
		// cannot be satisfied by UI checkbox bypass alone.
		if ($report['severity'] === self::SEVERITY_SAFETY_CRITICAL && $status === self::STATUS_CLOSED_NO_ACTION) {
			if ($reason === null || mb_strlen(trim($reason)) < 10) {
				throw new ValidationException('SAFETY_CRITICAL_REQUIRES_REASON', 'reason');
			}
			if (!$safetyCriticalClosureAcknowledged) {
				throw new ValidationException('SAFETY_CRITICAL_ACK_REQUIRED', 'safetyCriticalClosureAcknowledged');
			}
		}
		// Status transitions to terminal states must have a reason if
		// severity is major/safety_critical (§4.7).
		if (in_array($status, [self::STATUS_REPAIRED, self::STATUS_CLOSED_NO_ACTION], true)
			&& ($reason === null || trim($reason) === '')
			&& in_array($report['severity'], [self::SEVERITY_MAJOR, self::SEVERITY_SAFETY_CRITICAL], true)) {
			throw new ValidationException('REASON_REQUIRED', 'reason');
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_damage_reports')
			->set('status', $qb->createNamedParameter($status))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('damage_report', $id, 'status_change', $performedBy, [
			'status' => [$report['status'], $status],
		], $reason);
		// If the report is closed and the vehicle was in maintenance because of it,
		// re-evaluate the vehicle status. Skip if the vehicle has since been
		// decommissioned (§4.2 — decommission is terminal; reactivating it
		// would defeat the cancellation cascade and the audit chain).
		if (in_array($status, [self::STATUS_REPAIRED, self::STATUS_CLOSED_NO_ACTION], true)
			&& !$this->vehicleHasOpenSafetyCriticalDamage((int)$report['vehicle_id'])) {
			$vehicle = $this->vehicles->get((int)$report['vehicle_id']);
			if ($vehicle['status'] !== VehicleService::STATUS_DECOMMISSIONED) {
				$this->vehicles->setStatus((int)$report['vehicle_id'], VehicleService::STATUS_AVAILABLE, $performedBy);
			}
		}
		return $this->get($id);
	}

	/**
	 * §1.10 amendment — a new immutable row referencing the superseded one.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function amend(int $id, array $payload, string $performedBy, string $reason): array
	{
		$reason = trim($reason);
		if (mb_strlen($reason) < 10) {
			throw new ValidationException('AMENDMENT_REASON_TOO_SHORT', 'reason');
		}
		$original = $this->get($id);
		// §1.10 + §2.2 — amendments document a correction in the legally
		// binding record. Only the original reporter (within a short
		// window, enforced by the standard authoring rules) or a fleet
		// admin/manager may file one. Workshop and auditor roles must not.
		$isReporter = ($original['reported_by_user_id'] ?? '') === $performedBy;
		$isManager = $this->access->isFleetAdminOrManager($performedBy);
		if (!$isReporter && !$isManager) {
			throw new ForbiddenException('CANNOT_AMEND_DAMAGE');
		}
		// Build the amendment row by overlaying payload on top of the original.
		$amendmentPayload = array_merge([
			'vehicleId' => $original['vehicle_id'],
			'bookingId' => $original['booking_id'],
			'discoveryDatetime' => $original['discovery_datetime'],
			'description' => $original['description'],
			'zone' => $original['zone'],
			'severity' => $original['severity'],
			'isDriveable' => $original['is_driveable'],
		], $payload);
		$amendment = $this->create($amendmentPayload, $performedBy);
		// Link the amendment to the original.
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_damage_reports')
			->set('amendment_of_report_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			->set('amendment_reason', $qb->createNamedParameter($reason))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($amendment['id'], IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('damage_report', $amendment['id'], 'amend', $performedBy, [
			'amendment_of' => $id,
		], $reason);
		return $this->fullDetail($amendment['id']);
	}

	/** @return array<string,mixed> */
	public function fullDetail(int $id): array
	{
		$report = $this->get($id);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_damage_photos')
			->where($qb->expr()->eq('damage_report_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->orderBy('uploaded_at', 'ASC');
		$res = $qb->executeQuery();
		$photos = [];
		while (($r = $res->fetch()) !== false) {
			$photos[] = [
				'id' => (int)$r['id'],
				'fileId' => (string)$r['file_id'],
				'uploadedBy' => (string)$r['uploaded_by_user_id'],
				'uploadedAt' => (string)$r['uploaded_at'],
			];
		}
		$res->closeCursor();
		$qb2 = $this->db->getQueryBuilder();
		$qb2->select('id', 'status', 'created_at')->from('mc_damage_reports')
			->where($qb2->expr()->eq('amendment_of_report_id', $qb2->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC');
		$res2 = $qb2->executeQuery();
		$amendments = [];
		while (($r = $res2->fetch()) !== false) {
			$amendments[] = [
				'id' => (int)$r['id'],
				'status' => (string)$r['status'],
				'createdAt' => (string)$r['created_at'],
			];
		}
		$res2->closeCursor();
		return $report + ['photos' => $photos, 'amendments' => $amendments];
	}

	public function reportHasPhotos(int $reportId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('mc_damage_photos')
			->where($qb->expr()->eq('damage_report_id', $qb->createNamedParameter($reportId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		return $qb->executeQuery()->fetchOne() !== false;
	}

	public function vehicleHasOpenSafetyCriticalDamage(int $vehicleId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('mc_damage_reports')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('severity', $qb->createNamedParameter(self::SEVERITY_SAFETY_CRITICAL)))
			->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter([self::STATUS_REPAIRED, self::STATUS_CLOSED_NO_ACTION], IQueryBuilder::PARAM_STR_ARRAY)))
			->setMaxResults(1);
		return $qb->executeQuery()->fetchOne() !== false;
	}

	private function driverHasActiveBookingOnVehicle(string $userId, int $vehicleId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('mc_bookings')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('driver_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter([
				BookingService::STATUS_ACTIVE,
				BookingService::STATUS_APPROVED,
				BookingService::STATUS_COMPLETED,
			], IQueryBuilder::PARAM_STR_ARRAY)))
			->setMaxResults(1);
		return $qb->executeQuery()->fetchOne() !== false;
	}

	private function hydrate(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'vehicle_id' => (int)$row['vehicle_id'],
			'booking_id' => $row['booking_id'] !== null ? (int)$row['booking_id'] : null,
			'reported_by_user_id' => (string)$row['reported_by_user_id'],
			'discovery_datetime' => (string)$row['discovery_datetime'],
			'description' => (string)$row['description'],
			'zone' => (string)$row['zone'],
			'severity' => (string)$row['severity'],
			'is_driveable' => (int)$row['is_driveable'] === 1,
			'status' => (string)$row['status'],
			'amendment_of_report_id' => $row['amendment_of_report_id'] !== null ? (int)$row['amendment_of_report_id'] : null,
			'amendment_reason' => $row['amendment_reason'] !== null ? (string)$row['amendment_reason'] : null,
			'created_at' => (string)$row['created_at'],
			'updated_at' => (string)$row['updated_at'],
		];
	}
}
