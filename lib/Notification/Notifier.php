<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Notification;

use OCA\MobilityCheck\AppInfo\Application;
use OCA\MobilityCheck\Service\NotificationService;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;
use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * Parses MobilityCheck in-app notifications (subject = {@see NotificationService} type id).
 */
final class Notifier implements INotifier
{
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $url,
		private IUserManager $userManager,
	) {
	}

	public function getID(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return 'MobilityCheck';
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode !== '' ? $languageCode : null);
		$subject = $notification->getSubject();
		/** @var array<string, mixed> $p */
		$p = $notification->getSubjectParameters();

		$icon = $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg'));
		$notification->setIcon($icon);
		$notification->setLink($this->buildLink($notification));

		match ($subject) {
			NotificationService::TYPE_BOOKING_REQUESTED => $notification->setParsedSubject($l->t('Booking needs your approval'))
				->setParsedMessage($this->msgBookingRequested($l, $p)),
			NotificationService::TYPE_BOOKING_APPROVED => $notification->setParsedSubject($l->t('Booking approved'))
				->setParsedMessage($this->msgBookingApproved($l, $p)),
			NotificationService::TYPE_BOOKING_REJECTED => $notification->setParsedSubject($l->t('Booking rejected'))
				->setParsedMessage($this->msgBookingRejected($l, $p)),
			NotificationService::TYPE_BOOKING_CANCELLED => $notification->setParsedSubject($l->t('Booking cancelled'))
				->setParsedMessage($this->msgBookingCancelled($l, $p)),
			NotificationService::TYPE_BOOKING_CANCELLED_BY_DRIVER => $notification->setParsedSubject($l->t('Booking cancelled by driver'))
				->setParsedMessage($this->msgBookingCancelledByDriver($l, $p)),
			NotificationService::TYPE_BOOKING_RESCHEDULED => $notification->setParsedSubject($l->t('Booking updated'))
				->setParsedMessage($this->msgBookingRescheduled($l, $p)),
			NotificationService::TYPE_CHECKOUT_CONFIRMED => $notification->setParsedSubject($l->t('Vehicle checked out'))
				->setParsedMessage($this->msgCheckout($l, $p)),
			NotificationService::TYPE_CHECKIN_COMPLETED => $notification->setParsedSubject($l->t('Check-in recorded'))
				->setParsedMessage($this->msgCheckin($l, $p)),
			NotificationService::TYPE_DAMAGE_REPORTED => $notification->setParsedSubject($l->t('Damage reported'))
				->setParsedMessage($this->msgDamageReported($l, $p)),
			NotificationService::TYPE_DAMAGE_SAFETY_CRITICAL => $notification->setParsedSubject($l->t('Safety-critical damage'))
				->setParsedMessage($this->msgDamageCritical($l, $p)),
			NotificationService::TYPE_LICENCE_EXPIRING => $notification->setParsedSubject($l->t('Driving licence expiring soon'))
				->setParsedMessage($this->msgLicenceExpiring($l, $p)),
			NotificationService::TYPE_LICENCE_EXPIRED => $notification->setParsedSubject($l->t('Driving licence expired'))
				->setParsedMessage($this->msgLicenceExpired($l, $p)),
			NotificationService::TYPE_INSTRUCTION_DUE => $notification->setParsedSubject($l->t('Driver instruction due'))
				->setParsedMessage($this->msgInstructionDue($l, $p)),
			NotificationService::TYPE_INSTRUCTION_OVERDUE => $notification->setParsedSubject($l->t('Driver instruction overdue'))
				->setParsedMessage($this->msgInstructionOverdue($l, $p)),
			NotificationService::TYPE_MAINTENANCE_DUE => $notification->setParsedSubject($l->t('Maintenance due'))
				->setParsedMessage($this->msgMaintenanceDue($l, $p)),
			NotificationService::TYPE_MAINTENANCE_OVERDUE => $notification->setParsedSubject($l->t('Maintenance overdue'))
				->setParsedMessage($this->msgMaintenanceOverdue($l, $p)),
			NotificationService::TYPE_COST_THRESHOLD => $notification->setParsedSubject($l->t('Cost threshold exceeded'))
				->setParsedMessage($this->msgCostThreshold($l, $p)),
			NotificationService::TYPE_BOOKING_OVERDUE => $notification->setParsedSubject($l->t('Booking overdue'))
				->setParsedMessage($this->msgBookingOverdue($l, $p)),
			NotificationService::TYPE_BOOKING_NO_SHOW => $notification->setParsedSubject($l->t('Booking auto-cancelled'))
				->setParsedMessage($this->msgBookingNoShow($l, $p)),
			NotificationService::TYPE_BOOKING_EXTENDED => $notification->setParsedSubject($l->t('Booking extended'))
				->setParsedMessage($this->msgBookingExtended($l, $p)),
			NotificationService::TYPE_APPROVAL_ESCALATED_LM => $notification->setParsedSubject($l->t('Approval escalated'))
				->setParsedMessage($this->msgEscalatedLm($l, $p)),
			NotificationService::TYPE_APPROVAL_LINE_MANAGER_TIMEOUT_REMINDER => $notification->setParsedSubject($l->t('Approval reminder'))
				->setParsedMessage($this->msgLmTimeoutReminder($l, $p)),
			NotificationService::TYPE_APPROVAL_ESCALATED_FLEET => $notification->setParsedSubject($l->t('Fleet approval overdue'))
				->setParsedMessage($this->msgEscalatedFleet($l, $p)),
			NotificationService::TYPE_APPROVAL_LINE_MANAGER_OVERRIDDEN => $notification->setParsedSubject($l->t('Line manager step bypassed'))
				->setParsedMessage($this->msgLmOverridden($l, $p)),
			NotificationService::TYPE_APPROVAL_LINE_MANAGER_REASSIGNED => $notification->setParsedSubject($l->t('Approval reassigned to you'))
				->setParsedMessage($this->msgLmReassigned($l, $p)),
			NotificationService::TYPE_BOOKING_PROXY_CREATED => $notification->setParsedSubject($l->t('A booking was created on your behalf'))
				->setParsedMessage($this->msgProxyCreated($l, $p)),
			NotificationService::TYPE_CHARGEBACK_CREATED => $notification->setParsedSubject($l->t('A chargeback has been raised against you'))
				->setParsedMessage($this->msgChargebackCreated($l, $p)),
			NotificationService::TYPE_CHARGEBACK_DISPUTED => $notification->setParsedSubject($l->t('A driver disputed a chargeback'))
				->setParsedMessage($this->msgChargebackDisputed($l, $p)),
			NotificationService::TYPE_CHARGEBACK_RESOLVED => $notification->setParsedSubject($l->t('Your chargeback dispute was resolved'))
				->setParsedMessage($this->msgChargebackResolved($l, $p)),
			NotificationService::TYPE_BOOKING_REASSIGNMENT_SUGGESTED => $notification->setParsedSubject($l->t('Vehicle replacement suggested'))
				->setParsedMessage($l->t('Booking %s — open reassignment suggestions in MobilityCheck.', [(string)(int)($p['bookingId'] ?? 0)])),
			NotificationService::TYPE_BOOKING_REASSIGNMENT_MANUAL_REQUIRED => $notification->setParsedSubject($l->t('Manual reassignment required'))
				->setParsedMessage($l->t('Booking %s has no automatic replacement. Assign a vehicle manually.', [(string)(int)($p['bookingId'] ?? 0)])),
			NotificationService::TYPE_BOOKING_REASSIGNED_DRIVER => $notification->setParsedSubject($l->t('Your booking was moved to another vehicle'))
				->setParsedMessage($l->t('Booking %s was reassigned because the original vehicle is unavailable.', [(string)(int)($p['bookingId'] ?? 0)])),
			NotificationService::TYPE_BOOKING_REASSIGNED_MANAGER => $notification->setParsedSubject($l->t('Booking vehicle changed'))
				->setParsedMessage($l->t('Booking %s was reassigned (driver: %s).', [
					(string)(int)($p['bookingId'] ?? 0),
					(string)($p['driverUserId'] ?? ''),
				])),
			default => $notification->setParsedSubject($l->t('MobilityCheck'))
				->setParsedMessage($l->t('You have a new MobilityCheck notification.')),
		};

		return $notification;
	}

	private function buildLink(INotification $n): string
	{
		$rawId = $n->getObjectId();
		$id = is_numeric($rawId) ? (int)$rawId : 0;
		return match ($n->getObjectType()) {
			'booking' => $id > 0
				? $this->url->linkToRoute('mobilitycheck.page.bookingDetail', ['id' => $id])
				: $this->url->linkToRoute('mobilitycheck.page.bookings'),
			'driver_profile' => $id > 0
				? $this->url->linkToRoute('mobilitycheck.page.driverDetail', ['id' => $id])
				: $this->url->linkToRoute('mobilitycheck.page.compliance'),
			'vehicle' => $id > 0
				? $this->url->linkToRoute('mobilitycheck.page.vehicleDetail', ['id' => $id])
				: $this->url->linkToRoute('mobilitycheck.page.vehicles'),
			'maintenance_schedule' => $this->url->linkToRoute('mobilitycheck.page.maintenance'),
			default => $this->url->linkToRoute('mobilitycheck.page.index'),
		};
	}

	/** @param array<string, mixed> $p */
	private function msgBookingRequested(IL10N $l, array $p): string
	{
		return $l->t('%s — %s until %s. Approve or reject in MobilityCheck.', [
			(string)($p['vehicleName'] ?? ''),
			(string)($p['driverName'] ?? ''),
			(string)($p['end'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgBookingApproved(IL10N $l, array $p): string
	{
		return $l->t('Approved: %s from %s until %s.', [
			(string)($p['vehicleName'] ?? ''),
			(string)($p['start'] ?? ''),
			(string)($p['end'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgBookingRejected(IL10N $l, array $p): string
	{
		return $l->t('Rejected: %s. Reason: %s', [
			(string)($p['vehicleName'] ?? ''),
			(string)($p['reason'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgBookingCancelled(IL10N $l, array $p): string
	{
		return $l->t('Cancelled: %s starting %s. Reason: %s', [
			(string)($p['vehicleName'] ?? ''),
			(string)($p['start'] ?? ''),
			(string)($p['reason'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgBookingCancelledByDriver(IL10N $l, array $p): string
	{
		return $l->t('Driver %s cancelled %s (%s – %s). Reason: %s', [
			(string)($p['driverName'] ?? ''),
			(string)($p['vehicleName'] ?? ''),
			(string)($p['start'] ?? ''),
			(string)($p['end'] ?? ''),
			(string)($p['reason'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgBookingRescheduled(IL10N $l, array $p): string
	{
		$by = (string)($p['changedBy'] ?? '');
		if ($by !== '') {
			return $l->t('Updated by %1$s: %2$s, %3$s – %4$s.', [
				$by,
				(string)($p['vehicleName'] ?? ''),
				(string)($p['start'] ?? ''),
				(string)($p['end'] ?? ''),
			]);
		}
		return $l->t('Updated: %1$s, %2$s – %3$s.', [
			(string)($p['vehicleName'] ?? ''),
			(string)($p['start'] ?? ''),
			(string)($p['end'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgCheckout(IL10N $l, array $p): string
	{
		return $l->t('%s checked out %s for driver %s (%s – %s).', [
			(string)($p['checkedOutBy'] ?? ''),
			(string)($p['vehicleName'] ?? ''),
			(string)($p['driverName'] ?? ''),
			(string)($p['start'] ?? ''),
			(string)($p['end'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgCheckin(IL10N $l, array $p): string
	{
		return $l->t('Check-in recorded for %s (%s – %s). Recorded by: %s', [
			(string)($p['vehicleName'] ?? ''),
			(string)($p['start'] ?? ''),
			(string)($p['end'] ?? ''),
			(string)($p['checkedInBy'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgDamageReported(IL10N $l, array $p): string
	{
		return $l->t('New damage on %s (%s).', [
			(string)($p['vehicleName'] ?? ''),
			(string)($p['severity'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgDamageCritical(IL10N $l, array $p): string
	{
		return $l->t('Safety-critical damage on %s. Review immediately.', [
			(string)($p['vehicleName'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgLicenceExpiring(IL10N $l, array $p): string
	{
		$driverLabel = $this->driverLabel($p);
		if ($driverLabel !== '') {
			return $l->t('Licence for %1$s expires in %2$d days (%3$s).', [
				$driverLabel,
				(int)($p['days'] ?? 0),
				(string)($p['expiry'] ?? ''),
			]);
		}
		return $l->t('Your licence expires in %d days (%s).', [
			(int)($p['days'] ?? 0),
			(string)($p['expiry'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgLicenceExpired(IL10N $l, array $p): string
	{
		$driverLabel = $this->driverLabel($p);
		if ($driverLabel !== '') {
			return $l->t('Driving licence expired for %s.', [$driverLabel]);
		}
		return $l->t('Your driving licence in MobilityCheck has expired.');
	}

	/** @param array<string, mixed> $p */
	private function msgInstructionDue(IL10N $l, array $p): string
	{
		return $l->t('Yearly instruction for %d is not yet recorded.', [(int)($p['year'] ?? date('Y'))]);
	}

	/** @param array<string, mixed> $p */
	private function msgInstructionOverdue(IL10N $l, array $p): string
	{
		$driverLabel = $this->driverLabel($p);
		if ($driverLabel !== '') {
			return $l->t('Driver %s still owes the %d yearly instruction.', [
				$driverLabel,
				(int)($p['year'] ?? date('Y')),
			]);
		}
		return $l->t('Your %d yearly driver instruction is still missing.', [(int)($p['year'] ?? date('Y'))]);
	}

	/** @param array<string, mixed> $p */
	private function msgMaintenanceDue(IL10N $l, array $p): string
	{
		return $l->t('%s — %s due %s.', [
			(string)($p['vehicleName'] ?? ''),
			(string)($p['scheduleName'] ?? ''),
			(string)($p['due'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgMaintenanceOverdue(IL10N $l, array $p): string
	{
		return $l->t('Overdue (blocking): %s on %s (due %s).', [
			(string)($p['scheduleName'] ?? ''),
			(string)($p['vehicleName'] ?? ''),
			(string)($p['due'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgCostThreshold(IL10N $l, array $p): string
	{
		return $l->t('%s exceeded %s for month %s (threshold %s).', [
			(string)($p['vehicleName'] ?? ''),
			(string)($p['amount'] ?? ''),
			(string)($p['period'] ?? ''),
			(string)($p['thresholdAmount'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgBookingOverdue(IL10N $l, array $p): string
	{
		return $l->t('Check-in still missing for %s (scheduled end %s UTC, grace %d min).', [
			(string)($p['vehicleName'] ?? ''),
			(string)($p['endDatetime'] ?? ''),
			(int)($p['graceMinutes'] ?? 0),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgBookingNoShow(IL10N $l, array $p): string
	{
		return $l->t('No check-out: %s / %s.', [
			(string)($p['vehicleName'] ?? ''),
			(string)($p['driverName'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgBookingExtended(IL10N $l, array $p): string
	{
		return $l->t('%s extended until %s.', [
			(string)($p['vehicleName'] ?? ''),
			(string)($p['newEnd'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgEscalatedLm(IL10N $l, array $p): string
	{
		return $l->t('Line manager timeout: %s for %s.', [
			(string)($p['vehicleName'] ?? ''),
			(string)($p['driverName'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgLmTimeoutReminder(IL10N $l, array $p): string
	{
		return $l->t('Still awaiting your decision: %s (%s – %s).', [
			(string)($p['vehicleName'] ?? ''),
			(string)($p['start'] ?? ''),
			(string)($p['end'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgEscalatedFleet(IL10N $l, array $p): string
	{
		return $l->t('Fleet approval pending too long: %s.', [(string)($p['vehicleName'] ?? '')]);
	}

	/** @param array<string, mixed> $p */
	private function msgLmOverridden(IL10N $l, array $p): string
	{
		return $l->t('Fleet bypassed line-manager step for %s. Reason: %s', [
			(string)($p['driverName'] ?? ''),
			(string)($p['reason'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgLmReassigned(IL10N $l, array $p): string
	{
		return $l->t('You are now the approver for %s (%s – %s).', [
			(string)($p['vehicleName'] ?? ''),
			(string)($p['start'] ?? ''),
			(string)($p['end'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function driverLabel(array $p): string
	{
		$uid = (string)($p['driverUserId'] ?? '');
		if ($uid === '') {
			return '';
		}
		$u = $this->userManager->get($uid);
		if ($u !== null) {
			$dn = (string)($u->getDisplayName());
			return $dn !== '' ? $dn : $uid;
		}
		return $uid;
	}

	/** @param array<string, mixed> $p */
	private function msgProxyCreated(IL10N $l, array $p): string
	{
		return $l->t('%1$s created a booking on your behalf for %2$s (%3$s – %4$s). If you did not request this, cancel it directly.', [
			(string)($p['createdByName'] ?? ''),
			(string)($p['vehicleName'] ?? ''),
			(string)($p['start'] ?? ''),
			(string)($p['end'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgChargebackCreated(IL10N $l, array $p): string
	{
		$amount = (int)($p['amountMinor'] ?? 0);
		return $l->t('A chargeback of %.2f € has been created. Please review it in your expenses inbox.', [$amount / 100]);
	}

	/** @param array<string, mixed> $p */
	private function msgChargebackDisputed(IL10N $l, array $p): string
	{
		return $l->t('A driver disputed a chargeback. Reason: %s', [
			(string)($p['reason'] ?? ''),
		]);
	}

	/** @param array<string, mixed> $p */
	private function msgChargebackResolved(IL10N $l, array $p): string
	{
		return $l->t('Resolution: %1$s. %2$s', [
			(string)($p['resolution'] ?? ''),
			(string)($p['reason'] ?? ''),
		]);
	}
}
