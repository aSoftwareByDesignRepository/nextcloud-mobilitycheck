<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\BookingConflictException;
use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ServiceMisconfigurationException;
use OCA\MobilityCheck\Exception\ValidationException;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * §4.5 — Booking creation, approval, cancellation, check-in/out.
 *
 * Concurrency model (§1.13, §8, §11.1): all writes that depend on the
 * "no two approved bookings overlap" invariant run inside a single
 * transaction that takes a {@see IQueryBuilder::SELECT FOR UPDATE} lock
 * on the vehicle row. Conflicting INSERTs see the lock and serialise;
 * the loser throws a {@see BookingConflictException} with the winner's
 * id so the UI can deep-link to the existing reservation.
 *
 * Booking eligibility (§4.5, §11.x) consults {@see ComplianceService}
 * for licence verification, current-year instruction, and licence
 * class match before any DB write; eligible drivers + free windows
 * never block on the database, ineligible drivers never reach it.
 */
class BookingService
{
	public const STATUS_PENDING_FLEET = 'pending_fleet';
	public const STATUS_PENDING_LINE_MANAGER = 'pending_line_manager';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_ACTIVE = 'active';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_CANCELLED = 'cancelled';

	/** Stored in `mc_bookings.cancellation_reason` when {@see autoCancelNoShow} runs (§4.5 step 9a). */
	public const CANCELLATION_NO_SHOW = 'NO_SHOW';

	/** §A5.4 — Automation identity for cron-driven state changes (not a Nextcloud user). */
	public const AUTOMATION_ACTOR = '__mc_automation__';

	public const STATUSES = [
		self::STATUS_PENDING_FLEET,
		self::STATUS_PENDING_LINE_MANAGER,
		self::STATUS_APPROVED,
		self::STATUS_REJECTED,
		self::STATUS_ACTIVE,
		self::STATUS_COMPLETED,
		self::STATUS_CANCELLED,
	];

	/** @return list<string> */
	public static function pendingApprovalStatuses(): array
	{
		return [self::STATUS_PENDING_FLEET, self::STATUS_PENDING_LINE_MANAGER];
	}

	public static function isPendingApprovalStatus(string $status): bool
	{
		return str_starts_with($status, 'pending_');
	}

	/** Statuses that block the vehicle window for new bookings. */
	private const BLOCKING_STATUSES = [
		self::STATUS_PENDING_FLEET,
		self::STATUS_PENDING_LINE_MANAGER,
		self::STATUS_APPROVED,
		self::STATUS_ACTIVE,
	];

	private const FUEL_LEVELS = ['empty', 'quarter', 'half', 'three_quarter', 'full'];
	private const ZONES = ['front', 'rear', 'left', 'right', 'roof', 'interior', 'underbody'];
	private const MIN_PURPOSE_LENGTH = 4;
	private const MAX_PURPOSE_LENGTH = 250;
	private const MAX_HANDOVER_LOCATION_LEN = 500;
	/** Minimum trimmed length for mandatory pool/group return location (§4.6). */
	private const MIN_RETURN_LOCATION_LEN = 3;

	public function __construct(
		private IDBConnection $db,
		private VehicleService $vehicles,
		private ComplianceService $compliance,
		private SettingsService $settings,
		private AccessControlService $access,
		private AuditLogService $audit,
		private VehicleAssignmentService $assignments,
		private LogbookService $logbook,
		private LineManagerService $lineManagers,
		private ApprovalChainService $approvalChains,
		private DriverService $drivers,
		private RelocationService $relocations,
		private ?ChargebackService $chargebacks = null,
		private ?AllocationService $allocation = null,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function list(array $filters = []): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_bookings')->orderBy('start_datetime', 'DESC');
		if (!empty($filters['driverUserIds']) && is_array($filters['driverUserIds'])) {
			$ids = array_values(array_filter($filters['driverUserIds'], static fn ($id) => is_string($id) && $id !== ''));
			if ($ids !== []) {
				$qb->andWhere($qb->expr()->in('driver_user_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)));
			}
		} elseif (!empty($filters['driverUserId'])) {
			$qb->andWhere($qb->expr()->eq('driver_user_id', $qb->createNamedParameter((string)$filters['driverUserId'])));
		}
		if (!empty($filters['vehicleId'])) {
			$qb->andWhere($qb->expr()->eq('vehicle_id', $qb->createNamedParameter((int)$filters['vehicleId'], IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filters['status'])) {
			$status = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
			$qb->andWhere($qb->expr()->in('status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_STR_ARRAY)));
		} elseif (!empty($filters['statusLike'])) {
			$qb->andWhere($qb->expr()->like('status', $qb->createNamedParameter((string)$filters['statusLike'])));
		}
		if (!empty($filters['from'])) {
			$qb->andWhere($qb->expr()->gte('end_datetime', $qb->createNamedParameter((string)$filters['from'])));
		}
		if (!empty($filters['to'])) {
			$qb->andWhere($qb->expr()->lte('start_datetime', $qb->createNamedParameter((string)$filters['to'])));
		}
		if (!empty($filters['limit'])) {
			$qb->setMaxResults(min(500, max(1, (int)$filters['limit'])));
		}
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = $this->hydrate($r);
		}
		$res->closeCursor();
		return $this->decorateWithVehicle($out);
	}

	/**
	 * Batch-load the vehicles referenced by a list of bookings and attach
	 * a small read-only summary (`vehicle_internal_name`,
	 * `vehicle_licence_plate`, `vehicle_status`, `vehicle_base_location`,
	 * `vehicle_assignment_mode`, `checkout_requires_business_only_ack`,
	 * `vehicle_odometer_km`) to each row.
	 * uses these fields to render human-friendly bookings tables without
	 * issuing per-row REST calls — a common N+1 trap (§1.13).
	 *
	 * @param list<array<string,mixed>> $bookings
	 * @return list<array<string,mixed>>
	 */
	private function decorateWithVehicle(array $bookings): array
	{
		if ($bookings === []) {
			return $bookings;
		}
		$ids = array_map(static fn ($b) => (int)($b['vehicle_id'] ?? 0), $bookings);
		$summaries = $this->vehicles->summariesByIds($ids);
		foreach ($bookings as &$b) {
			$vid = (int)($b['vehicle_id'] ?? 0);
			$v = $summaries[$vid] ?? null;
			$b['vehicle_internal_name'] = $v['internal_name'] ?? null;
			$b['vehicle_licence_plate'] = $v['licence_plate'] ?? null;
			$b['vehicle_status'] = $v['status'] ?? null;
			$b['vehicle_base_location'] = $v['base_location'] ?? null;
			$b['vehicle_odometer_km'] = isset($v['odometer_km']) ? (int)$v['odometer_km'] : null;
			$a = $this->assignments->getActiveAssignment($vid);
			$b['vehicle_assignment_mode'] = $a['assignment_mode'] ?? null;
			$b['checkout_requires_business_only_ack'] = false;
			if ($a !== null && ($a['tax_treatment'] ?? '') === VehicleAssignmentService::TAX_BUSINESS_ONLY) {
				$b['checkout_requires_business_only_ack'] = true;
			}
		}
		return $bookings;
	}

	public function get(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_bookings')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if (!$row) {
			throw new NotFoundException('BOOKING_NOT_FOUND');
		}
		$decorated = $this->decorateWithVehicle([$this->hydrate($row)]);
		return $decorated[0];
	}

	/**
	 * §4.5c — `proxy_unacknowledged` clears when the driver first opens the
	 * booking detail **or** 24 h after creation, whichever occurs first.
	 *
	 * @param bool $driverOpenedDetailPage Pass true from the booking-detail
	 *        page controller; API `GET /api/bookings/{id}` passes false and
	 *        only applies the 24 h automatic expiry.
	 */
	public function clearProxyUnacknowledgedIfApplicable(int $bookingId, string $viewerId, bool $driverOpenedDetailPage = false): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('driver_user_id', 'proxy_unacknowledged', 'created_at')
			->from('mc_bookings')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if (!$row || (string)$row['driver_user_id'] !== $viewerId) {
			return;
		}
		if ((int)($row['proxy_unacknowledged'] ?? 0) === 0) {
			return;
		}
		$createdTs = strtotime((string)$row['created_at'] . ' UTC');
		$ageExpired = $createdTs !== false && (time() - $createdTs) >= 24 * 3600;
		if (!$driverOpenedDetailPage && !$ageExpired) {
			return;
		}
		$upd = $this->db->getQueryBuilder();
		$upd->update('mc_bookings')
			->set('proxy_unacknowledged', $upd->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('updated_at', $upd->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->where($upd->expr()->eq('id', $upd->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
			->andWhere($upd->expr()->eq('driver_user_id', $upd->createNamedParameter($viewerId)))
			->andWhere($upd->expr()->eq('proxy_unacknowledged', $upd->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		$upd->executeStatement();
	}

	public function assertUserMayViewBooking(array $booking, string $viewerId): void
	{
		if (($booking['driver_user_id'] ?? '') === $viewerId) {
			return;
		}
		if ($this->access->isFleetAdminOrManager($viewerId) || $this->access->isAuditor($viewerId) || $this->access->isAppAdmin($viewerId)) {
			return;
		}
		if ($this->access->isLineManager($viewerId)
			&& $this->lineManagers->isActiveLineManagerForDriver($viewerId, (string)($booking['driver_user_id'] ?? ''))) {
			// Line managers see all bookings for their supervised drivers
			// (not only the pending step) so they can audit decisions
			// over time. Read-only outside `pending_line_manager`.
			return;
		}
		throw new ForbiddenException('INSUFFICIENT_ROLE');
	}

	/**
	 * Auto-cancel an approved booking whose driver did not check out
	 * within the no-show grace period (§4.5a invariant). Returns true
	 * if the booking actually flipped to `cancelled`. Idempotent via
	 * a status check inside the transaction.
	 *
	 * @param int|null $graceMinutes When set, included in the audit payload (human-readable detail is not stored in `cancellation_reason`).
	 */
	public function autoCancelNoShow(int $bookingId, ?int $graceMinutes = null): bool
	{
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'status', 'vehicle_id', 'driver_user_id', 'start_datetime')
				->from('mc_bookings')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
				->setMaxResults(1);
			$row = $qb->executeQuery()->fetch();
			if (!$row || (string)$row['status'] !== self::STATUS_APPROVED) {
				$this->db->commit();
				return false;
			}
			$now = gmdate('Y-m-d H:i:s');
			$upd = $this->db->getQueryBuilder();
			$upd->update('mc_bookings')
				->set('status', $upd->createNamedParameter(self::STATUS_CANCELLED))
				->set('cancellation_reason', $upd->createNamedParameter(self::CANCELLATION_NO_SHOW))
				->set('updated_at', $upd->createNamedParameter($now))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
				->andWhere($upd->expr()->eq('status', $upd->createNamedParameter(self::STATUS_APPROVED)));
			$affected = $upd->executeStatement();
			if ($affected === 0) {
				$this->db->commit();
				return false;
			}
			$auditReason = self::CANCELLATION_NO_SHOW;
			if ($graceMinutes !== null) {
				$auditReason .= ';grace_minutes=' . $graceMinutes;
			}
			$this->audit->log('booking', $bookingId, 'auto_cancel_no_show', '__system__', [], $auditReason);
			$this->maybeFreeVehicleStatus((int)$row['vehicle_id'], '__system__');
			$this->db->commit();
			return true;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Bookings awaiting line-manager action for drivers this user supervises.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listPendingApprovalsForLineManager(string $lineManagerUserId): array
	{
		$rows = $this->list(['status' => self::STATUS_PENDING_LINE_MANAGER, 'limit' => 200]);
		return array_values(array_filter(
			$rows,
			fn ($b) => $this->lineManagers->isActiveLineManagerForDriver($lineManagerUserId, (string)($b['driver_user_id'] ?? '')),
		));
	}

	/**
	 * §4.5 — Create a booking. Driver_user_id is always the current user
	 * unless a manager creates the booking on someone's behalf.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function create(array $payload, string $performedBy): array
	{
		$vehicleId = (int)($payload['vehicleId'] ?? $payload['vehicle_id'] ?? 0);
		$driverUserId = trim((string)($payload['driverUserId'] ?? $payload['driver_user_id'] ?? $performedBy));
		if ($driverUserId === '') {
			throw new ValidationException('DRIVER_REQUIRED', 'driver_user_id');
		}
		if ($driverUserId !== $performedBy && !$this->canFleetOrAppPolicyOnBookings($performedBy)) {
			throw new ForbiddenException('CANNOT_BOOK_FOR_OTHERS');
		}
		$start = $this->parseDateTime((string)($payload['startDatetime'] ?? $payload['start_datetime'] ?? ''), 'start_datetime');
		$end = $this->parseDateTime((string)($payload['endDatetime'] ?? $payload['end_datetime'] ?? ''), 'end_datetime');
		if ($start >= $end) {
			throw new ValidationException('END_BEFORE_START', 'end_datetime');
		}
		$durationSeconds = $end - $start;
		if ($durationSeconds < 15 * 60) {
			throw new ValidationException('BOOKING_TOO_SHORT', 'end_datetime');
		}
		if ($durationSeconds > 90 * 24 * 3600) {
			throw new ValidationException('BOOKING_TOO_LONG', 'end_datetime');
		}
		$purpose = trim((string)($payload['purpose'] ?? ''));
		if (mb_strlen($purpose) < self::MIN_PURPOSE_LENGTH) {
			throw new ValidationException('PURPOSE_TOO_SHORT', 'purpose');
		}
		if (mb_strlen($purpose) > self::MAX_PURPOSE_LENGTH) {
			throw new ValidationException('PURPOSE_TOO_LONG', 'purpose');
		}
		$destination = $this->stringOrNull($payload['destination'] ?? null, 250);
		$costCentre = $this->stringOrNull($payload['costCentre'] ?? $payload['cost_centre'] ?? null, 80);
		$expectedDistanceKm = $payload['expectedDistanceKm'] ?? $payload['expected_distance_km'] ?? null;
		if ($expectedDistanceKm !== null && $expectedDistanceKm !== '') {
			$expectedDistanceKm = (int)$expectedDistanceKm;
			if ($expectedDistanceKm < 0 || $expectedDistanceKm > 1_000_000) {
				throw new ValidationException('DISTANCE_INVALID', 'expected_distance_km');
			}
		} else {
			$expectedDistanceKm = null;
		}
		$passengersStr = $this->stringOrNull($payload['passengers'] ?? null, 500);
		$passengerUserIdsJsonCreate = null;
		if (isset($payload['passengerUserIds']) || isset($payload['passenger_user_ids'])) {
			$raw = $payload['passengerUserIds'] ?? $payload['passenger_user_ids'] ?? null;
			if (is_array($raw)) {
				$uids = array_values(array_filter(array_map('strval', $raw), static fn ($u) => $u !== ''));
				if (count($uids) > 4) {
					throw new ValidationException('PASSENGER_USER_IDS_LIMIT', 'passenger_user_ids');
				}
				$passengerUserIdsJsonCreate = json_encode($uids, JSON_THROW_ON_ERROR);
			}
		}

		// Eligibility — refuses the booking BEFORE we lock the vehicle row
		// so an ineligible driver never holds a database lock.
		$eligibility = $this->compliance->evaluate($driverUserId, $vehicleId);
		if (!$eligibility['eligible']) {
			throw new ValidationException(
				'NOT_ELIGIBLE',
				null,
				['reasons' => $eligibility['reasons']],
			);
		}
		$this->assignments->assertUserMayBookVehicle($driverUserId, $vehicleId);

		$vehicleRow = $this->vehicles->get($vehicleId);
		$startDb = gmdate('Y-m-d H:i:s', $start);
		$endDb = gmdate('Y-m-d H:i:s', $end);
		$st = (string)($vehicleRow['status'] ?? '');
		if ($st === VehicleService::STATUS_IN_MAINTENANCE || $st === VehicleService::STATUS_DECOMMISSIONED) {
			throw new ValidationException('NOT_ELIGIBLE', null, ['reasons' => ['vehicle_unavailable']]);
		}
		if ($this->allocation !== null) {
			if (!$this->allocation->leaseAllowsBookingThrough($vehicleRow, $endDb)) {
				throw new ValidationException('NOT_ELIGIBLE', null, ['reasons' => ['vehicle_lease_end']]);
			}
			if (!$this->allocation->passesMinRemainingLeaseKmBudget($vehicleRow, $startDb)) {
				throw new ValidationException('NOT_ELIGIBLE', null, ['reasons' => ['vehicle_lease_km_headroom']]);
			}
		}
		$crossReasonRaw = (string)($payload['crossStationReason'] ?? $payload['cross_station_reason'] ?? '');
		$this->assertBookingStationPolicy($driverUserId, $vehicleRow, $performedBy, $crossReasonRaw);
		[$pickupSid, $returnSid] = $this->normalizedPickupReturnForPayload($payload, $vehicleRow);
		$this->assertStationIdsExist([$pickupSid, $returnSid]);
		$crossStored = $this->normalizedCrossStationReasonForStorage($crossReasonRaw);

		$now = gmdate('Y-m-d H:i:s');
		$approval = $this->resolveCreateApproval($driverUserId, $performedBy, [
			'vehicleId' => $vehicleId,
			'purpose' => $purpose,
			'costCentre' => $costCentre,
			'expectedDistanceKm' => $expectedDistanceKm,
		]);
		$status = $approval['status'];
		$snapshot = $approval['snapshot'];
		$approvedBy = $approval['approved_by_user_id'];
		$approvedAt = $approval['approved_at'];

		$this->db->beginTransaction();
		try {
			$this->lockVehicleRow($vehicleId);
			$conflict = $this->findOverlappingBooking($vehicleId, $startDb, $endDb, null);
			if ($conflict !== null) {
				throw new BookingConflictException(
					(int)$conflict['id'],
					(string)$conflict['start_datetime'],
					(string)$conflict['end_datetime'],
				);
			}
			$ins = $this->db->getQueryBuilder();
			$ins->insert('mc_bookings')->values([
				'vehicle_id' => $ins->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT),
				'driver_user_id' => $ins->createNamedParameter($driverUserId),
				'start_datetime' => $ins->createNamedParameter($startDb),
				'end_datetime' => $ins->createNamedParameter($endDb),
				'status' => $ins->createNamedParameter($status),
				'approval_mode_snapshot' => $ins->createNamedParameter($snapshot),
				'approval_chain_snapshot_json' => $approval['chain_snapshot'] !== null
					? $ins->createNamedParameter($approval['chain_snapshot'], IQueryBuilder::PARAM_STR)
					: $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'proxy_unacknowledged' => $ins->createNamedParameter(
					($performedBy !== $driverUserId && $this->canFleetOrAppPolicyOnBookings($performedBy)) ? 1 : 0,
					IQueryBuilder::PARAM_INT,
				),
				'is_escalated' => $ins->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'assisted_handover_required' => $ins->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'purpose' => $ins->createNamedParameter($purpose),
				'destination' => $ins->createNamedParameter($destination),
				'cost_centre' => $ins->createNamedParameter($costCentre),
				'expected_distance_km' => $expectedDistanceKm !== null
					? $ins->createNamedParameter($expectedDistanceKm, IQueryBuilder::PARAM_INT)
					: $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'passengers' => $passengersStr !== null
					? $ins->createNamedParameter($passengersStr)
					: $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'passenger_user_ids' => $passengerUserIdsJsonCreate !== null
					? $ins->createNamedParameter($passengerUserIdsJsonCreate, IQueryBuilder::PARAM_STR)
					: $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'approved_by_user_id' => $ins->createNamedParameter($approvedBy),
				'approved_at' => $ins->createNamedParameter($approvedAt),
				'created_by_user_id' => $ins->createNamedParameter($performedBy),
				'created_at' => $ins->createNamedParameter($now),
				'updated_at' => $ins->createNamedParameter($now),
				'pickup_station_id' => $pickupSid !== null
					? $ins->createNamedParameter($pickupSid, IQueryBuilder::PARAM_INT)
					: $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'return_station_id' => $returnSid !== null
					? $ins->createNamedParameter($returnSid, IQueryBuilder::PARAM_INT)
					: $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'cross_station_reason' => $crossStored !== null
					? $ins->createNamedParameter($crossStored)
					: $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			]);
			$ins->executeStatement();
			$id = (int)$this->db->lastInsertId('mc_bookings');
			$this->audit->log('booking', $id, 'create', $performedBy, [
				'vehicle_id' => $vehicleId,
				'driver_user_id' => $driverUserId,
				'status' => $status,
				'start' => $startDb,
				'end' => $endDb,
			]);
			if ($driverUserId !== $performedBy && $this->canFleetOrAppPolicyOnBookings($performedBy)) {
				$this->audit->log('booking', $id, 'BOOKING_CREATED_BY_PROXY', $performedBy, [
					'acting_user_id' => $performedBy,
					'driver_user_id' => $driverUserId,
				]);
			}
			$this->db->commit();
			return $this->get($id);
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	private function assertNoDuplicateApprovedStep(int $bookingId, string $step): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('mc_booking_approvals')
			->where($qb->expr()->eq('booking_id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('step', $qb->createNamedParameter($step)))
			->andWhere($qb->expr()->eq('decision', $qb->createNamedParameter('approved')))
			->setMaxResults(1);
		if ($qb->executeQuery()->fetchOne() !== false) {
			throw new ValidationException('APPROVAL_ALREADY_RECORDED');
		}
	}

	/**
	 * @param array<string,mixed> $booking
	 */
	private function approveCustomChainStep(int $id, array $booking, string $performedBy, string $chainJson): array
	{
		$st = (string)$booking['status'];
		$driverId = (string)$booking['driver_user_id'];
		$cur = $this->approvalChains->currentStepFromSnapshot($chainJson, $st);
		if ($cur === null) {
			throw new ValidationException('BOOKING_NOT_PENDING');
		}
		$isFleet = $this->access->isFleetAdminOrManager($performedBy);
		$canFleetBook = $isFleet || $this->access->isAppAdmin($performedBy);
		$isLm = $this->access->isLineManager($performedBy)
			&& $this->lineManagers->isActiveLineManagerForDriver($performedBy, $driverId);
		if ($isLm && !$isFleet && $performedBy === $driverId && !$this->settings->lineManagerSelfApprovalAllowed()) {
			throw new ForbiddenException('LINE_MANAGER_SELF_APPROVAL_FORBIDDEN');
		}
		$may = $this->approvalChains->userMayActOnChainStep(
			$performedBy,
			$cur['approver'],
			$driverId,
			isset($booking['cost_centre']) ? (string)$booking['cost_centre'] : null,
		);
		if (!$may) {
			if ($canFleetBook && !$isLm && $st === self::STATUS_PENDING_LINE_MANAGER) {
				throw new ForbiddenException('USE_OVERRIDE_LINE_MANAGER');
			}
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
		$this->assertNoDuplicateApprovedStep($id, $cur['step_id']);
		$next = $this->approvalChains->nextStatusAfterApproval($chainJson, $st);
		if ($next === self::STATUS_APPROVED) {
			$this->approveToApprovedState($id, $booking, $performedBy, $cur['step_id'], $this->approverRoleKey($performedBy));
			return $this->get($id);
		}
		$now = gmdate('Y-m-d H:i:s');
		$this->db->beginTransaction();
		try {
			$this->insertBookingApproval($id, $cur['step_id'], 'approved', $performedBy, $this->approverRoleKey($performedBy), null);
			$upd = $this->db->getQueryBuilder();
			$upd->update('mc_bookings')
				->set('status', $upd->createNamedParameter($next))
				->set('updated_at', $upd->createNamedParameter($now))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$upd->executeStatement();
			$this->audit->log('booking', $id, 'approve_chain', $performedBy, ['status' => [$st, $next]]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->get($id);
	}

	/**
	 * @param array<string,mixed> $booking
	 */
	private function rejectCustomChainStep(int $id, array $booking, string $performedBy, string $reason, string $chainJson): array
	{
		$st = (string)$booking['status'];
		$driverId = (string)$booking['driver_user_id'];
		$cur = $this->approvalChains->currentStepFromSnapshot($chainJson, $st);
		if ($cur === null) {
			throw new ValidationException('BOOKING_NOT_PENDING');
		}
		$isFleet = $this->access->isFleetAdminOrManager($performedBy);
		$canFleetBook = $isFleet || $this->access->isAppAdmin($performedBy);
		$isLm = $this->access->isLineManager($performedBy)
			&& $this->lineManagers->isActiveLineManagerForDriver($performedBy, $driverId);
		$may = $this->approvalChains->userMayActOnChainStep(
			$performedBy,
			$cur['approver'],
			$driverId,
			isset($booking['cost_centre']) ? (string)$booking['cost_centre'] : null,
		);
		if (!$may && !$canFleetBook) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
		$reason = trim($reason);
		if ($reason === '') {
			throw new ValidationException('REASON_REQUIRED', 'reason');
		}
		$now = gmdate('Y-m-d H:i:s');
		$this->insertBookingApproval($id, $cur['step_id'], 'rejected', $performedBy, $this->approverRoleKey($performedBy), $reason);
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_bookings')
			->set('status', $qb->createNamedParameter(self::STATUS_REJECTED))
			->set('rejection_reason', $qb->createNamedParameter($reason))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('booking', $id, 'reject_chain', $performedBy, ['status' => [$st, self::STATUS_REJECTED]], $reason);
		return $this->get($id);
	}

	public function approve(int $id, string $performedBy): array
	{
		$booking = $this->get($id);
		$chainJson = $booking['approval_chain_snapshot_json'] ?? null;
		if (is_string($chainJson) && $chainJson !== '') {
			return $this->approveCustomChainStep($id, $booking, $performedBy, $chainJson);
		}
		$driverId = (string)$booking['driver_user_id'];
		$modeSnapshot = (string)($booking['approval_mode_snapshot'] ?? 'fleet_manager');
		$isFleet = $this->access->isFleetAdminOrManager($performedBy);
		$canFleetBook = $isFleet || $this->access->isAppAdmin($performedBy);
		$isLm = $this->access->isLineManager($performedBy)
			&& $this->lineManagers->isActiveLineManagerForDriver($performedBy, $driverId);

		// §4.5a §D.4 — A line manager cannot approve their own booking
		// unless an operator has explicitly opted in. Fleet managers retain
		// override authority (handled below via the dedicated override
		// endpoint or by being the only approver in the chain).
		if ($isLm && !$isFleet && $performedBy === $driverId && !$this->settings->lineManagerSelfApprovalAllowed()) {
			throw new ForbiddenException('LINE_MANAGER_SELF_APPROVAL_FORBIDDEN');
		}

		if ($booking['status'] === self::STATUS_PENDING_LINE_MANAGER) {
			if (!$canFleetBook && !$isLm) {
				throw new ForbiddenException('INSUFFICIENT_ROLE');
			}
			if ($canFleetBook && !$isLm) {
				// §4.5a §C, §13.30 — A fleet manager that is NOT the active LM
				// for the driver must use the dedicated override endpoint so
				// the LM is notified and the override is recorded explicitly.
				throw new ForbiddenException('USE_OVERRIDE_LINE_MANAGER');
			}
			if ($isLm && !$isFleet) {
				if ($modeSnapshot === 'line_manager_then_fleet') {
					$now = gmdate('Y-m-d H:i:s');
					$this->db->beginTransaction();
					try {
						$upd = $this->db->getQueryBuilder();
						$upd->update('mc_bookings')
							->set('status', $upd->createNamedParameter(self::STATUS_PENDING_FLEET))
							->set('updated_at', $upd->createNamedParameter($now))
							->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
						$upd->executeStatement();
						$this->insertBookingApproval(
							$id,
							'line_manager',
							'approved',
							$performedBy,
							AccessControlService::ROLE_LINE_MANAGER,
							null,
						);
						$this->audit->log('booking', $id, 'approve_line_manager', $performedBy, [
							'status' => [self::STATUS_PENDING_LINE_MANAGER, self::STATUS_PENDING_FLEET],
						]);
						$this->db->commit();
					} catch (\Throwable $e) {
						$this->db->rollBack();
						throw $e;
					}
					return $this->get($id);
				}
				$this->approveToApprovedState($id, $booking, $performedBy, 'line_manager', AccessControlService::ROLE_LINE_MANAGER);
				return $this->get($id);
			}
			$this->approveToApprovedState($id, $booking, $performedBy, 'fleet_manager', $this->approverRoleKey($performedBy));
			return $this->get($id);
		}

		if ($booking['status'] === self::STATUS_PENDING_FLEET) {
			if (!$canFleetBook) {
				throw new ForbiddenException('INSUFFICIENT_ROLE');
			}
			$this->approveToApprovedState($id, $booking, $performedBy, 'fleet_manager', $this->approverRoleKey($performedBy));
			return $this->get($id);
		}

		throw new ValidationException('BOOKING_NOT_PENDING');
	}

	public function reject(int $id, string $performedBy, string $reason): array
	{
		$booking = $this->get($id);
		$chainJson = $booking['approval_chain_snapshot_json'] ?? null;
		if (is_string($chainJson) && $chainJson !== '') {
			return $this->rejectCustomChainStep($id, $booking, $performedBy, $reason, $chainJson);
		}
		if (!self::isPendingApprovalStatus((string)$booking['status'])) {
			throw new ValidationException('BOOKING_NOT_PENDING');
		}
		$driverId = (string)$booking['driver_user_id'];
		$isFleet = $this->access->isFleetAdminOrManager($performedBy);
		$canFleetBook = $isFleet || $this->access->isAppAdmin($performedBy);
		$isLm = $this->access->isLineManager($performedBy)
			&& $this->lineManagers->isActiveLineManagerForDriver($performedBy, $driverId);
		$canReject = match ($booking['status']) {
			self::STATUS_PENDING_FLEET => $canFleetBook,
			self::STATUS_PENDING_LINE_MANAGER => $canFleetBook || $isLm,
			default => false,
		};
		if (!$canReject) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
		$reason = trim($reason);
		if ($reason === '') {
			throw new ValidationException('REASON_REQUIRED', 'reason');
		}
		$isApp = $this->access->isAppAdmin($performedBy);
		$useFleetManagerStep = $isFleet
			|| ($isApp && $booking['status'] === self::STATUS_PENDING_FLEET)
			|| ($isApp && $booking['status'] === self::STATUS_PENDING_LINE_MANAGER && !$isLm);
		$step = $useFleetManagerStep ? 'fleet_manager' : 'line_manager';
		$roleKey = $useFleetManagerStep ? $this->approverRoleKey($performedBy) : AccessControlService::ROLE_LINE_MANAGER;
		$now = gmdate('Y-m-d H:i:s');
		$this->insertBookingApproval($id, $step, 'rejected', $performedBy, $roleKey, $reason);
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_bookings')
			->set('status', $qb->createNamedParameter(self::STATUS_REJECTED))
			->set('rejection_reason', $qb->createNamedParameter($reason))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('booking', $id, 'reject', $performedBy, [
			'status' => [$booking['status'], self::STATUS_REJECTED],
		], $reason);
		return $this->get($id);
	}

	public function cancel(int $id, string $performedBy, string $reason): array
	{
		$booking = $this->get($id);
		$isOwner = $booking['driver_user_id'] === $performedBy;
		$isManager = $this->canFleetOrAppPolicyOnBookings($performedBy) || $performedBy === self::AUTOMATION_ACTOR;
		$startTs = strtotime($booking['start_datetime'] . ' UTC');
		$isFuture = $startTs !== false && $startTs > time();
		$isLineSupervisorCancel = !$isOwner && !$isManager
			&& $this->access->isLineManager($performedBy)
			&& $this->lineManagers->isActiveLineManagerForDriver($performedBy, (string)$booking['driver_user_id'])
			&& $isFuture;
		if (!$isOwner && !$isManager && !$isLineSupervisorCancel) {
			throw new ForbiddenException('CANNOT_CANCEL_BOOKING');
		}
		if (in_array($booking['status'], [self::STATUS_CANCELLED, self::STATUS_REJECTED, self::STATUS_COMPLETED], true)) {
			throw new ValidationException('BOOKING_NOT_CANCELLABLE');
		}
		if ($booking['status'] === self::STATUS_ACTIVE && !$isManager) {
			// §11.2 — active booking cancellation is a manager-only emergency override.
			throw new ForbiddenException('CANNOT_CANCEL_ACTIVE_BOOKING');
		}
		// Drivers cancelling their own booking may only cancel future bookings.
		if ($isOwner && !$isManager) {
			$start = strtotime($booking['start_datetime'] . ' UTC');
			if ($start <= time()) {
				throw new ValidationException('BOOKING_ALREADY_STARTED');
			}
		}
		$reason = trim($reason);
		if ($reason === '') {
			throw new ValidationException('REASON_REQUIRED', 'reason');
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_bookings')
			->set('status', $qb->createNamedParameter(self::STATUS_CANCELLED))
			->set('cancellation_reason', $qb->createNamedParameter($reason))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('booking', $id, 'cancel', $performedBy, [
			'status' => [$booking['status'], self::STATUS_CANCELLED],
		], $reason);
		// Release the vehicle when an approved or active reservation is cancelled
		// (§11.2 manager emergency cancel on active checkout).
		if (in_array($booking['status'], [self::STATUS_APPROVED, self::STATUS_ACTIVE], true)) {
			$this->maybeFreeVehicleStatus((int)$booking['vehicle_id'], $performedBy);
		}
		return $this->get($id);
	}

	/**
	 * §A5.4 — Move a future booking to another vehicle without approval reset
	 * (original vehicle unavailable). Fleet managers or automation only.
	 *
	 * @param array<string,mixed> $auditExtras merged into the audit payload
	 * @return array<string,mixed>
	 */
	public function applyFleetReassignment(int $bookingId, int $newVehicleId, string $performedBy, array $auditExtras = []): array
	{
		if ($performedBy !== self::AUTOMATION_ACTOR && !$this->canFleetOrAppPolicyOnBookings($performedBy)) {
			throw new ForbiddenException('CANNOT_REASSIGN_BOOKING_VEHICLE');
		}
		$booking = $this->get($bookingId);
		$driverUserId = (string)$booking['driver_user_id'];
		$oldVehicleId = (int)$booking['vehicle_id'];
		if ($newVehicleId === $oldVehicleId) {
			throw new ValidationException('VEHICLE_UNCHANGED');
		}
		if (!self::isPendingApprovalStatus((string)$booking['status']) && $booking['status'] !== self::STATUS_APPROVED) {
			throw new ValidationException('BOOKING_READ_ONLY');
		}
		$logs = $this->logs($bookingId);
		if (($logs['checkout'] ?? null) !== null) {
			throw new ValidationException('BOOKING_IMMUTABLE_AFTER_CHECKOUT');
		}
		$this->assignments->assertUserMayBookVehicle($driverUserId, $newVehicleId);
		$newVehicle = $this->vehicles->get($newVehicleId);
		$crossReasonEffective = trim((string)($booking['cross_station_reason'] ?? ''));
		$this->assertBookingStationPolicy($driverUserId, $newVehicle, $performedBy, $crossReasonEffective);
		$eligibility = $this->compliance->evaluate($driverUserId, $newVehicleId);
		if (!$eligibility['eligible']) {
			throw new ValidationException('NOT_ELIGIBLE', null, ['reasons' => $eligibility['reasons']]);
		}
		$startDb = (string)$booking['start_datetime'];
		$endDb = (string)$booking['end_datetime'];
		$nvSt = (string)($newVehicle['status'] ?? '');
		if ($nvSt === VehicleService::STATUS_IN_MAINTENANCE || $nvSt === VehicleService::STATUS_DECOMMISSIONED) {
			throw new ValidationException('NOT_ELIGIBLE', null, ['reasons' => ['vehicle_unavailable']]);
		}
		if ($this->allocation !== null) {
			if (!$this->allocation->leaseAllowsBookingThrough($newVehicle, $endDb)) {
				throw new ValidationException('NOT_ELIGIBLE', null, ['reasons' => ['vehicle_lease_end']]);
			}
			if (!$this->allocation->passesMinRemainingLeaseKmBudget($newVehicle, $startDb)) {
				throw new ValidationException('NOT_ELIGIBLE', null, ['reasons' => ['vehicle_lease_km_headroom']]);
			}
		}
		$now = gmdate('Y-m-d H:i:s');
		$this->db->beginTransaction();
		try {
			foreach (array_values(array_unique([$oldVehicleId, $newVehicleId])) as $vid) {
				$this->lockVehicleRow((int)$vid);
			}
			$conflict = $this->findOverlappingBooking($newVehicleId, $startDb, $endDb, $bookingId);
			if ($conflict !== null) {
				throw new BookingConflictException(
					(int)$conflict['id'],
					(string)$conflict['start_datetime'],
					(string)$conflict['end_datetime'],
				);
			}
			$upd = $this->db->getQueryBuilder();
			$upd->update('mc_bookings')
				->set('vehicle_id', $upd->createNamedParameter($newVehicleId, IQueryBuilder::PARAM_INT))
				->set('reassigned_from_vehicle_id', $upd->createNamedParameter($oldVehicleId, IQueryBuilder::PARAM_INT))
				->set('flag_requires_manual_reassignment', $upd->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->set('updated_at', $upd->createNamedParameter($now))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)));
			$upd->executeStatement();
			$this->maybeFreeVehicleStatus($oldVehicleId, $performedBy);
			$this->audit->log('booking', $bookingId, 'fleet_vehicle_reassignment', $performedBy, array_merge([
				'vehicle_id' => [$oldVehicleId, $newVehicleId],
			], $auditExtras));
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->get($bookingId);
	}

	private function supersedeOpenBookingApprovals(int $bookingId, string $reason): void
	{
		// §6.17 — the `is_superseded` flag is the only way the UI can render
		// the historical approval row without confusing it with a still-open
		// step. Swallowing a failure here would leave the booking in a new
		// status while the previous step still appears actionable, which
		// violates the approval audit guarantee. Let the exception propagate
		// so the wrapping transaction rolls back; callers must surface the
		// failure to the operator instead of silently corrupting the trail.
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_booking_approvals')
			->set('is_superseded', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->set('supersede_reason', $qb->createNamedParameter($reason))
			->where($qb->expr()->eq('booking_id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_superseded', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function extractChainIdFromSnapshot(?string $json): ?string
	{
		if ($json === null || $json === '') {
			return null;
		}
		try {
			/** @var array<string,mixed> $s */
			$s = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
			$cid = trim((string)($s['chainId'] ?? ''));
			return $cid !== '' ? $cid : null;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * §4.5b — Re-run approval resolution using the booking's frozen mode and
	 * chain definition id, never the organisation's current global defaults.
	 *
	 * @param array{vehicleId:int,purpose:string,costCentre:?string,expectedDistanceKm:?int} $chainCtx
	 * @return array{status:string,snapshot:string,approved_by_user_id:?string,approved_at:?string,chain_snapshot:?string}
	 */
	private function resolveCreateApprovalFrozen(string $frozenMode, ?string $frozenChainJson, string $driverUserId, string $performedBy, array $chainCtx): array
	{
		$now = gmdate('Y-m-d H:i:s');
		$mode = $frozenMode;
		if ($mode === 'none' || $this->canFleetOrAppPolicyOnBookings($driverUserId)) {
			return [
				'status' => self::STATUS_APPROVED,
				'snapshot' => $mode,
				'approved_by_user_id' => $performedBy,
				'approved_at' => $now,
				'chain_snapshot' => null,
			];
		}
		if ($mode === 'chain') {
			$chainId = $this->extractChainIdFromSnapshot($frozenChainJson);
			if ($chainId === null) {
				throw new ValidationException('APPROVAL_CHAIN_SNAPSHOT_ORPHANED');
			}
			$def = $this->approvalChains->getChainDefinitionById($chainId);
			if ($def === null) {
				throw new ValidationException('APPROVAL_CHAIN_SNAPSHOT_ORPHANED');
			}
			$a = $this->assignments->getActiveAssignment((int)$chainCtx['vehicleId']);
			$ctx = [
				'driverUserId' => $driverUserId,
				'expectedDistanceKm' => (int)($chainCtx['expectedDistanceKm'] ?? 0),
				'purpose' => (string)($chainCtx['purpose'] ?? ''),
				'costCentre' => (string)($chainCtx['costCentre'] ?? ''),
				'vehicleId' => (int)$chainCtx['vehicleId'],
				'assignmentMode' => $a['assignment_mode'] ?? null,
				'taxTreatment' => $a['tax_treatment'] ?? null,
			];
			$r = $this->approvalChains->resolveFromDefinition($def, $ctx);
			return [
				'status' => $r['initialStatus'],
				'snapshot' => 'chain',
				'approved_by_user_id' => null,
				'approved_at' => null,
				'chain_snapshot' => $r['snapshot'],
			];
		}
		$snapshot = $mode;
		if ($mode === 'fleet_manager') {
			return [
				'status' => self::STATUS_PENDING_FLEET,
				'snapshot' => $snapshot,
				'approved_by_user_id' => null,
				'approved_at' => null,
				'chain_snapshot' => null,
			];
		}
		if ($mode === 'line_manager' || $mode === 'line_manager_then_fleet') {
			$lm = $this->lineManagers->getActiveLineManagerUserIdForDriver($driverUserId);
			if ($lm !== null) {
				return [
					'status' => self::STATUS_PENDING_LINE_MANAGER,
					'snapshot' => $snapshot,
					'approved_by_user_id' => null,
					'approved_at' => null,
					'chain_snapshot' => null,
				];
			}
			if (!$this->settings->approvalFallbackWhenNoLineManager()) {
				throw new ValidationException('LINE_MANAGER_REQUIRED_NOT_ASSIGNED');
			}
		}
		return [
			'status' => self::STATUS_PENDING_FLEET,
			'snapshot' => $snapshot,
			'approved_by_user_id' => null,
			'approved_at' => null,
			'chain_snapshot' => null,
		];
	}

	/**
	 * @return array{vehicleId:int,purpose:string,costCentre:?string,expectedDistanceKm:?int}
	 */
	private function bookingChainCtx(int $vehicleId, string $purpose, ?string $costCentre, ?int $expectedDistanceKm): array
	{
		return [
			'vehicleId' => $vehicleId,
			'purpose' => $purpose,
			'costCentre' => $costCentre,
			'expectedDistanceKm' => $expectedDistanceKm,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function approvalBookingContextForChain(int $vehicleId, string $driverUserId, string $purpose, ?string $costCentre, ?int $expectedDistanceKm): array
	{
		$a = $this->assignments->getActiveAssignment($vehicleId);
		return [
			'driverUserId' => $driverUserId,
			'expectedDistanceKm' => (int)($expectedDistanceKm ?? 0),
			'purpose' => $purpose,
			'costCentre' => (string)($costCentre ?? ''),
			'vehicleId' => $vehicleId,
			'assignmentMode' => $a['assignment_mode'] ?? null,
			'taxTreatment' => $a['tax_treatment'] ?? null,
		];
	}

	/**
	 * §4.5b / §7.2 — Update a pending or approved booking before check-out.
	 * Vehicle changes always reset approval; time shifts may reset per policy;
	 * custom chains re-walk when `applies_when` yields a different step list.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function update(int $id, array $payload, string $performedBy): array
	{
		$booking = $this->get($id);
		$isOwner = ($booking['driver_user_id'] ?? '') === $performedBy;
		$isManager = $this->canFleetOrAppPolicyOnBookings($performedBy);
		if (!$isOwner && !$isManager) {
			throw new ForbiddenException('CANNOT_EDIT_BOOKING');
		}
		if (!self::isPendingApprovalStatus((string)$booking['status']) && $booking['status'] !== self::STATUS_APPROVED) {
			throw new ValidationException('BOOKING_READ_ONLY');
		}
		$logs = $this->logs($id);
		if (($logs['checkout'] ?? null) !== null) {
			throw new ValidationException('BOOKING_IMMUTABLE_AFTER_CHECKOUT');
		}
		$oldVehicleId = (int)$booking['vehicle_id'];
		$newVehicleId = (array_key_exists('vehicleId', $payload) || array_key_exists('vehicle_id', $payload))
			? (int)($payload['vehicleId'] ?? $payload['vehicle_id'])
			: $oldVehicleId;
		if ($newVehicleId <= 0) {
			throw new ValidationException('VEHICLE_REQUIRED', 'vehicle_id');
		}
		$driverUserId = (string)$booking['driver_user_id'];
		$start = $this->parseDateTime((string)($payload['startDatetime'] ?? $payload['start_datetime'] ?? $booking['start_datetime']), 'start_datetime');
		$end = $this->parseDateTime((string)($payload['endDatetime'] ?? $payload['end_datetime'] ?? $booking['end_datetime']), 'end_datetime');
		if ($start >= $end) {
			throw new ValidationException('END_BEFORE_START', 'end_datetime');
		}
		$durationSeconds = $end - $start;
		if ($durationSeconds < 15 * 60) {
			throw new ValidationException('BOOKING_TOO_SHORT', 'end_datetime');
		}
		if ($durationSeconds > 90 * 24 * 3600) {
			throw new ValidationException('BOOKING_TOO_LONG', 'end_datetime');
		}
		$purpose = trim((string)($payload['purpose'] ?? $booking['purpose'] ?? ''));
		if (mb_strlen($purpose) < self::MIN_PURPOSE_LENGTH) {
			throw new ValidationException('PURPOSE_TOO_SHORT', 'purpose');
		}
		if (mb_strlen($purpose) > self::MAX_PURPOSE_LENGTH) {
			throw new ValidationException('PURPOSE_TOO_LONG', 'purpose');
		}
		$destination = $this->stringOrNull($payload['destination'] ?? $booking['destination'] ?? null, 250);
		$costCentre = $this->stringOrNull($payload['costCentre'] ?? $payload['cost_centre'] ?? $booking['cost_centre'] ?? null, 80);
		$expectedDistanceKm = $payload['expectedDistanceKm'] ?? $payload['expected_distance_km'] ?? $booking['expected_distance_km'] ?? null;
		if ($expectedDistanceKm !== null && $expectedDistanceKm !== '') {
			$expectedDistanceKm = (int)$expectedDistanceKm;
			if ($expectedDistanceKm < 0 || $expectedDistanceKm > 1_000_000) {
				throw new ValidationException('DISTANCE_INVALID', 'expected_distance_km');
			}
		} else {
			$expectedDistanceKm = null;
		}
		$passengers = $this->stringOrNull($payload['passengers'] ?? $booking['passengers'] ?? null, 500);
		$passengerUserIdsJson = null;
		$payloadHasPassengerUserIds = isset($payload['passengerUserIds']) || isset($payload['passenger_user_ids']);
		if ($payloadHasPassengerUserIds && !$isManager) {
			throw new ForbiddenException('PASSENGER_USER_IDS_MANAGERS_ONLY');
		}
		if ($payloadHasPassengerUserIds && $isManager) {
			$raw = $payload['passengerUserIds'] ?? $payload['passenger_user_ids'] ?? null;
			if (is_array($raw)) {
				$uids = array_values(array_filter(array_map('strval', $raw), static fn ($u) => $u !== ''));
				if (count($uids) > 4) {
					throw new ValidationException('PASSENGER_USER_IDS_LIMIT', 'passenger_user_ids');
				}
				$passengerUserIdsJson = json_encode($uids, JSON_THROW_ON_ERROR);
			}
		} elseif (!empty($booking['passenger_user_ids']) && is_array($booking['passenger_user_ids'])) {
			$passengerUserIdsJson = json_encode($booking['passenger_user_ids'], JSON_THROW_ON_ERROR);
		}

		$vehicleChanged = $newVehicleId !== $oldVehicleId;
		if ($vehicleChanged) {
			if (!$isManager) {
				throw new ForbiddenException('CANNOT_CHANGE_BOOKING_VEHICLE');
			}
			$this->assignments->assertUserMayBookVehicle($driverUserId, $newVehicleId);
		}

		$vehicleForPolicy = $this->vehicles->get($newVehicleId);
		$crossReasonEffective = trim((string)(
			(array_key_exists('crossStationReason', $payload) || array_key_exists('cross_station_reason', $payload))
				? ($payload['crossStationReason'] ?? $payload['cross_station_reason'])
				: ($booking['cross_station_reason'] ?? '')
		));
		$this->assertBookingStationPolicy($driverUserId, $vehicleForPolicy, $performedBy, $crossReasonEffective);
		$pickupPayload = $payload['pickupStationId'] ?? $payload['pickup_station_id'] ?? null;
		if ($pickupPayload === null) {
			$pickupPayload = $booking['pickup_station_id'] ?? null;
		}
		$returnPayload = $payload['returnStationId'] ?? $payload['return_station_id'] ?? null;
		if ($returnPayload === null) {
			$returnPayload = $booking['return_station_id'] ?? null;
		}
		[$pickupSid, $returnSid] = $this->normalizedPickupReturnForPayload([
			'pickup_station_id' => $pickupPayload,
			'return_station_id' => $returnPayload,
		], $vehicleForPolicy);
		$this->assertStationIdsExist([$pickupSid, $returnSid]);
		$crossStored = $this->normalizedCrossStationReasonForStorage($crossReasonEffective);

		$eligibility = $this->compliance->evaluate($driverUserId, $newVehicleId);
		if (!$eligibility['eligible']) {
			throw new ValidationException('NOT_ELIGIBLE', null, ['reasons' => $eligibility['reasons']]);
		}

		$startDb = gmdate('Y-m-d H:i:s', $start);
		$endDb = gmdate('Y-m-d H:i:s', $end);
		$vfSt = (string)($vehicleForPolicy['status'] ?? '');
		if ($vfSt === VehicleService::STATUS_IN_MAINTENANCE || $vfSt === VehicleService::STATUS_DECOMMISSIONED) {
			throw new ValidationException('NOT_ELIGIBLE', null, ['reasons' => ['vehicle_unavailable']]);
		}
		if ($this->allocation !== null) {
			if (!$this->allocation->leaseAllowsBookingThrough($vehicleForPolicy, $endDb)) {
				throw new ValidationException('NOT_ELIGIBLE', null, ['reasons' => ['vehicle_lease_end']]);
			}
			if (!$this->allocation->passesMinRemainingLeaseKmBudget($vehicleForPolicy, $startDb)) {
				throw new ValidationException('NOT_ELIGIBLE', null, ['reasons' => ['vehicle_lease_km_headroom']]);
			}
		}

		$oldStart = strtotime((string)$booking['start_datetime'] . ' UTC') ?: 0;
		$oldEnd = strtotime((string)$booking['end_datetime'] . ' UTC') ?: 0;
		$timeShiftSec = max(abs($start - $oldStart), abs($end - $oldEnd));
		$timeReset = $booking['status'] === self::STATUS_APPROVED
			&& $this->settings->approvalResetsOnTimeChange()
			&& $timeShiftSec > 15 * 60;

		$frozenMode = (string)($booking['approval_mode_snapshot'] ?? 'none');
		$frozenChain = $booking['approval_chain_snapshot_json'] ?? null;
		$isChain = is_string($frozenChain) && $frozenChain !== '' && $frozenMode === 'chain';

		$oldCtx = $this->approvalBookingContextForChain(
			$oldVehicleId,
			$driverUserId,
			(string)($booking['purpose'] ?? ''),
			isset($booking['cost_centre']) ? (string)$booking['cost_centre'] : null,
			isset($booking['expected_distance_km']) ? (int)$booking['expected_distance_km'] : null,
		);
		$newCtx = $this->approvalBookingContextForChain(
			$newVehicleId,
			$driverUserId,
			$purpose,
			$costCentre,
			$expectedDistanceKm,
		);

		$chainSoftRewalk = $booking['status'] === self::STATUS_APPROVED
			&& $isChain
			&& $this->approvalChains->chainWalkDiffersForContext((string)$frozenChain, $oldCtx, $newCtx);

		$chainPendingRewalk = self::isPendingApprovalStatus((string)$booking['status'])
			&& $isChain
			&& $this->approvalChains->chainWalkDiffersForContext((string)$frozenChain, $oldCtx, $newCtx);

		$needApprovalReset = $vehicleChanged
			|| $timeReset
			|| $chainSoftRewalk
			|| $chainPendingRewalk;

		$chainCtx = $this->bookingChainCtx($newVehicleId, $purpose, $costCentre, $expectedDistanceKm);
		$approvalPackage = $needApprovalReset
			? $this->resolveCreateApprovalFrozen($frozenMode, is_string($frozenChain) ? $frozenChain : null, $driverUserId, $performedBy, $chainCtx)
			: null;

		$now = gmdate('Y-m-d H:i:s');

		$vehiclesToLock = array_values(array_unique(array_filter([$oldVehicleId, $newVehicleId])));
		sort($vehiclesToLock);

		$this->db->beginTransaction();
		try {
			foreach ($vehiclesToLock as $vid) {
				$this->lockVehicleRow((int)$vid);
			}
			$conflict = $this->findOverlappingBooking($newVehicleId, $startDb, $endDb, $id);
			if ($conflict !== null) {
				throw new BookingConflictException(
					(int)$conflict['id'],
					(string)$conflict['start_datetime'],
					(string)$conflict['end_datetime'],
				);
			}

			if ($needApprovalReset && ($booking['status'] === self::STATUS_APPROVED || self::isPendingApprovalStatus((string)$booking['status']))) {
				$this->supersedeOpenBookingApprovals($id, 'booking_modification:' . $performedBy);
			}

			$upd = $this->db->getQueryBuilder();
			$upd->update('mc_bookings')
				->set('vehicle_id', $upd->createNamedParameter($newVehicleId, IQueryBuilder::PARAM_INT))
				->set('start_datetime', $upd->createNamedParameter($startDb))
				->set('end_datetime', $upd->createNamedParameter($endDb))
				->set('purpose', $upd->createNamedParameter($purpose))
				->set('destination', $upd->createNamedParameter($destination))
				->set('cost_centre', $upd->createNamedParameter($costCentre))
				->set('expected_distance_km', $expectedDistanceKm !== null
					? $upd->createNamedParameter($expectedDistanceKm, IQueryBuilder::PARAM_INT)
					: $upd->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->set('updated_at', $upd->createNamedParameter($now));
			if ($pickupSid !== null) {
				$upd->set('pickup_station_id', $upd->createNamedParameter($pickupSid, IQueryBuilder::PARAM_INT));
			} else {
				$upd->set('pickup_station_id', $upd->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
			}
			if ($returnSid !== null) {
				$upd->set('return_station_id', $upd->createNamedParameter($returnSid, IQueryBuilder::PARAM_INT));
			} else {
				$upd->set('return_station_id', $upd->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
			}
			$upd->set('cross_station_reason', $crossStored !== null
				? $upd->createNamedParameter($crossStored)
				: $upd->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
			if ($passengers !== null) {
				$upd->set('passengers', $upd->createNamedParameter($passengers));
			} else {
				$upd->set('passengers', $upd->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
			}
			if ($passengerUserIdsJson !== null) {
				$upd->set('passenger_user_ids', $upd->createNamedParameter($passengerUserIdsJson, IQueryBuilder::PARAM_STR));
			}
			if ($approvalPackage !== null) {
				$upd->set('status', $upd->createNamedParameter($approvalPackage['status']))
					->set('approval_chain_snapshot_json', $approvalPackage['chain_snapshot'] !== null
						? $upd->createNamedParameter($approvalPackage['chain_snapshot'], IQueryBuilder::PARAM_STR)
						: $upd->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
					->set('approved_by_user_id', $approvalPackage['approved_by_user_id'] !== null
						? $upd->createNamedParameter($approvalPackage['approved_by_user_id'])
						: $upd->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
					->set('approved_at', $approvalPackage['approved_at'] !== null
						? $upd->createNamedParameter($approvalPackage['approved_at'])
						: $upd->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
			}
			$upd->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$upd->executeStatement();

			if ($vehicleChanged) {
				$this->maybeFreeVehicleStatus($oldVehicleId, $performedBy);
			}

			$this->audit->log('booking', $id, 'update', $performedBy, [
				'start' => [$booking['start_datetime'], $startDb],
				'end' => [$booking['end_datetime'], $endDb],
				'vehicle_id' => [$oldVehicleId, $newVehicleId],
				'approval_reset' => $needApprovalReset,
			]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->get($id);
	}

	/**
	 * §4.6 — Check out a booking. Records odometer, fuel, zone checklist.
	 * Driver must be the booking owner or a manager; vehicle moves to
	 * `in_use`; booking moves to `active`.
	 *
	 * Concurrency: the booking row flips from `approved` to `active` with a
	 * single conditional UPDATE inside the same transaction as the checkout
	 * log and vehicle writes, so two simultaneous requests cannot both succeed.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function checkout(int $id, array $payload, string $performedBy): array
	{
		$booking = $this->get($id);
		$isOwner = $booking['driver_user_id'] === $performedBy;
		$isManager = $this->canFleetOrAppPolicyOnBookings($performedBy);
		if (!$isOwner && !$isManager) {
			throw new ForbiddenException('CHECKOUT_NOT_ALLOWED');
		}
		if ($booking['status'] !== self::STATUS_APPROVED) {
			throw new ValidationException('BOOKING_NOT_APPROVED_FOR_CHECKOUT');
		}
		$vehicle = $this->vehicles->get((int)$booking['vehicle_id']);
		// §4.2 — a decommissioned vehicle is out of service. If the
		// decommission cascade lost a race with this checkout, refuse
		// to put the driver behind the wheel. The booking should already
		// be cancelled; surfacing the explicit error here avoids
		// silently writing an `in_use` status onto a retired vehicle.
		if ($vehicle['status'] === VehicleService::STATUS_DECOMMISSIONED) {
			throw new ValidationException('VEHICLE_DECOMMISSIONED');
		}
		if ($vehicle['status'] === VehicleService::STATUS_IN_MAINTENANCE) {
			throw new ValidationException('VEHICLE_IN_MAINTENANCE');
		}
		$odometerKm = (int)($payload['odometerKm'] ?? $payload['odometer_km'] ?? -1);
		if ($odometerKm < 0) {
			throw new ValidationException('ODOMETER_REQUIRED', 'odometer_km');
		}
		if ($odometerKm < $vehicle['odometer_km']) {
			throw new ValidationException('ODOMETER_REGRESSION', 'odometer_km', [
				'current' => $vehicle['odometer_km'],
				'attempted' => $odometerKm,
			]);
		}
		$fuelLevel = (string)($payload['fuelLevel'] ?? $payload['fuel_level'] ?? '');
		if (!in_array($fuelLevel, self::FUEL_LEVELS, true)) {
			throw new ValidationException('FUEL_LEVEL_INVALID', 'fuel_level');
		}
		$zonesOk = $this->normaliseZones($payload['conditionZonesOk'] ?? $payload['condition_zones_ok'] ?? []);
		$conditionNotes = $this->stringOrNull($payload['conditionNotes'] ?? $payload['condition_notes'] ?? null, 2000);
		$pickupLocationNote = $this->stringOrNull($payload['pickupLocationNote'] ?? $payload['pickup_location_note'] ?? null, self::MAX_HANDOVER_LOCATION_LEN);
		$now = gmdate('Y-m-d H:i:s');

		// §4.6.4 / §13.39 — photo evidence policy at checkout.
		$photoPolicy = $this->vehicles->effectivePhotoPolicy(
			$vehicle,
			$this->settings->globalPhotoEvidenceRequiredAtCheckout(),
			$this->settings->globalPhotoEvidenceRequiredAtCheckin(),
			$this->settings->globalPhotoEvidenceMinimumCount(),
		);
		if ($photoPolicy['atCheckout']) {
			$photoCount = $this->countHandoverPhotos($id, 'pre_trip');
			if ($photoCount < $photoPolicy['minimumCount']) {
				throw new ValidationException('HANDOVER_PHOTOS_REQUIRED', null, [
					'minimum_count' => $photoPolicy['minimumCount'],
					'current_count' => $photoCount,
					'evidence_type' => 'pre_trip',
				]);
			}
		}

		$a = $this->assignments->getActiveAssignment((int)$booking['vehicle_id']);
		$confirmedBiz = null;
		if ($a !== null && ($a['tax_treatment'] ?? '') === VehicleAssignmentService::TAX_BUSINESS_ONLY) {
			$cb = $payload['confirmedBusinessOnly'] ?? $payload['confirmed_business_only'] ?? false;
			if (!filter_var($cb, FILTER_VALIDATE_BOOLEAN)) {
				throw new ValidationException('BUSINESS_ONLY_DECLARATION_REQUIRED');
			}
			$confirmedBiz = 1;
		}

		$this->db->beginTransaction();
		try {
			$this->lockVehicleRow((int)$booking['vehicle_id']);
			$upd = $this->db->getQueryBuilder();
			$upd->update('mc_bookings')
				->set('status', $upd->createNamedParameter(self::STATUS_ACTIVE))
				->set('updated_at', $upd->createNamedParameter($now))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($upd->expr()->eq('status', $upd->createNamedParameter(self::STATUS_APPROVED)));
			if ($upd->executeStatement() !== 1) {
				throw new ValidationException('BOOKING_NOT_APPROVED_FOR_CHECKOUT');
			}

			$log = $this->db->getQueryBuilder();
			$log->insert('mc_checkout_logs')->values([
				'booking_id' => $log->createNamedParameter($id, IQueryBuilder::PARAM_INT),
				'event_type' => $log->createNamedParameter('checkout'),
				'odometer_km' => $log->createNamedParameter($odometerKm, IQueryBuilder::PARAM_INT),
				'fuel_level' => $log->createNamedParameter($fuelLevel),
				'condition_notes' => $log->createNamedParameter($conditionNotes),
				'condition_zones_ok' => $log->createNamedParameter(json_encode($zonesOk, JSON_THROW_ON_ERROR)),
				'pickup_location_note' => $log->createNamedParameter($pickupLocationNote),
				'return_location_note' => $log->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'recorded_by_user_id' => $log->createNamedParameter($performedBy),
				'recorded_at' => $log->createNamedParameter($now),
				'confirmed_business_only' => $confirmedBiz !== null
					? $log->createNamedParameter($confirmedBiz, IQueryBuilder::PARAM_INT)
					: $log->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			]);
			$log->executeStatement();

			$this->vehicles->setStatus((int)$booking['vehicle_id'], VehicleService::STATUS_IN_USE, $performedBy);
			$this->vehicles->setOdometer((int)$booking['vehicle_id'], $odometerKm, $performedBy);

			$this->audit->log('booking', $id, 'checkout', $performedBy, [
				'odometer_km' => $odometerKm,
				'fuel_level' => $fuelLevel,
				'has_pickup_location_note' => $pickupLocationNote !== null,
			]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->get($id);
	}

	/**
	 * §4.6 — Check in a booking. Symmetric to checkout, with odometer
	 * monotonicity vs. the matching checkout row.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function checkin(int $id, array $payload, string $performedBy): array
	{
		$booking = $this->get($id);
		$isOwner = $booking['driver_user_id'] === $performedBy;
		$isManager = $this->canFleetOrAppPolicyOnBookings($performedBy);
		if (!$isOwner && !$isManager) {
			throw new ForbiddenException('CHECKIN_NOT_ALLOWED');
		}
		if ($booking['status'] !== self::STATUS_ACTIVE) {
			throw new ValidationException('BOOKING_NOT_ACTIVE_FOR_CHECKIN');
		}
		$odometerKm = (int)($payload['odometerKm'] ?? $payload['odometer_km'] ?? -1);
		if ($odometerKm < 0) {
			throw new ValidationException('ODOMETER_REQUIRED', 'odometer_km');
		}
		$checkoutLog = $this->fetchCheckoutLog($id);
		if ($checkoutLog === null) {
			throw new ValidationException('CHECKOUT_MISSING');
		}
		if ($odometerKm < (int)$checkoutLog['odometer_km']) {
			throw new ValidationException('ODOMETER_REGRESSION', 'odometer_km', [
				'checkout' => (int)$checkoutLog['odometer_km'],
				'attempted' => $odometerKm,
			]);
		}
		$fuelLevel = (string)($payload['fuelLevel'] ?? $payload['fuel_level'] ?? '');
		if (!in_array($fuelLevel, self::FUEL_LEVELS, true)) {
			throw new ValidationException('FUEL_LEVEL_INVALID', 'fuel_level');
		}
		$zonesOk = $this->normaliseZones($payload['conditionZonesOk'] ?? $payload['condition_zones_ok'] ?? []);
		$conditionNotes = $this->stringOrNull($payload['conditionNotes'] ?? $payload['condition_notes'] ?? null, 2000);
		$returnLocationNote = $this->stringOrNull($payload['returnLocationNote'] ?? $payload['return_location_note'] ?? null, self::MAX_HANDOVER_LOCATION_LEN);
		$assignment = $this->assignments->getActiveAssignment((int)$booking['vehicle_id']);
		$mode = $assignment['assignment_mode'] ?? null;
		$requiresReturnLocation = $mode === VehicleAssignmentService::MODE_POOL
			|| $mode === VehicleAssignmentService::MODE_GROUP;
		if ($requiresReturnLocation) {
			$trimmed = trim((string)($payload['returnLocationNote'] ?? $payload['return_location_note'] ?? ''));
			if (mb_strlen($trimmed) < self::MIN_RETURN_LOCATION_LEN) {
				throw new ValidationException('RETURN_LOCATION_REQUIRED', 'return_location_note');
			}
		}

		// §4.6.4 / §13.39 — photo evidence policy at check-in.
		$vehicle = $this->vehicles->get((int)$booking['vehicle_id']);
		$photoPolicy = $this->vehicles->effectivePhotoPolicy(
			$vehicle,
			$this->settings->globalPhotoEvidenceRequiredAtCheckout(),
			$this->settings->globalPhotoEvidenceRequiredAtCheckin(),
			$this->settings->globalPhotoEvidenceMinimumCount(),
		);
		if ($photoPolicy['atCheckin']) {
			$photoCount = $this->countHandoverPhotos($id, 'post_trip');
			if ($photoCount < $photoPolicy['minimumCount']) {
				throw new ValidationException('HANDOVER_PHOTOS_REQUIRED', null, [
					'minimum_count' => $photoPolicy['minimumCount'],
					'current_count' => $photoCount,
					'evidence_type' => 'post_trip',
				]);
			}
		}

		// §4.6.8 — fuel return-minimum policy. Default per-vehicle; falls back
		// to disabled. When chargeback is enabled, return below minimum is
		// allowed but creates an audited driver chargeback row.
		$fuelMinimum = $vehicle['fuel_minimum_at_return'] ?? null;
		$fuelStepsBelow = 0;
		$createFuelChargeback = false;
		$fuelType = (string)($vehicle['fuel_type'] ?? '');
		if ($fuelType === 'electric') {
			$chargeMin = $vehicle['charge_minimum_at_return_percent'] ?? null;
			if ($chargeMin !== null && (int)$chargeMin > 0) {
				$socMap = ['empty' => 0, 'quarter' => 25, 'half' => 50, 'three_quarter' => 75, 'full' => 100];
				$actualPct = $socMap[$fuelLevel] ?? 0;
				if ($actualPct < (int)$chargeMin) {
					$fuelStepsBelow = (int)max(1, ceil(((int)$chargeMin - $actualPct) / 25));
					if ($this->settings->fuelMinimumChargebackEnabled() && $this->settings->fuelMinimumChargebackRatePerStepMinor() > 0) {
						$createFuelChargeback = true;
					} else {
						throw new ValidationException('FUEL_BELOW_MINIMUM', 'fuel_level', [
							'minimum' => (string)$chargeMin . '% SOC',
							'actual' => (string)$actualPct . '%',
							'steps_below' => $fuelStepsBelow,
						]);
					}
				}
			}
		} elseif ($fuelMinimum !== null && $fuelMinimum !== '') {
			$fuelStepsBelow = ChargebackService::fuelStepsBelow((string)$fuelMinimum, $fuelLevel);
			if ($fuelStepsBelow > 0) {
				if ($this->settings->fuelMinimumChargebackEnabled() && $this->settings->fuelMinimumChargebackRatePerStepMinor() > 0) {
					$createFuelChargeback = true;
				} else {
					throw new ValidationException('FUEL_BELOW_MINIMUM', 'fuel_level', [
						'minimum' => $fuelMinimum,
						'actual' => $fuelLevel,
						'steps_below' => $fuelStepsBelow,
					]);
				}
			}
		}

		$now = gmdate('Y-m-d H:i:s');

		$this->db->beginTransaction();
		try {
			$this->lockVehicleRow((int)$booking['vehicle_id']);
			$upd = $this->db->getQueryBuilder();
			$upd->update('mc_bookings')
				->set('status', $upd->createNamedParameter(self::STATUS_COMPLETED))
				->set('updated_at', $upd->createNamedParameter($now))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($upd->expr()->eq('status', $upd->createNamedParameter(self::STATUS_ACTIVE)));
			if ($upd->executeStatement() !== 1) {
				throw new ValidationException('BOOKING_NOT_ACTIVE_FOR_CHECKIN');
			}

			$log = $this->db->getQueryBuilder();
			$log->insert('mc_checkout_logs')->values([
				'booking_id' => $log->createNamedParameter($id, IQueryBuilder::PARAM_INT),
				'event_type' => $log->createNamedParameter('checkin'),
				'odometer_km' => $log->createNamedParameter($odometerKm, IQueryBuilder::PARAM_INT),
				'fuel_level' => $log->createNamedParameter($fuelLevel),
				'condition_notes' => $log->createNamedParameter($conditionNotes),
				'condition_zones_ok' => $log->createNamedParameter(json_encode($zonesOk, JSON_THROW_ON_ERROR)),
				'pickup_location_note' => $log->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'return_location_note' => $log->createNamedParameter($returnLocationNote),
				'recorded_by_user_id' => $log->createNamedParameter($performedBy),
				'recorded_at' => $log->createNamedParameter($now),
			]);
			$log->executeStatement();

			$this->vehicles->setOdometer((int)$booking['vehicle_id'], $odometerKm, $performedBy);
			// Vehicle goes back to available unless another constraint blocks it.
			$this->maybeFreeVehicleStatus((int)$booking['vehicle_id'], $performedBy);

			$this->audit->log('booking', $id, 'checkin', $performedBy, [
				'odometer_km' => $odometerKm,
				'fuel_level' => $fuelLevel,
				'distance_km' => $odometerKm - (int)$checkoutLog['odometer_km'],
				'has_return_location_note' => $returnLocationNote !== null,
				'fuel_steps_below_minimum' => $fuelStepsBelow,
				'fuel_chargeback_created' => $createFuelChargeback,
			]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->relocations->ensureOpenTaskAfterCheckin(
			$id,
			(int)$booking['vehicle_id'],
			$this->effectivePickupStationForRelocation($booking, $vehicle),
			$this->effectiveReturnStationForRelocation($booking, $vehicle),
			$performedBy,
		);
		// §4.6.8 — fire the chargeback row outside the booking transaction so
		// the check-in itself is the canonical event; failures in the
		// chargeback step never roll back the trip closure. §13.36 mandates
		// audit integrity for every auto-chargeback path; if the row cannot
		// be created we still record the *intent* so an operator can
		// reconcile manually.
		if ($createFuelChargeback && $this->chargebacks !== null) {
			$minLabel = ($fuelType === 'electric' && isset($vehicle['charge_minimum_at_return_percent']) && (int)$vehicle['charge_minimum_at_return_percent'] > 0)
				? '>=' . (int)$vehicle['charge_minimum_at_return_percent'] . '% SOC'
				: (string)($fuelMinimum ?? '');
			try {
				$this->chargebacks->createFuelChargeback([
					'vehicleId' => (int)$booking['vehicle_id'],
					'bookingId' => $id,
					'driverUserId' => (string)$booking['driver_user_id'],
					'stepsBelow' => $fuelStepsBelow,
					'actorUserId' => $performedBy,
					'minimum' => $minLabel,
					'actual' => $fuelLevel,
				]);
			} catch (\Throwable $e) {
				try {
					$this->audit->log('booking', $id, 'fuel_chargeback_failed', $performedBy, [
						'driver_user_id' => (string)$booking['driver_user_id'],
						'vehicle_id' => (int)$booking['vehicle_id'],
						'fuel_steps_below_minimum' => $fuelStepsBelow,
						'minimum' => $minLabel,
						'actual' => $fuelLevel,
						'error' => substr($e->getMessage(), 0, 200),
					]);
				} catch (\Throwable) {
					// last-resort: audit failure cannot itself block the trip closure.
				}
			}
		}
		try {
			$this->logbook->createDraftFromCompletedBooking($id, $performedBy);
		} catch (\Throwable) {
			// Draft creation is best-effort — booking completion stays authoritative.
		}
		return $this->get($id);
	}

	/**
	 * §4.6.4 — count distinct stored handover photos for a booking at the
	 * given evidence type (`pre_trip` or `post_trip`). Reuses
	 * `mc_damage_photos` (the schema accommodates photo evidence rows
	 * without a damage report).
	 */
	private function countHandoverPhotos(int $bookingId, string $evidenceType): int
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->selectAlias($qb->func()->count('*'), 'c')
				->from('mc_damage_photos')
				->where($qb->expr()->eq('booking_id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('evidence_type', $qb->createNamedParameter($evidenceType)));
			return (int)($qb->executeQuery()->fetchOne() ?: 0);
		} catch (\Throwable) {
			return 0;
		}
	}

	/**
	 * @return array{checkout:?array<string,mixed>,checkin:?array<string,mixed>}
	 */
	public function logs(int $bookingId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_checkout_logs')
			->where($qb->expr()->eq('booking_id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
			->orderBy('recorded_at', 'ASC');
		$res = $qb->executeQuery();
		$out = ['checkout' => null, 'checkin' => null];
		while (($r = $res->fetch()) !== false) {
			$row = [
				'id' => (int)$r['id'],
				'eventType' => (string)$r['event_type'],
				'odometerKm' => (int)$r['odometer_km'],
				'fuelLevel' => (string)$r['fuel_level'],
				'conditionNotes' => $r['condition_notes'] !== null ? (string)$r['condition_notes'] : null,
				'pickupLocationNote' => isset($r['pickup_location_note']) && $r['pickup_location_note'] !== null
					? (string)$r['pickup_location_note'] : null,
				'returnLocationNote' => isset($r['return_location_note']) && $r['return_location_note'] !== null
					? (string)$r['return_location_note'] : null,
				'conditionZonesOk' => $r['condition_zones_ok'] !== null ? json_decode((string)$r['condition_zones_ok'], true) : [],
				'recordedBy' => (string)$r['recorded_by_user_id'],
				'recordedAt' => (string)$r['recorded_at'],
			];
			if ($r['event_type'] === 'checkout') {
				$out['checkout'] = $row;
			} elseif ($r['event_type'] === 'checkin') {
				$out['checkin'] = $row;
			}
		}
		$res->closeCursor();
		return $out;
	}

	/**
	 * Read-only comparison of scheduled `end_datetime` vs wall-clock / check-in.
	 * All timestamps are stored and interpreted as UTC (same convention as
	 * {@see cancel()} and the overdue background job).
	 *
	 * @param array<string,mixed> $booking Hydrated booking row
	 * @param array{checkout:?array<string,mixed>,checkin:?array<string,mixed>} $logs
	 * @return array{
	 *   overdue_return_grace_minutes:int,
	 *   scheduled_end_utc:string,
	 *   actual_return_utc:?string,
	 *   checkin_vs_end_minutes:?int,
	 *   active_past_scheduled_end:bool,
	 *   active_minutes_past_end:?int,
	 *   fleet_alert_eligible:bool,
	 *   completed_missing_checkin_log:bool
	 * }
	 */
	public function returnScheduleInsights(array $booking, array $logs): array
	{
		$grace = $this->settings->overdueReturnGraceMinutes();
		$endStr = (string)($booking['end_datetime'] ?? '');
		$endTs = $endStr !== '' ? strtotime($endStr . ' UTC') : false;
		$status = (string)($booking['status'] ?? '');
		$out = [
			'overdue_return_grace_minutes' => $grace,
			'scheduled_end_utc' => $endStr,
			'actual_return_utc' => null,
			'checkin_vs_end_minutes' => null,
			'active_past_scheduled_end' => false,
			'active_minutes_past_end' => null,
			'fleet_alert_eligible' => false,
			'completed_missing_checkin_log' => false,
		];
		if ($endTs === false) {
			return $out;
		}
		$checkin = $logs['checkin'] ?? null;
		if ($status === self::STATUS_COMPLETED) {
			if (!is_array($checkin) || ($checkin['recordedAt'] ?? '') === '') {
				$out['completed_missing_checkin_log'] = true;
				return $out;
			}
			$ciTs = strtotime((string)$checkin['recordedAt'] . ' UTC');
			if ($ciTs === false) {
				return $out;
			}
			$out['actual_return_utc'] = (string)$checkin['recordedAt'];
			$out['checkin_vs_end_minutes'] = (int)floor(($ciTs - $endTs) / 60);
			return $out;
		}
		if ($status === self::STATUS_ACTIVE) {
			$now = time();
			if ($now > $endTs) {
				$out['active_past_scheduled_end'] = true;
				$past = (int)floor(($now - $endTs) / 60);
				$out['active_minutes_past_end'] = $past;
				$out['fleet_alert_eligible'] = $grace >= 0 && $past >= $grace;
			}
		}
		return $out;
	}

	/**
	 * §4.5 §T3.8 — Extend an active booking. Only the owner (or a manager
	 * acting on their behalf) may extend; the new end must (a) fall before
	 * the configured cap from the current end, (b) not collide with any
	 * other booking on the same vehicle, and (c) the booking must still
	 * be `active` (extension before check-out uses {@see update()}).
	 *
	 * @param array{newEndDatetime?:string,new_end_datetime?:string,reason?:string} $payload
	 */
	public function extend(int $id, array $payload, string $performedBy): array
	{
		$booking = $this->get($id);
		$isOwner = ($booking['driver_user_id'] ?? '') === $performedBy;
		$isManager = $this->canFleetOrAppPolicyOnBookings($performedBy);
		if (!$isOwner && !$isManager) {
			throw new ForbiddenException('CANNOT_EXTEND_BOOKING');
		}
		if ($booking['status'] !== self::STATUS_ACTIVE) {
			throw new ValidationException('BOOKING_NOT_ACTIVE_FOR_EXTEND');
		}
		$newEnd = $this->parseDateTime((string)($payload['newEndDatetime'] ?? $payload['new_end_datetime'] ?? ''), 'new_end_datetime');
		$currentEnd = strtotime($booking['end_datetime'] . ' UTC');
		if ($newEnd <= $currentEnd) {
			throw new ValidationException('EXTEND_NOT_LATER', 'new_end_datetime');
		}
		$cap = $this->settings->bookingExtensionMaxMinutes();
		if ($cap > 0 && ($newEnd - $currentEnd) > $cap * 60) {
			throw new ValidationException('EXTEND_EXCEEDS_CAP', 'new_end_datetime', [
				'maxMinutes' => $cap,
			]);
		}
		$reason = trim((string)($payload['reason'] ?? ''));
		if ($reason === '') {
			throw new ValidationException('REASON_REQUIRED', 'reason');
		}
		if (mb_strlen($reason) > 500) {
			throw new ValidationException('REASON_TOO_LONG', 'reason');
		}
		$newEndDb = gmdate('Y-m-d H:i:s', $newEnd);
		$now = gmdate('Y-m-d H:i:s');
		$this->db->beginTransaction();
		try {
			$this->lockVehicleRow((int)$booking['vehicle_id']);
			$conflict = $this->findOverlappingBooking(
				(int)$booking['vehicle_id'],
				$booking['end_datetime'],
				$newEndDb,
				$id,
			);
			if ($conflict !== null) {
				throw new BookingConflictException(
					(int)$conflict['id'],
					(string)$conflict['start_datetime'],
					(string)$conflict['end_datetime'],
				);
			}
			$upd = $this->db->getQueryBuilder();
			$upd->update('mc_bookings')
				->set('end_datetime', $upd->createNamedParameter($newEndDb))
				->set('updated_at', $upd->createNamedParameter($now))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$upd->executeStatement();
			$this->audit->log('booking', $id, 'extend', $performedBy, [
				'end_datetime' => [$booking['end_datetime'], $newEndDb],
				'reason' => $reason,
			], $reason);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->get($id);
	}

	/**
	 * §4.5a §C / §13.30 — Fleet manager dedicated override of the
	 * line-manager approval step. The booking moves directly to the
	 * appropriate next state (approved if mode = `line_manager`, or
	 * `pending_fleet` if mode = `line_manager_then_fleet`). Records a
	 * `fleet_override` decision row, sets `is_escalated = 1`, and the
	 * caller can fan out a notification to the bypassed LM (handled
	 * by the controller so the booking row + approval row commit
	 * atomically before any side effects fire).
	 */
	public function overrideLineManager(int $id, string $performedBy, string $reason): array
	{
		$this->access->requireFleetAdminOrManagerOrAppAdmin($performedBy);
		$booking = $this->get($id);
		if ($booking['status'] !== self::STATUS_PENDING_LINE_MANAGER) {
			throw new ValidationException('BOOKING_NOT_PENDING_LINE_MANAGER');
		}
		$reason = trim($reason);
		if ($reason === '') {
			throw new ValidationException('REASON_REQUIRED', 'reason');
		}
		if (mb_strlen($reason) < 10) {
			throw new ValidationException('OVERRIDE_REASON_TOO_SHORT', 'reason', ['min' => 10]);
		}
		if (mb_strlen($reason) > 500) {
			throw new ValidationException('REASON_TOO_LONG', 'reason');
		}
		$modeSnapshot = (string)($booking['approval_mode_snapshot'] ?? 'fleet_manager');
		$nextStatus = $modeSnapshot === 'line_manager_then_fleet'
			? self::STATUS_PENDING_FLEET
			: self::STATUS_APPROVED;
		$now = gmdate('Y-m-d H:i:s');
		$this->db->beginTransaction();
		try {
			if ($nextStatus === self::STATUS_APPROVED) {
				$this->lockVehicleRow((int)$booking['vehicle_id']);
				$conflict = $this->findOverlappingBooking(
					(int)$booking['vehicle_id'],
					$booking['start_datetime'],
					$booking['end_datetime'],
					$id,
				);
				if ($conflict !== null) {
					throw new BookingConflictException(
						(int)$conflict['id'],
						(string)$conflict['start_datetime'],
						(string)$conflict['end_datetime'],
					);
				}
			}
			$upd = $this->db->getQueryBuilder();
			$upd->update('mc_bookings')
				->set('status', $upd->createNamedParameter($nextStatus))
				->set('is_escalated', $upd->createNamedParameter(1, IQueryBuilder::PARAM_INT))
				->set('updated_at', $upd->createNamedParameter($now));
			if ($nextStatus === self::STATUS_APPROVED) {
				$upd->set('approved_by_user_id', $upd->createNamedParameter($performedBy));
				$upd->set('approved_at', $upd->createNamedParameter($now));
			}
			$upd->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$upd->executeStatement();
			$this->insertBookingApproval(
				$id,
				'line_manager',
				'fleet_override',
				$performedBy,
				'fleet_override',
				$reason,
			);
			$this->audit->log('booking', $id, 'override_line_manager', $performedBy, [
				'status' => [(string)$booking['status'], $nextStatus],
			], $reason);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->get($id);
	}

	/**
	 * §4.5a §D.5 — Reassign a `pending_line_manager` booking to a new
	 * line manager (e.g. the assigned LM goes on holiday). The booking
	 * stays in `pending_line_manager`; the {@see LineManagerService}
	 * holds the actual driver→LM relationship, so the reassignment is
	 * effected by replacing the active LM assignment for the driver.
	 * This method enforces the integrity invariants and writes the
	 * audit trail; controllers wire in the notification fan-out.
	 *
	 * Caller must be a fleet manager / admin. The reason is mandatory.
	 */
	public function reassignLineManager(int $id, string $newLineManagerUserId, string $reason, string $performedBy): array
	{
		$this->access->requireFleetAdminOrManager($performedBy);
		$booking = $this->get($id);
		if ($booking['status'] !== self::STATUS_PENDING_LINE_MANAGER) {
			throw new ValidationException('BOOKING_NOT_PENDING_LINE_MANAGER');
		}
		$newLm = trim($newLineManagerUserId);
		if ($newLm === '') {
			throw new ValidationException('LINE_MANAGER_REQUIRED', 'lineManagerUserId');
		}
		if ($newLm === ($booking['driver_user_id'] ?? '')) {
			throw new ValidationException('LINE_MANAGER_SELF');
		}
		$reason = trim($reason);
		if ($reason === '') {
			throw new ValidationException('REASON_REQUIRED', 'reason');
		}
		$driverId = (string)$booking['driver_user_id'];
		$today = gmdate('Y-m-d');
		$now = gmdate('Y-m-d H:i:s');
		$this->db->beginTransaction();
		try {
			// Close any currently active LM assignment for this driver,
			// then create a fresh one pointing at the new LM. The
			// LineManagerService also enforces the "no self-supervision"
			// rule, but we double-check above for early failure.
			$closeQb = $this->db->getQueryBuilder();
			$closeQb->update('mc_line_manager_assignments')
				->set('valid_until', $closeQb->createNamedParameter($today))
				->where($closeQb->expr()->eq('driver_user_id', $closeQb->createNamedParameter($driverId)))
				->andWhere($closeQb->expr()->lte('valid_from', $closeQb->createNamedParameter($today)))
				->andWhere($closeQb->expr()->orX(
					$closeQb->expr()->isNull('valid_until'),
					$closeQb->expr()->gte('valid_until', $closeQb->createNamedParameter($today)),
				));
			$closeQb->executeStatement();

			$insQb = $this->db->getQueryBuilder();
			$insQb->insert('mc_line_manager_assignments')->values([
				'driver_user_id' => $insQb->createNamedParameter($driverId),
				'line_manager_user_id' => $insQb->createNamedParameter($newLm),
				'valid_from' => $insQb->createNamedParameter($today),
				'valid_until' => $insQb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'notes' => $insQb->createNamedParameter('Reassigned for booking #' . $id . ': ' . $reason),
				'created_by_user_id' => $insQb->createNamedParameter($performedBy),
				'created_at' => $insQb->createNamedParameter($now),
			]);
			$insQb->executeStatement();

			$this->audit->log('booking', $id, 'reassign_line_manager', $performedBy, [
				'new_line_manager_user_id' => $newLm,
				'driver_user_id' => $driverId,
			], $reason);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->get($id);
	}

	/**
	 * §7.2 — Approval audit trail for a single booking. Returns the
	 * append-only chronological list of approval decisions; visible to
	 * fleet managers, the driver themselves, and any LM that ever
	 * supervised this driver (via assertion already on the booking).
	 *
	 * @return list<array{step:string,decision:string,approverUserId:string,approverRole:string,decidedAt:string,reason:?string}>
	 */
	public function listApprovals(int $bookingId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('step', 'decision', 'approver_user_id', 'approver_role', 'decided_at', 'reason')
			->from('mc_booking_approvals')
			->where($qb->expr()->eq('booking_id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
			->orderBy('decided_at', 'ASC')
			->addOrderBy('id', 'ASC');
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = [
				'step' => (string)$r['step'],
				'decision' => (string)$r['decision'],
				'approverUserId' => (string)$r['approver_user_id'],
				'approverRole' => (string)$r['approver_role'],
				'decidedAt' => (string)$r['decided_at'],
				'reason' => $r['reason'] !== null ? (string)$r['reason'] : null,
			];
		}
		$res->closeCursor();
		return $out;
	}

	/**
	 * §7.2 — "My pending approvals" — what a line manager or fleet
	 * manager has waiting in their personal queue. Fleet managers see
	 * the full `pending_fleet` queue and any `pending_line_manager`
	 * row that has been auto-escalated to the fleet pool; line
	 * managers see only `pending_line_manager` rows for drivers they
	 * actively supervise.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listMyApprovals(string $userId): array
	{
		$out = [];
		$seen = [];
		if ($this->access->isFleetAdminOrManager($userId) || $this->access->isAppAdmin($userId)) {
			$pendingChain = $this->list([
				'statusLike' => 'pending%',
				'limit' => 200,
			]);
			foreach ($pendingChain as $row) {
				$bid = (int)($row['id'] ?? 0);
				if ($bid <= 0 || isset($seen[$bid])) {
					continue;
				}
				$st = (string)($row['status'] ?? '');
				$chain = $row['approval_chain_snapshot_json'] ?? null;
				if (is_string($chain) && $chain !== '') {
					$cur = $this->approvalChains->currentStepFromSnapshot($chain, $st);
					if ($cur !== null) {
						$may = $this->approvalChains->userMayActOnChainStep(
							$userId,
							$cur['approver'],
							(string)($row['driver_user_id'] ?? ''),
							isset($row['cost_centre']) ? (string)$row['cost_centre'] : null,
						);
						if ($may) {
							$out[] = $row;
							$seen[$bid] = true;
							continue;
						}
					}
				}
				if ($st === self::STATUS_PENDING_FLEET) {
					$out[] = $row;
					$seen[$bid] = true;
				} elseif ($st === self::STATUS_PENDING_LINE_MANAGER && (int)($row['is_escalated'] ?? 0) === 1) {
					$out[] = $row;
					$seen[$bid] = true;
				}
			}
		}
		if ($this->access->isLineManager($userId)) {
			$rows = $this->listPendingApprovalsForLineManager($userId);
			foreach ($rows as $row) {
				$bid = (int)($row['id'] ?? 0);
				if (!isset($seen[$bid])) {
					$out[] = $row;
					$seen[$bid] = true;
				}
			}
		}
		usort($out, static fn ($a, $b) => strcmp((string)($a['start_datetime'] ?? ''), (string)($b['start_datetime'] ?? '')));
		return $out;
	}

	/**
	 * §4.5 reschedule options — returns vehicles that could replace the
	 * given booking's vehicle for the same window. Filters by driver
	 * eligibility (licence class), free window, maintenance, and active
	 * status. Sorted by closest match (same fuel type / transmission
	 * first).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function rescheduleOptions(int $id, string $viewerId): array
	{
		$booking = $this->get($id);
		$this->assertUserMayViewBooking($booking, $viewerId);
		$start = $booking['start_datetime'];
		$end = $booking['end_datetime'];
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_vehicles')
			->where($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('status', $qb->createNamedParameter(VehicleService::STATUS_DECOMMISSIONED)))
			->andWhere($qb->expr()->neq('id', $qb->createNamedParameter((int)$booking['vehicle_id'], IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$candidates = [];
		while (($r = $res->fetch()) !== false) {
			$candidates[] = $r;
		}
		$res->closeCursor();
		$current = $this->vehicles->get((int)$booking['vehicle_id']);
		$out = [];
		foreach ($candidates as $row) {
			$elig = $this->compliance->evaluate($booking['driver_user_id'], (int)$row['id']);
			if (!$elig['eligible']) {
				continue;
			}
			if ($this->findOverlappingBooking((int)$row['id'], $start, $end, null) !== null) {
				continue;
			}
			$score = 0;
			if ($row['fuel_type'] === $current['fuel_type']) {
				$score += 2;
			}
			if ($row['transmission'] === $current['transmission']) {
				$score += 1;
			}
			if ((int)$row['seating_capacity'] >= (int)$current['seating_capacity']) {
				$score += 1;
			}
			$out[] = [
				'vehicleId' => (int)$row['id'],
				'internalName' => (string)$row['internal_name'],
				'make' => (string)$row['make'],
				'model' => (string)$row['model'],
				'licencePlate' => (string)$row['licence_plate'],
				'fuelType' => (string)$row['fuel_type'],
				'transmission' => (string)$row['transmission'],
				'seatingCapacity' => (int)$row['seating_capacity'],
				'score' => $score,
			];
		}
		usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);
		return $out;
	}

	public function maybeFreeVehicleStatus(int $vehicleId, string $performedBy): void
	{
		// §4.2 — a decommissioned vehicle is terminal; do not let the
		// post-checkin logic try to "free" it back to available / in_use
		// (which would also throw at the VehicleService boundary).
		$vehicleRow = $this->vehicles->get($vehicleId);
		if ($vehicleRow['status'] === VehicleService::STATUS_DECOMMISSIONED) {
			return;
		}
		// A vehicle already in maintenance must never be auto-freed by check-in/cancel logic.
		// It is reactivated via explicit operational action (e.g. repair completion / schedule update).
		if ($vehicleRow['status'] === VehicleService::STATUS_IN_MAINTENANCE) {
			return;
		}
		// Only relax to available if there's no other approved/active booking
		// in progress and no blocking maintenance.
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('mc_bookings')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_ACTIVE)))
			->setMaxResults(1);
		$hasActive = $qb->executeQuery()->fetchOne();
		if ($hasActive !== false) {
			$this->vehicles->setStatus($vehicleId, VehicleService::STATUS_IN_USE, $performedBy);
			return;
		}
		if ($this->compliance->hasBlockingMaintenance($vehicleId)) {
			$this->vehicles->setStatus($vehicleId, VehicleService::STATUS_IN_MAINTENANCE, $performedBy);
			return;
		}
		$this->vehicles->setStatus($vehicleId, VehicleService::STATUS_AVAILABLE, $performedBy);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function findOverlappingBooking(int $vehicleId, string $start, string $end, ?int $excludeBookingId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'start_datetime', 'end_datetime', 'status')
			->from('mc_bookings')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_APPROVED)),
				$qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_ACTIVE)),
				$qb->expr()->like('status', $qb->createNamedParameter('pending%')),
			))
			// Half-open: A.end > B.start AND A.start < B.end → overlap
			->andWhere($qb->expr()->lt('start_datetime', $qb->createNamedParameter($end)))
			->andWhere($qb->expr()->gt('end_datetime', $qb->createNamedParameter($start)))
			->setMaxResults(1);
		if ($excludeBookingId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeBookingId, IQueryBuilder::PARAM_INT)));
		}
		$row = $qb->executeQuery()->fetch();
		return $row ?: null;
	}

	private function lockVehicleRow(int $vehicleId): void
	{
		// SELECT … FOR UPDATE serialises booking writers on MySQL, MariaDB,
		// and PostgreSQL for the duration of the surrounding transaction.
		// SQLite does not implement row-level FOR UPDATE; overlap protection
		// there relies on DEFERRED/IMMEDIATE transaction semantics plus the
		// overlap re-check before commit (see unit tests).
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('mc_vehicles')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
		if ($this->db->getDatabasePlatform() instanceof SqlitePlatform) {
			$qb->executeQuery()->closeCursor();
			return;
		}
		$sql = $qb->getSQL();
		$stmt = $this->db->prepare($sql . ' FOR UPDATE');
		foreach ($qb->getParameters() as $name => $value) {
			$stmt->bindValue($name, $value);
		}
		$stmt->execute();
		$stmt->closeCursor();
	}

	private function fetchCheckoutLog(int $bookingId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_checkout_logs')
			->where($qb->expr()->eq('booking_id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('event_type', $qb->createNamedParameter('checkout')))
			->orderBy('recorded_at', 'DESC')
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		return $row ?: null;
	}

	private function parseDateTime(string $value, string $field): int
	{
		$value = trim($value);
		if ($value === '') {
			throw new ValidationException('DATETIME_REQUIRED', $field);
		}
		// Accept three on-the-wire formats:
		//   1. HTML datetime-local         "2026-05-15T09:00"            (local wall-clock; treated as UTC)
		//   2. ISO 8601 with Z suffix      "2026-05-15T07:00:00Z"        (what the JS client sends)
		//   3. ISO 8601 with TZ offset     "2026-05-15T09:00:00+02:00"   (third-party integrators)
		// Everything else is rejected so a malformed datetime cannot be silently coerced.
		if (!preg_match(
			'/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?$/',
			$value,
		)) {
			throw new ValidationException('DATETIME_INVALID', $field);
		}
		try {
			$dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
		} catch (\Throwable) {
			throw new ValidationException('DATETIME_INVALID', $field);
		}
		return $dt->getTimestamp();
	}

	private function stringOrNull(mixed $v, int $maxLen): ?string
	{
		if ($v === null) return null;
		$s = trim((string)$v);
		if ($s === '') return null;
		if (mb_strlen($s) > $maxLen) {
			$s = mb_substr($s, 0, $maxLen);
		}
		return $s;
	}

	/**
	 * @param mixed $raw
	 * @return array<string,bool>
	 */
	private function normaliseZones($raw): array
	{
		$out = [];
		foreach (self::ZONES as $zone) {
			$out[$zone] = false;
		}
		if (is_array($raw)) {
			foreach ($raw as $zone => $value) {
				if (is_string($zone) && in_array($zone, self::ZONES, true)) {
					$out[$zone] = (bool)$value;
				} elseif (is_int($zone) && is_string($value) && in_array($value, self::ZONES, true)) {
					$out[$value] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * @param array{vehicleId:int,purpose:string,costCentre:?string,expectedDistanceKm:?int} $chainCtx
	 * @return array{status:string,snapshot:string,approved_by_user_id:?string,approved_at:?string,chain_snapshot:?string}
	 */
	private function resolveCreateApproval(string $driverUserId, string $performedBy, array $chainCtx): array
	{
		$now = gmdate('Y-m-d H:i:s');
		$mode = $this->settings->approvalMode();
		if ($mode === 'none' || $this->canFleetOrAppPolicyOnBookings($driverUserId)) {
			return [
				'status' => self::STATUS_APPROVED,
				'snapshot' => $mode,
				'approved_by_user_id' => $performedBy,
				'approved_at' => $now,
				'chain_snapshot' => null,
			];
		}
		if ($mode === 'chain') {
			$a = $this->assignments->getActiveAssignment((int)$chainCtx['vehicleId']);
			$ctx = [
				'driverUserId' => $driverUserId,
				'expectedDistanceKm' => (int)($chainCtx['expectedDistanceKm'] ?? 0),
				'purpose' => (string)($chainCtx['purpose'] ?? ''),
				'costCentre' => (string)($chainCtx['costCentre'] ?? ''),
				'vehicleId' => (int)$chainCtx['vehicleId'],
				'assignmentMode' => $a['assignment_mode'] ?? null,
				'taxTreatment' => $a['tax_treatment'] ?? null,
			];
			$r = $this->approvalChains->resolveInitialForCreate($ctx);
			return [
				'status' => $r['initialStatus'],
				'snapshot' => 'chain',
				'approved_by_user_id' => null,
				'approved_at' => null,
				'chain_snapshot' => $r['snapshot'],
			];
		}
		$snapshot = $mode;
		if ($mode === 'fleet_manager') {
			return [
				'status' => self::STATUS_PENDING_FLEET,
				'snapshot' => $snapshot,
				'approved_by_user_id' => null,
				'approved_at' => null,
				'chain_snapshot' => null,
			];
		}
		if ($mode === 'line_manager' || $mode === 'line_manager_then_fleet') {
			$lm = $this->lineManagers->getActiveLineManagerUserIdForDriver($driverUserId);
			if ($lm !== null) {
				return [
					'status' => self::STATUS_PENDING_LINE_MANAGER,
					'snapshot' => $snapshot,
					'approved_by_user_id' => null,
					'approved_at' => null,
					'chain_snapshot' => null,
				];
			}
			if (!$this->settings->approvalFallbackWhenNoLineManager()) {
				throw new ValidationException('LINE_MANAGER_REQUIRED_NOT_ASSIGNED');
			}
		}
		return [
			'status' => self::STATUS_PENDING_FLEET,
			'snapshot' => $snapshot,
			'approved_by_user_id' => null,
			'approved_at' => null,
			'chain_snapshot' => null,
		];
	}

	private function approverRoleKey(string $userId): string
	{
		if ($this->access->isFleetAdmin($userId)) {
			return AccessControlService::ROLE_FLEET_ADMIN;
		}
		if ($this->access->isFleetManager($userId)) {
			return AccessControlService::ROLE_FLEET_MANAGER;
		}
		if ($this->access->isAppAdmin($userId)) {
			return 'app_admin';
		}
		if ($this->access->isLineManager($userId)) {
			return AccessControlService::ROLE_LINE_MANAGER;
		}
		return 'user';
	}

	private function insertBookingApproval(int $bookingId, string $step, string $decision, string $performedBy, string $approverRole, ?string $reason): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert('mc_booking_approvals')->values([
			'booking_id' => $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT),
			'step' => $qb->createNamedParameter($step),
			'decision' => $qb->createNamedParameter($decision),
			'approver_user_id' => $qb->createNamedParameter($performedBy),
			'approver_role' => $qb->createNamedParameter($approverRole),
			'decided_at' => $qb->createNamedParameter(gmdate('Y-m-d H:i:s')),
			'reason' => $reason !== null
				? $qb->createNamedParameter($reason)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
		]);
		$qb->executeStatement();
	}

	/**
	 * @param array<string,mixed> $booking hydrated booking row
	 */
	private function approveToApprovedState(int $id, array $booking, string $performedBy, string $step, string $approverRole): void
	{
		$now = gmdate('Y-m-d H:i:s');
		$this->db->beginTransaction();
		try {
			$this->lockVehicleRow((int)$booking['vehicle_id']);
			$vehicle = $this->vehicles->get((int)$booking['vehicle_id']);
			if ($vehicle['status'] === VehicleService::STATUS_DECOMMISSIONED) {
				throw new ValidationException('VEHICLE_DECOMMISSIONED');
			}
			if ($vehicle['status'] === VehicleService::STATUS_IN_MAINTENANCE) {
				throw new ValidationException('VEHICLE_IN_MAINTENANCE');
			}
			$conflict = $this->findOverlappingBooking(
				(int)$booking['vehicle_id'],
				$booking['start_datetime'],
				$booking['end_datetime'],
				$id,
			);
			if ($conflict !== null) {
				throw new BookingConflictException(
					(int)$conflict['id'],
					(string)$conflict['start_datetime'],
					(string)$conflict['end_datetime'],
				);
			}
			$upd = $this->db->getQueryBuilder();
			$upd->update('mc_bookings')
				->set('status', $upd->createNamedParameter(self::STATUS_APPROVED))
				->set('approved_by_user_id', $upd->createNamedParameter($performedBy))
				->set('approved_at', $upd->createNamedParameter($now))
				->set('updated_at', $upd->createNamedParameter($now))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$upd->executeStatement();
			$this->insertBookingApproval($id, $step, 'approved', $performedBy, $approverRole, null);
			$this->audit->log('booking', $id, 'approve', $performedBy, [
				'status' => [(string)$booking['status'], self::STATUS_APPROVED],
			]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * §2.1 / §2.2 — Fleet business roles plus delegated MobilityCheck app
	 * administrators (policy operators) for booking mutations that the
	 * matrix grants to “fleet admin” class actors.
	 */
	private function canFleetOrAppPolicyOnBookings(string $userId): bool
	{
		return $this->access->isFleetAdminOrManager($userId) || $this->access->isAppAdmin($userId);
	}

	private function optionalPositiveInt(mixed $v): ?int
	{
		if ($v === null || $v === '') {
			return null;
		}
		$i = (int)$v;
		return $i > 0 ? $i : null;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $vehicle {@see VehicleService::get()}
	 * @return array{0:?int,1:?int}
	 */
	private function normalizedPickupReturnForPayload(array $payload, array $vehicle): array
	{
		$vehSt = isset($vehicle['station_id']) && $vehicle['station_id'] !== null ? (int)$vehicle['station_id'] : null;
		$pickup = $this->optionalPositiveInt($payload['pickupStationId'] ?? $payload['pickup_station_id'] ?? null);
		if ($pickup === null) {
			$pickup = $vehSt;
		}
		$ret = $this->optionalPositiveInt($payload['returnStationId'] ?? $payload['return_station_id'] ?? null);
		if ($ret === null) {
			$ret = $pickup;
		}
		return [$pickup, $ret];
	}

	/** @param list<?int> $ids */
	private function assertStationIdsExist(array $ids): void
	{
		foreach ($ids as $id) {
			if ($id === null) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->selectAlias($qb->func()->count('*'), 'c')->from('mc_stations')
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
			if ((int)($qb->executeQuery()->fetchOne() ?: 0) === 0) {
				throw new ValidationException('STATION_NOT_FOUND', 'station_id');
			}
		}
	}

	private function normalizedCrossStationReasonForStorage(string $crossReason): ?string
	{
		$t = trim($crossReason);
		if (mb_strlen($t) < 10) {
			return null;
		}
		return $t;
	}

	/**
	 * §4.2a.2 — Station strict mode for bookings (drivers vs home station).
	 *
	 * @param array<string,mixed> $vehicle {@see VehicleService::get()}
	 */
	private function assertBookingStationPolicy(string $driverUserId, array $vehicle, string $performedBy, string $crossReason): void
	{
		if (!$this->settings->stationStrictMode()) {
			return;
		}
		$profile = $this->drivers->getByUserId($driverUserId);
		$home = ($profile !== null && isset($profile['home_station_id']) && $profile['home_station_id'] !== null)
			? (int)$profile['home_station_id']
			: null;
		$vehSt = isset($vehicle['station_id']) && $vehicle['station_id'] !== null ? (int)$vehicle['station_id'] : null;
		if ($home === null || $vehSt === null) {
			return;
		}
		if ($vehSt === $home) {
			return;
		}
		if ($this->canFleetOrAppPolicyOnBookings($performedBy) && $performedBy === $driverUserId) {
			return;
		}
		if (!$this->canFleetOrAppPolicyOnBookings($performedBy) && $performedBy !== self::AUTOMATION_ACTOR) {
			throw new ValidationException('STATION_NOT_PERMITTED');
		}
		if (mb_strlen(trim($crossReason)) < 10) {
			throw new ValidationException('CROSS_STATION_REASON_REQUIRED', 'cross_station_reason');
		}
	}

	/** @param array<string,mixed> $booking */
	private function effectivePickupStationForRelocation(array $booking, array $vehicle): ?int
	{
		if (isset($booking['pickup_station_id']) && $booking['pickup_station_id'] !== null) {
			return (int)$booking['pickup_station_id'];
		}
		if (isset($vehicle['station_id']) && $vehicle['station_id'] !== null) {
			return (int)$vehicle['station_id'];
		}
		return null;
	}

	/** @param array<string,mixed> $booking */
	private function effectiveReturnStationForRelocation(array $booking, array $vehicle): ?int
	{
		if (isset($booking['return_station_id']) && $booking['return_station_id'] !== null) {
			return (int)$booking['return_station_id'];
		}
		return $this->effectivePickupStationForRelocation($booking, $vehicle);
	}

	private function hydratePassengerUserIds(mixed $raw): ?array
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		if (is_array($raw)) {
			return $raw;
		}
		if (!is_string($raw)) {
			return null;
		}
		try {
			$j = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
			return is_array($j) ? $j : null;
		} catch (\Throwable) {
			return null;
		}
	}

	private function hydrate(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'vehicle_id' => (int)$row['vehicle_id'],
			'driver_user_id' => (string)$row['driver_user_id'],
			'start_datetime' => (string)$row['start_datetime'],
			'end_datetime' => (string)$row['end_datetime'],
			'status' => (string)$row['status'],
			'approval_mode_snapshot' => (string)($row['approval_mode_snapshot'] ?? 'none'),
			'approval_chain_snapshot_json' => $row['approval_chain_snapshot_json'] !== null ? (string)$row['approval_chain_snapshot_json'] : null,
			'proxy_unacknowledged' => isset($row['proxy_unacknowledged']) ? (int)$row['proxy_unacknowledged'] : 0,
			'assisted_handover_required' => isset($row['assisted_handover_required']) ? (int)$row['assisted_handover_required'] : 0,
			'purpose' => $row['purpose'] !== null ? (string)$row['purpose'] : null,
			'destination' => $row['destination'] !== null ? (string)$row['destination'] : null,
			'cost_centre' => $row['cost_centre'] !== null ? (string)$row['cost_centre'] : null,
			'expected_distance_km' => $row['expected_distance_km'] !== null ? (int)$row['expected_distance_km'] : null,
			'passengers' => isset($row['passengers']) && $row['passengers'] !== null ? (string)$row['passengers'] : null,
			'passenger_user_ids' => $this->hydratePassengerUserIds($row['passenger_user_ids'] ?? null),
			'rejection_reason' => $row['rejection_reason'] !== null ? (string)$row['rejection_reason'] : null,
			'cancellation_reason' => $row['cancellation_reason'] !== null ? (string)$row['cancellation_reason'] : null,
			'auto_rescheduled_from_booking_id' => $row['auto_rescheduled_from_booking_id'] !== null ? (int)$row['auto_rescheduled_from_booking_id'] : null,
			'approved_by_user_id' => $row['approved_by_user_id'] !== null ? (string)$row['approved_by_user_id'] : null,
			'approved_at' => $row['approved_at'] !== null ? (string)$row['approved_at'] : null,
			'created_by_user_id' => (string)$row['created_by_user_id'],
			'created_at' => (string)$row['created_at'],
			'updated_at' => (string)$row['updated_at'],
			'pickup_station_id' => isset($row['pickup_station_id']) && $row['pickup_station_id'] !== null ? (int)$row['pickup_station_id'] : null,
			'return_station_id' => isset($row['return_station_id']) && $row['return_station_id'] !== null ? (int)$row['return_station_id'] : null,
			'cross_station_reason' => isset($row['cross_station_reason']) && $row['cross_station_reason'] !== null ? (string)$row['cross_station_reason'] : null,
			'reassigned_from_vehicle_id' => isset($row['reassigned_from_vehicle_id']) && $row['reassigned_from_vehicle_id'] !== null ? (int)$row['reassigned_from_vehicle_id'] : null,
			'flag_requires_manual_reassignment' => isset($row['flag_requires_manual_reassignment']) ? (int)$row['flag_requires_manual_reassignment'] : 0,
		];
	}
}
