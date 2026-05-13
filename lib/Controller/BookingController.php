<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\BookingService;
use OCA\MobilityCheck\Service\LineManagerService;
use OCA\MobilityCheck\Service\NotificationService;
use OCA\MobilityCheck\Service\VehicleService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;

class BookingController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private BookingService $bookings,
		private LineManagerService $lineManagers,
		private NotificationService $notifications,
		private VehicleService $vehicles,
		private IUserManager $userManager,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function list(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			$filters = [
				'vehicleId' => (int)($this->request->getParam('vehicleId', 0) ?? 0),
				'driverUserId' => (string)($this->request->getParam('driverUserId', '') ?? ''),
				'status' => (string)($this->request->getParam('status', '') ?? ''),
				'from' => (string)($this->request->getParam('from', '') ?? ''),
				'to' => (string)($this->request->getParam('to', '') ?? ''),
			];
			if ($this->access->isFleetAdminOrManager($userId) || $this->access->isAuditor($userId)) {
				// full list — optional query filters only
			} elseif ($this->access->isLineManager($userId)) {
				$allowed = array_values(array_unique(array_merge(
					$this->lineManagers->listSupervisedDriverUserIds($userId),
					[$userId]
				)));
				if ($filters['driverUserId'] !== '' && !in_array($filters['driverUserId'], $allowed, true)) {
					throw new ForbiddenException('INSUFFICIENT_ROLE');
				}
				if ($filters['driverUserId'] === '') {
					$filters['driverUserIds'] = $allowed;
					$filters['driverUserId'] = '';
				}
			} elseif ($filters['driverUserId'] === '') {
				$filters['driverUserId'] = $userId;
			}
			return $this->bookings->list($filters);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$booking = $this->bookings->get($id);
			$this->bookings->assertUserMayViewBooking($booking, $userId);
			$this->bookings->clearProxyUnacknowledgedIfApplicable($id, $userId, false);
			$booking = $this->bookings->get($id);
			$booking['logs'] = $this->bookings->logs($id);
			$booking['return_schedule'] = $this->bookings->returnScheduleInsights($booking, $booking['logs']);
			return $booking;
		});
	}

	#[NoAdminRequired]
	public function update(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			$before = $this->bookings->get($id);
			$updated = $this->bookings->update($id, $this->payload(), $userId);
			$this->notifyAfterBookingUpdate($before, $updated, $userId);
			return $updated;
		});
	}

	#[NoAdminRequired]
	public function create(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			if (!$this->access->isFleetAdminOrManager($userId) && !$this->access->isAppAdmin($userId)) {
				$this->access->requireDriver($userId);
			}
			$payload = $this->payload();
			$behalf = trim((string)($payload['onBehalfOf'] ?? $payload['on_behalf_of'] ?? ''));
			if ($behalf !== '') {
				$payload['driverUserId'] = $behalf;
			}
			if (!$this->access->isFleetAdminOrManager($userId) && !$this->access->isAppAdmin($userId)) {
				$payload['driverUserId'] = $userId;
			}
			$created = $this->bookings->create($payload, $userId);
			$this->notifyAfterBookingCreate($created);
			return $created;
		});
	}

	#[NoAdminRequired]
	public function approve(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			$before = $this->bookings->get($id);
			$after = $this->bookings->approve($id, $userId);
			$this->notifyAfterBookingApprove($before, $after);
			return $after;
		});
	}

	#[NoAdminRequired]
	public function reject(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			$reason = (string)($this->request->getParam('reason', '') ?? '');
			$after = $this->bookings->reject($id, $userId, $reason);
			$this->notifyAfterBookingReject($after, $reason);
			return $after;
		});
	}

	#[NoAdminRequired]
	public function cancel(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			$reason = (string)($this->request->getParam('reason', '') ?? '');
			$before = $this->bookings->get($id);
			$after = $this->bookings->cancel($id, $userId, $reason);
			$this->notifyAfterBookingCancel($before, $after, $userId, $reason);
			return $after;
		});
	}

	#[NoAdminRequired]
	public function checkout(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireBookingHandoverApiAccess($userId);
			$after = $this->bookings->checkout($id, $this->payload(), $userId);
			$this->notifyAfterBookingCheckout($after, $userId);
			return $after;
		});
	}

	#[NoAdminRequired]
	public function checkin(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireBookingHandoverApiAccess($userId);
			$after = $this->bookings->checkin($id, $this->payload(), $userId);
			$this->notifyAfterBookingCheckin($after, $userId);
			return $after;
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function rescheduleOptions(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireDriver($userId);
			$booking = $this->bookings->get($id);
			$this->bookings->assertUserMayViewBooking($booking, $userId);
			return $this->bookings->rescheduleOptions($id, $userId);
		});
	}

	#[NoAdminRequired]
	public function extend(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			$before = $this->bookings->get($id);
			$updated = $this->bookings->extend($id, $this->payload(), $userId);
			$vehicleName = $this->vehicleName((int)$before['vehicle_id']);
			$context = [
				'bookingId' => $id,
				'vehicleId' => (int)$before['vehicle_id'],
				'vehicleName' => $vehicleName,
				'start' => (string)($before['start_datetime'] ?? ''),
				'newEnd' => (string)($updated['end_datetime'] ?? ''),
				'oldEnd' => (string)($before['end_datetime'] ?? ''),
				'reason' => (string)($this->payload()['reason'] ?? ''),
				'driverName' => $this->displayName((string)($before['driver_user_id'] ?? '')),
				'purpose' => (string)($before['purpose'] ?? ''),
			];
			// Notify the driver if a manager extended on their behalf so
			// the audit trail and inbox stay in sync (§4.10).
			$driverUserId = (string)($before['driver_user_id'] ?? '');
			if ($driverUserId !== '' && $driverUserId !== $userId) {
				$this->notifications->send(
					NotificationService::TYPE_BOOKING_EXTENDED,
					$driverUserId,
					'booking',
					$id,
					sprintf('booking_extended:%d:%s', $id, (string)$updated['end_datetime']),
					$context,
				);
			}
			return $updated;
		});
	}

	#[NoAdminRequired]
	public function overrideLineManager(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireFleetAdminOrManagerOrAppAdmin($userId);
			$before = $this->bookings->get($id);
			$p = $this->payload();
			$reason = trim((string)($p['reason'] ?? $this->request->getParam('reason', '') ?? ''));
			$updated = $this->bookings->overrideLineManager($id, $userId, $reason);
			$driverId = (string)($before['driver_user_id'] ?? '');
			$lmId = $this->lineManagers->getActiveLineManagerUserIdForDriver($driverId);
			$vehicleName = $this->vehicleName((int)$before['vehicle_id']);
			$context = [
				'bookingId' => $id,
				'vehicleId' => (int)$before['vehicle_id'],
				'vehicleName' => $vehicleName,
				'driverName' => $this->displayName($driverId),
				'start' => (string)($before['start_datetime'] ?? ''),
				'end' => (string)($before['end_datetime'] ?? ''),
				'reason' => $reason,
			];
			if ($lmId !== null && $lmId !== '' && $lmId !== $userId) {
				$this->notifications->send(
					NotificationService::TYPE_APPROVAL_LINE_MANAGER_OVERRIDDEN,
					$lmId,
					'booking',
					$id,
					sprintf('approval.line_manager_overridden:%d', $id),
					$context,
				);
			}
			return $updated;
		});
	}

	#[NoAdminRequired]
	public function reassignLineManager(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireFleetAdminOrManager($userId);
			$payload = $this->payload();
			$newLm = (string)($payload['lineManagerUserId'] ?? $payload['line_manager_user_id'] ?? '');
			$reason = (string)($payload['reason'] ?? '');
			$before = $this->bookings->get($id);
			$updated = $this->bookings->reassignLineManager($id, $newLm, $reason, $userId);
			$vehicleName = $this->vehicleName((int)$before['vehicle_id']);
			$context = [
				'bookingId' => $id,
				'vehicleId' => (int)$before['vehicle_id'],
				'vehicleName' => $vehicleName,
				'driverName' => $this->displayName((string)($before['driver_user_id'] ?? '')),
				'start' => (string)($before['start_datetime'] ?? ''),
				'end' => (string)($before['end_datetime'] ?? ''),
				'reason' => $reason,
			];
			if ($newLm !== '' && $newLm !== $userId) {
				$this->notifications->send(
					NotificationService::TYPE_APPROVAL_LINE_MANAGER_REASSIGNED,
					$newLm,
					'booking',
					$id,
					sprintf('approval.line_manager_reassigned:%d:%s', $id, $newLm),
					$context,
				);
			}
			return $updated;
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function approvals(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$booking = $this->bookings->get($id);
			$this->bookings->assertUserMayViewBooking($booking, $userId);
			return $this->bookings->listApprovals($id);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function myApprovals(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			return $this->bookings->listMyApprovals($userId);
		});
	}

	/**
	 * @param array<string,mixed> $booking
	 * @return array<string, mixed>
	 */
	private function bookingNotificationContext(array $booking): array
	{
		$vid = (int)($booking['vehicle_id'] ?? 0);
		$driverId = (string)($booking['driver_user_id'] ?? '');
		return [
			'bookingId' => (int)($booking['id'] ?? 0),
			'vehicleId' => $vid,
			'vehicleName' => $this->vehicleName($vid),
			'driverName' => $this->displayName($driverId),
			'start' => (string)($booking['start_datetime'] ?? ''),
			'end' => (string)($booking['end_datetime'] ?? ''),
			'purpose' => (string)($booking['purpose'] ?? ''),
		];
	}

	/** @param array<string,mixed> $b */
	private function scheduleFingerprint(array $b): string
	{
		$raw = (string)($b['start_datetime'] ?? '') . '|' . (string)($b['end_datetime'] ?? '') . '|' . (string)($b['purpose'] ?? '');
		return substr(hash('sha256', $raw), 0, 16);
	}

	private function notifyAfterBookingCreate(array $booking): void
	{
		$id = (int)($booking['id'] ?? 0);
		if ($id <= 0) {
			return;
		}
		$ctx = $this->bookingNotificationContext($booking);
		$st = (string)($booking['status'] ?? '');
		if ($st === BookingService::STATUS_APPROVED) {
			$driver = (string)($booking['driver_user_id'] ?? '');
			if ($driver !== '') {
				$this->notifications->send(
					NotificationService::TYPE_BOOKING_APPROVED,
					$driver,
					'booking',
					$id,
					'booking.approved:' . $id,
					$ctx,
				);
			}
		} elseif ($st === BookingService::STATUS_PENDING_FLEET) {
			$this->notifications->sendMany(
				NotificationService::TYPE_BOOKING_REQUESTED,
				$this->access->fleetManagerRecipients(),
				'booking',
				$id,
				'booking.requested:{userId}:' . $id . ':fleet',
				$ctx,
			);
		} elseif ($st === BookingService::STATUS_PENDING_LINE_MANAGER) {
			$lm = $this->lineManagers->getActiveLineManagerUserIdForDriver((string)($booking['driver_user_id'] ?? ''));
			if ($lm !== null && $lm !== '') {
				$this->notifications->send(
					NotificationService::TYPE_BOOKING_REQUESTED,
					$lm,
					'booking',
					$id,
					'booking.requested:' . $id . ':lm',
					$ctx,
				);
			}
		}

		$createdBy = (string)($booking['created_by_user_id'] ?? '');
		$driver = (string)($booking['driver_user_id'] ?? '');
		if ($createdBy !== '' && $driver !== '' && $createdBy !== $driver) {
			$ctxP = $ctx + ['createdBy' => $this->displayName($createdBy)];
			$this->notifications->send(
				NotificationService::TYPE_BOOKING_PROXY_CREATED,
				$driver,
				'booking',
				$id,
				'booking.proxy_created:' . $id,
				$ctxP,
			);
		}
	}

	/** @param array<string,mixed> $before */
	private function notifyAfterBookingApprove(array $before, array $after): void
	{
		$id = (int)($after['id'] ?? 0);
		if ($id <= 0) {
			return;
		}
		$beforeSt = (string)($before['status'] ?? '');
		$afterSt = (string)($after['status'] ?? '');
		$ctx = $this->bookingNotificationContext($after);
		if ($afterSt === BookingService::STATUS_PENDING_FLEET && $beforeSt === BookingService::STATUS_PENDING_LINE_MANAGER) {
			$this->notifications->sendMany(
				NotificationService::TYPE_BOOKING_REQUESTED,
				$this->access->fleetManagerRecipients(),
				'booking',
				$id,
				'booking.requested:{userId}:' . $id . ':fleet_escalation',
				$ctx,
			);
			return;
		}
		if ($afterSt === BookingService::STATUS_APPROVED) {
			$driver = (string)($after['driver_user_id'] ?? '');
			if ($driver !== '') {
				$this->notifications->send(
					NotificationService::TYPE_BOOKING_APPROVED,
					$driver,
					'booking',
					$id,
					'booking.approved:' . $id,
					$ctx,
				);
			}
		}
	}

	/** @param array<string,mixed> $after */
	private function notifyAfterBookingReject(array $after, string $reason): void
	{
		$id = (int)($after['id'] ?? 0);
		$driver = (string)($after['driver_user_id'] ?? '');
		if ($id <= 0 || $driver === '') {
			return;
		}
		$ctx = $this->bookingNotificationContext($after);
		$ctx['reason'] = $reason;
		$this->notifications->send(
			NotificationService::TYPE_BOOKING_REJECTED,
			$driver,
			'booking',
			$id,
			'booking.rejected:' . $id,
			$ctx,
		);
	}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 */
	private function notifyAfterBookingCancel(array $before, array $after, string $performedBy, string $reason): void
	{
		$id = (int)($after['id'] ?? 0);
		$driver = (string)($after['driver_user_id'] ?? '');
		if ($id <= 0) {
			return;
		}
		$ctx = $this->bookingNotificationContext($after);
		$ctx['reason'] = $reason;
		if ($driver !== '' && $performedBy !== $driver) {
			$this->notifications->send(
				NotificationService::TYPE_BOOKING_CANCELLED,
				$driver,
				'booking',
				$id,
				'booking.cancelled:' . $id . ':driver',
				$ctx,
			);
		}
		if ($performedBy === $driver) {
			$recipients = array_values(array_filter(
				$this->access->fleetManagerRecipients(),
				static fn (string $uid): bool => $uid !== $performedBy,
			));
			if ($recipients !== []) {
				$this->notifications->sendMany(
					NotificationService::TYPE_BOOKING_CANCELLED_BY_DRIVER,
					$recipients,
					'booking',
					$id,
					'booking.cancelled_by_driver:{userId}:' . $id,
					$ctx,
				);
			}
		}
	}

	/** @param array<string,mixed> $after */
	private function notifyAfterBookingCheckout(array $after, string $performedBy): void
	{
		$id = (int)($after['id'] ?? 0);
		if ($id <= 0) {
			return;
		}
		$ctx = $this->bookingNotificationContext($after);
		$ctx['checkedOutBy'] = $this->displayName($performedBy);
		$recipients = array_values(array_filter(
			$this->access->fleetManagerRecipients(),
			static fn (string $uid): bool => $uid !== $performedBy,
		));
		if ($recipients === []) {
			return;
		}
		$this->notifications->sendMany(
			NotificationService::TYPE_CHECKOUT_CONFIRMED,
			$recipients,
			'booking',
			$id,
			'checkout.confirmed:{userId}:' . $id,
			$ctx,
		);
	}

	/** @param array<string,mixed> $after */
	private function notifyAfterBookingCheckin(array $after, string $performedBy): void
	{
		$id = (int)($after['id'] ?? 0);
		$driver = (string)($after['driver_user_id'] ?? '');
		if ($id <= 0 || $driver === '') {
			return;
		}
		$ctx = $this->bookingNotificationContext($after);
		$ctx['checkedInBy'] = $this->displayName($performedBy);
		$this->notifications->send(
			NotificationService::TYPE_CHECKIN_COMPLETED,
			$driver,
			'booking',
			$id,
			'checkin.completed:' . $id,
			$ctx,
		);
	}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 */
	private function notifyAfterBookingUpdate(array $before, array $after, string $performedBy): void
	{
		$id = (int)($after['id'] ?? 0);
		if ($id <= 0) {
			return;
		}
		$timeChanged = ((string)($before['start_datetime'] ?? '') !== (string)($after['start_datetime'] ?? ''))
			|| ((string)($before['end_datetime'] ?? '') !== (string)($after['end_datetime'] ?? ''));
		$purposeChanged = ((string)($before['purpose'] ?? '') !== (string)($after['purpose'] ?? ''));
		$vehicleChanged = ((int)($before['vehicle_id'] ?? 0) !== (int)($after['vehicle_id'] ?? 0));
		$beforeSt = (string)($before['status'] ?? '');
		$afterSt = (string)($after['status'] ?? '');
		$approvalReopened = $beforeSt === BookingService::STATUS_APPROVED
			&& BookingService::isPendingApprovalStatus($afterSt);
		if (!$timeChanged && !$purposeChanged && !$vehicleChanged && !$approvalReopened) {
			return;
		}
		$st = $afterSt;
		$fp = $this->scheduleFingerprint($after);
		$ctx = $this->bookingNotificationContext($after);
		$driver = (string)($after['driver_user_id'] ?? '');
		$fleetActor = ($this->access->isFleetAdminOrManager($performedBy) || $this->access->isAppAdmin($performedBy))
			&& $driver !== '' && $performedBy !== $driver;

		if (BookingService::isPendingApprovalStatus($st)) {
			if ($st === BookingService::STATUS_PENDING_LINE_MANAGER) {
				$lm = $this->lineManagers->getActiveLineManagerUserIdForDriver($driver);
				if ($lm !== null && $lm !== '') {
					$this->notifications->send(
						NotificationService::TYPE_BOOKING_REQUESTED,
						$lm,
						'booking',
						$id,
						'booking.requested:' . $id . ':lm:upd:' . $fp,
						$ctx,
					);
				}
			} elseif ($st === BookingService::STATUS_PENDING_FLEET) {
				$this->notifications->sendMany(
					NotificationService::TYPE_BOOKING_REQUESTED,
					$this->access->fleetManagerRecipients(),
					'booking',
					$id,
					'booking.requested:{userId}:' . $id . ':fleet:upd:' . $fp,
					$ctx,
				);
			} else {
				$this->notifications->sendMany(
					NotificationService::TYPE_BOOKING_REQUESTED,
					$this->access->fleetManagerRecipients(),
					'booking',
					$id,
					'booking.requested:{userId}:' . $id . ':fleet:upd:chain:' . $st . ':' . $fp,
					$ctx,
				);
			}
		}

		if ($fleetActor) {
			$ctxR = $ctx + ['changedBy' => $this->displayName($performedBy)];
			if (BookingService::isPendingApprovalStatus($st) || $st === BookingService::STATUS_APPROVED) {
				$this->notifications->send(
					NotificationService::TYPE_BOOKING_RESCHEDULED,
					$driver,
					'booking',
					$id,
					'booking.rescheduled:' . $id . ':' . $fp,
					$ctxR,
				);
			}
		}
	}

	private function vehicleName(int $vehicleId): string
	{
		try {
			$v = $this->vehicles->get($vehicleId);
			return (string)($v['internal_name'] ?? '');
		} catch (\Throwable) {
			return '';
		}
	}

	private function displayName(string $uid): string
	{
		if ($uid === '') {
			return '';
		}
		$u = $this->userManager->get($uid);
		return $u !== null ? (string)($u->getDisplayName() ?: $uid) : $uid;
	}
}
