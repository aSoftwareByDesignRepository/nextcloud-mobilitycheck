<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\AppInfo\Application;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\Headers\AutoSubmitted;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Central event dispatcher (§4.10).
 *
 * Every notification flows through `send()` so that:
 *  - the recipient matrix lives in **one** place;
 *  - background jobs can check `mc_notification_log` for the
 *    dedupe key before sending (idempotency, §1.11);
 *  - in-app and email channels are honoured per-user preference
 *    (with admin-locked types like `safety_critical_damage`);
 *  - emails localise to the recipient's own language.
 *
 * Notification types are the keys you'll see in `mc_notification_log`.
 * Each type maps to a recipient resolver, an l10n key, and a default
 * channel set. Admin-locked types ignore the user's "off" preference.
 */
class NotificationService
{
	public const TYPE_BOOKING_REQUESTED = 'booking.requested';
	public const TYPE_BOOKING_APPROVED = 'booking.approved';
	public const TYPE_BOOKING_REJECTED = 'booking.rejected';
	public const TYPE_BOOKING_CANCELLED = 'booking.cancelled';
	/** Driver (or self-service actor) cancelled — fleet desk / supervisors need a distinct copy from {@see TYPE_BOOKING_CANCELLED}. */
	public const TYPE_BOOKING_CANCELLED_BY_DRIVER = 'booking.cancelled_by_driver';
	public const TYPE_BOOKING_RESCHEDULED = 'booking.rescheduled';
	public const TYPE_CHECKOUT_CONFIRMED = 'checkout.confirmed';
	public const TYPE_CHECKIN_COMPLETED = 'checkin.completed';
	public const TYPE_DAMAGE_REPORTED = 'damage.reported';
	public const TYPE_DAMAGE_SAFETY_CRITICAL = 'damage.safety_critical';
	public const TYPE_REPAIR_ASSIGNED = 'repair.assigned';
	public const TYPE_REPAIR_COMPLETED = 'repair.completed';
	public const TYPE_LICENCE_EXPIRING = 'licence.expiring';
	public const TYPE_LICENCE_EXPIRED = 'licence.expired';
	public const TYPE_INSTRUCTION_DUE = 'instruction.due';
	public const TYPE_INSTRUCTION_OVERDUE = 'instruction.overdue';
	public const TYPE_MAINTENANCE_DUE = 'maintenance.due';
	public const TYPE_MAINTENANCE_OVERDUE = 'maintenance.overdue';
	public const TYPE_COST_THRESHOLD = 'cost.threshold';
	public const TYPE_BOOKING_OVERDUE = 'booking.overdue';
	public const TYPE_BOOKING_NO_SHOW = 'booking.no_show';
	public const TYPE_BOOKING_EXTENDED = 'booking.extended';
	public const TYPE_APPROVAL_ESCALATED_LM = 'approval.escalated_line_manager';
	public const TYPE_APPROVAL_ESCALATED_FLEET = 'approval.escalated_fleet';
	/** Second nudge to the assigned LM when `pending_line_manager` exceeds the timeout (§4.5 step 5e). */
	public const TYPE_APPROVAL_LINE_MANAGER_TIMEOUT_REMINDER = 'approval.line_manager_timeout_reminder';
	public const TYPE_APPROVAL_LINE_MANAGER_OVERRIDDEN = 'approval.line_manager_overridden';
	public const TYPE_APPROVAL_LINE_MANAGER_REASSIGNED = 'approval.line_manager_reassigned';
	public const TYPE_BOOKING_PROXY_CREATED = 'booking.proxy_created';
	public const TYPE_CHARGEBACK_CREATED = 'chargeback.created';
	public const TYPE_CHARGEBACK_DISPUTED = 'chargeback.disputed';
	public const TYPE_CHARGEBACK_RESOLVED = 'chargeback.resolved';
	public const TYPE_BOOKING_REASSIGNMENT_SUGGESTED = 'booking.reassignment_suggested';
	public const TYPE_BOOKING_REASSIGNMENT_MANUAL_REQUIRED = 'booking.reassignment_manual_required';
	public const TYPE_BOOKING_REASSIGNED_DRIVER = 'booking.reassigned_driver';
	public const TYPE_BOOKING_REASSIGNED_MANAGER = 'booking.reassigned_manager';

	/** Critical types: cannot be disabled by the recipient. */
	public const ALWAYS_DELIVER = [
		self::TYPE_DAMAGE_SAFETY_CRITICAL,
		self::TYPE_LICENCE_EXPIRED,
	];

	public function __construct(
		private IDBConnection $db,
		private INotificationManager $notificationManager,
		private IMailer $mailer,
		private IFactory $l10nFactory,
		private IUserManager $userManager,
		private LoggerInterface $logger,
		private SettingsService $settings,
		private BookingIcalService $bookingIcal,
	) {
	}

	/**
	 * Send to one recipient, honouring channel preferences and dedupe.
	 *
	 *  - dedupeKey: pass a stable per-logical-event key (e.g.
	 *    `licence.expiring:driver42:90`) so duplicate sends are
	 *    a single row, no second notification.
	 *
	 * Returns true if a notification was actually emitted (or
	 * already had been for the same dedupe key), false on hard error.
	 */
	public function send(
		string $type,
		string $recipientUserId,
		string $entityType,
		int $entityId,
		string $dedupeKey,
		array $context = [],
	): bool {
		if ($recipientUserId === '') {
			return false;
		}
		if ($this->wasSent($type, $recipientUserId, $dedupeKey)) {
			return true;
		}
		try {
			$inAppOk = $this->sendInApp($type, $recipientUserId, $entityType, $entityId, $context);
			$emailOk = $this->maybeSendEmail($type, $recipientUserId, $entityType, $entityId, $context);
			$status = ($inAppOk || $emailOk) ? 'sent' : 'failed';
			$this->logRow($type, $recipientUserId, $entityType, $entityId, 'in_app', $dedupeKey, $status, $inAppOk ? null : 'in_app_failed');
			if ($emailOk) {
				$this->logRow($type, $recipientUserId, $entityType, $entityId, 'email', $dedupeKey . ':email', 'sent', null);
			}
			return $status === 'sent';
		} catch (\Throwable $e) {
			$this->logger->error('MobilityCheck notification dispatch failed', [
				'type' => $type,
				'recipient' => $recipientUserId,
				'exception' => $e->getMessage(),
			]);
			$this->logRow($type, $recipientUserId, $entityType, $entityId, 'in_app', $dedupeKey, 'failed', substr($e->getMessage(), 0, 250));
			return false;
		}
	}

	/**
	 * Send to a list of recipients. Used by background jobs and
	 * services that fan out to fleet managers / drivers at once.
	 *
	 * @param list<string> $recipients
	 */
	public function sendMany(
		string $type,
		array $recipients,
		string $entityType,
		int $entityId,
		string $dedupeKeyTemplate,
		array $context = [],
	): int {
		$count = 0;
		foreach (array_unique($recipients) as $uid) {
			if (!is_string($uid) || $uid === '') {
				continue;
			}
			$dedupe = str_replace('{userId}', $uid, $dedupeKeyTemplate);
			if ($this->send($type, $uid, $entityType, $entityId, $dedupe, $context)) {
				$count++;
			}
		}
		return $count;
	}

	public function wasSent(string $type, string $userId, string $dedupeKey): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('mc_notification_log')
			->where($qb->expr()->eq('notification_type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('recipient_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('dedupe_key', $qb->createNamedParameter($dedupeKey)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sent')))
			->setMaxResults(1);
		return $qb->executeQuery()->fetchOne() !== false;
	}

	private function sendInApp(string $type, string $userId, string $entityType, int $entityId, array $context): bool
	{
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(new \DateTime())
				->setObject($entityType, (string)$entityId)
				->setSubject($type, $context);
			$this->notificationManager->notify($notification);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('MobilityCheck in-app notification failed', [
				'type' => $type,
				'user' => $userId,
				'message' => $e->getMessage(),
			]);
			return false;
		}
	}

	private function maybeSendEmail(string $type, string $userId, string $entityType, int $entityId, array $context): bool
	{
		$user = $this->userManager->get($userId);
		if (!$user instanceof IUser) {
			return false;
		}
		$email = (string)$user->getEMailAddress();
		if ($email === '') {
			return false;
		}
		if (!$this->mailer->validateMailAddress($email)) {
			$this->logger->warning('MobilityCheck email skipped — invalid address', ['user' => $userId]);
			return false;
		}
		$lang = (string)$this->l10nFactory->getUserLanguage($user);
		$l = $this->l10nFactory->get(Application::APP_ID, $lang !== '' ? $lang : null);
		[$subject, $body] = $this->buildEmailCopy($type, $context, $l);
		$ical = null;
		if ($this->settings->bookingEmailAttachIcs()) {
			try {
				$ical = $this->bookingIcal->buildForEmail($type, $context, $l, $entityId);
			} catch (\Throwable $e) {
				$this->logger->warning('MobilityCheck booking iCalendar build failed', [
					'type' => $type,
					'user' => $userId,
					'message' => $e->getMessage(),
				]);
			}
		}
		if ($ical !== null && $ical !== '') {
			$body .= "\n\n" . $l->t('An iCalendar file (.ics) is attached so you can add this booking to your calendar.');
		}
		try {
			$msg = $this->mailer->createMessage();
			$msg->setSubject($subject);
			$msg->setTo([$email => $user->getDisplayName() ?: $userId]);
			$msg->setPlainBody($body);
			if ($ical !== null && $ical !== '') {
				$bookingId = (int)($context['bookingId'] ?? $entityId);
				$filename = 'mobilitycheck-booking-' . max(1, $bookingId) . '.ics';
				$msg->attach($this->mailer->createAttachment(
					$ical,
					$filename,
					'text/calendar; method=PUBLISH; charset=UTF-8',
				));
			}
			$msg->setAutoSubmitted(AutoSubmitted::VALUE_AUTO_GENERATED);
			$this->mailer->send($msg);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('MobilityCheck email send failed', [
				'type' => $type,
				'user' => $userId,
				'message' => $e->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * @return array{0:string,1:string} [subject, plain body]
	 */
	private function buildEmailCopy(string $type, array $ctx, \OCP\IL10N $l): array
	{
		return match ($type) {
			self::TYPE_BOOKING_REQUESTED => [
				$l->t('New booking request for %s', [$ctx['vehicleName'] ?? '']),
				$l->t("A new booking request is awaiting your approval.\n\nVehicle: %s\nDriver: %s\nFrom: %s\nUntil: %s\nPurpose: %s\n\nOpen MobilityCheck to approve or reject.", [
					$ctx['vehicleName'] ?? '',
					$ctx['driverName'] ?? '',
					$ctx['start'] ?? '',
					$ctx['end'] ?? '',
					$ctx['purpose'] ?? '',
				]),
			],
			self::TYPE_BOOKING_APPROVED => [
				$l->t('Your booking is approved'),
				$l->t("Your MobilityCheck booking is approved.\n\nVehicle: %s\nFrom: %s\nUntil: %s", [
					$ctx['vehicleName'] ?? '',
					$ctx['start'] ?? '',
					$ctx['end'] ?? '',
				]),
			],
			self::TYPE_BOOKING_REJECTED => [
				$l->t('Your booking was rejected'),
				$l->t("Your MobilityCheck booking was rejected.\n\nVehicle: %s\nFrom: %s\nUntil: %s\nReason: %s", [
					$ctx['vehicleName'] ?? '',
					$ctx['start'] ?? '',
					$ctx['end'] ?? '',
					$ctx['reason'] ?? '',
				]),
			],
			self::TYPE_BOOKING_CANCELLED => [
				$l->t('Your booking was cancelled'),
				$l->t("Your MobilityCheck booking was cancelled.\n\nVehicle: %s\nFrom: %s\nReason: %s", [
					$ctx['vehicleName'] ?? '',
					$ctx['start'] ?? '',
					$ctx['reason'] ?? '',
				]),
			],
			self::TYPE_BOOKING_CANCELLED_BY_DRIVER => [
				$l->t('Booking cancelled by driver'),
				$l->t("A driver cancelled their MobilityCheck booking.\n\nVehicle: %s\nDriver: %s\nFrom: %s\nUntil: %s\nReason: %s\n\nOpen MobilityCheck if you need to reassign the slot.", [
					$ctx['vehicleName'] ?? '',
					$ctx['driverName'] ?? '',
					$ctx['start'] ?? '',
					$ctx['end'] ?? '',
					$ctx['reason'] ?? '',
				]),
			],
			self::TYPE_BOOKING_RESCHEDULED => [
				$l->t('Your booking was updated'),
				$l->t("Your MobilityCheck booking details were updated.\n\nVehicle: %s\nFrom: %s\nUntil: %s\nPurpose: %s\n\nUpdated by: %s\n\nOpen MobilityCheck to review.", [
					$ctx['vehicleName'] ?? '',
					$ctx['start'] ?? '',
					$ctx['end'] ?? '',
					$ctx['purpose'] ?? '',
					$ctx['changedBy'] ?? '',
				]),
			],
			self::TYPE_CHECKOUT_CONFIRMED => [
				$l->t('Vehicle checked out'),
				$l->t("A booking was checked out (vehicle handed over).\n\nVehicle: %s\nDriver: %s\nFrom: %s\nUntil: %s\nRecorded by: %s\n\nOpen MobilityCheck for handover details.", [
					$ctx['vehicleName'] ?? '',
					$ctx['driverName'] ?? '',
					$ctx['start'] ?? '',
					$ctx['end'] ?? '',
					$ctx['checkedOutBy'] ?? '',
				]),
			],
			self::TYPE_CHECKIN_COMPLETED => [
				$l->t('Trip completed — check-in recorded'),
				$l->t("Your MobilityCheck booking was checked in.\n\nVehicle: %s\nFrom: %s\nUntil: %s\nRecorded by: %s\n\nOpen MobilityCheck for return notes and distance.", [
					$ctx['vehicleName'] ?? '',
					$ctx['start'] ?? '',
					$ctx['end'] ?? '',
					$ctx['checkedInBy'] ?? '',
				]),
			],
			self::TYPE_DAMAGE_REPORTED => [
				$l->t('Damage report for %s', [$ctx['vehicleName'] ?? '']),
				$l->t("A new damage report was submitted.\n\nVehicle: %s\nSeverity: %s\nZone: %s\nReported by: %s\n\nReview in MobilityCheck.", [
					$ctx['vehicleName'] ?? '',
					$ctx['severity'] ?? '',
					$ctx['zone'] ?? '',
					$ctx['reporter'] ?? '',
				]),
			],
			self::TYPE_DAMAGE_SAFETY_CRITICAL => [
				$l->t('SAFETY-CRITICAL DAMAGE on %s', [$ctx['vehicleName'] ?? '']),
				$l->t("A safety-critical damage report was submitted. The vehicle has been taken out of service.\n\nVehicle: %s\nDescription: %s\nReported by: %s\n\nReview in MobilityCheck immediately.", [
					$ctx['vehicleName'] ?? '',
					$ctx['description'] ?? '',
					$ctx['reporter'] ?? '',
				]),
			],
			self::TYPE_LICENCE_EXPIRING => [
				$l->t('Driving licence expires in %d days', [(int)($ctx['days'] ?? 0)]),
				$l->t("Your driving licence in MobilityCheck expires on %s. Please upload a renewed licence in good time so a fleet administrator can re-verify it.", [
					$ctx['expiry'] ?? '',
				]),
			],
			self::TYPE_LICENCE_EXPIRED => [
				$l->t('Driving licence expired'),
				$l->t("Your driving licence in MobilityCheck has expired. New vehicle bookings are blocked until you upload a renewed licence and a fleet administrator re-verifies it."),
			],
			self::TYPE_INSTRUCTION_DUE => [
				$l->t('Yearly driver instruction due'),
				$l->t("Your yearly driver instruction (Fahrunterweisung) for %d is not yet on record. Please complete it with your fleet manager.", [(int)($ctx['year'] ?? date('Y'))]),
			],
			self::TYPE_INSTRUCTION_OVERDUE => [
				$l->t('Yearly driver instruction overdue'),
				$l->t("The yearly driver instruction (Fahrunterweisung) for %d is still missing on record after the reminder window. Please complete it with your fleet manager without delay.", [(int)($ctx['year'] ?? date('Y'))]),
			],
			self::TYPE_MAINTENANCE_DUE => [
				$l->t('Maintenance due for %s', [$ctx['vehicleName'] ?? '']),
				$l->t("Scheduled maintenance is due: %s\nVehicle: %s\nDue: %s", [
					$ctx['scheduleName'] ?? '',
					$ctx['vehicleName'] ?? '',
					$ctx['due'] ?? '',
				]),
			],
			self::TYPE_MAINTENANCE_OVERDUE => [
				$l->t('Maintenance overdue for %s', [$ctx['vehicleName'] ?? '']),
				$l->t("Scheduled maintenance is overdue (blocking): %s\nVehicle: %s\nDue: %s\n\nOpen MobilityCheck and resolve the schedule.", [
					$ctx['scheduleName'] ?? '',
					$ctx['vehicleName'] ?? '',
					$ctx['due'] ?? '',
				]),
			],
			self::TYPE_COST_THRESHOLD => [
				$l->t('Vehicle cost threshold exceeded'),
				$l->t("%s exceeded its monthly cost threshold (%s) for %s.", [
					$ctx['vehicleName'] ?? '',
					$ctx['amount'] ?? '',
					$ctx['period'] ?? '',
				]),
			],
			self::TYPE_BOOKING_OVERDUE => [
				$l->t('Booking overdue check-in'),
				$l->t("A vehicle is still checked out past the booking end time and has not been checked in.\n\nVehicle: %s\nScheduled end (UTC): %s\nGrace before alert (minutes): %d\n\nOpen MobilityCheck to complete check-in or contact the driver.", [
					$ctx['vehicleName'] ?? '',
					$ctx['endDatetime'] ?? '',
					(int)($ctx['graceMinutes'] ?? 0),
				]),
			],
			self::TYPE_BOOKING_NO_SHOW => [
				$l->t('Booking auto-cancelled — no check-out'),
				$l->t("A booking was auto-cancelled because the vehicle was not checked out within the no-show grace period.\n\nVehicle: %s\nDriver: %s\nStart: %s\nGrace (minutes): %s", [
					$ctx['vehicleName'] ?? '',
					$ctx['driverName'] ?? '',
					$ctx['start'] ?? '',
					(string)($ctx['graceMinutes'] ?? ''),
				]),
			],
			self::TYPE_BOOKING_EXTENDED => [
				$l->t('Booking extended'),
				$l->t("A booking has been extended.\n\nVehicle: %s\nFrom: %s\nNew end: %s\nReason: %s", [
					$ctx['vehicleName'] ?? '',
					$ctx['start'] ?? '',
					$ctx['newEnd'] ?? '',
					$ctx['reason'] ?? '',
				]),
			],
			self::TYPE_APPROVAL_ESCALATED_LM => [
				$l->t('Booking approval escalated — line manager timeout'),
				$l->t("A booking approval has been escalated because the line manager did not respond within the configured time.\n\nVehicle: %s\nDriver: %s\nFrom: %s\nUntil: %s", [
					$ctx['vehicleName'] ?? '',
					$ctx['driverName'] ?? '',
					$ctx['start'] ?? '',
					$ctx['end'] ?? '',
				]),
			],
			self::TYPE_APPROVAL_LINE_MANAGER_TIMEOUT_REMINDER => [
				$l->t('Reminder — booking still needs your approval'),
				$l->t("This booking is still waiting for your line-manager decision after the configured timeout. Fleet supervisors have been notified as well. Please open MobilityCheck to approve or reject.\n\nVehicle: %s\nDriver: %s\nFrom: %s\nUntil: %s\nTimeout (hours): %s", [
					$ctx['vehicleName'] ?? '',
					$ctx['driverName'] ?? '',
					$ctx['start'] ?? '',
					$ctx['end'] ?? '',
					(string)($ctx['timeoutHours'] ?? ''),
				]),
			],
			self::TYPE_APPROVAL_ESCALATED_FLEET => [
				$l->t('Booking approval pending — fleet timeout'),
				$l->t("A booking approval is pending fleet decision past the configured timeout.\n\nVehicle: %s\nDriver: %s\nFrom: %s\nUntil: %s", [
					$ctx['vehicleName'] ?? '',
					$ctx['driverName'] ?? '',
					$ctx['start'] ?? '',
					$ctx['end'] ?? '',
				]),
			],
			self::TYPE_APPROVAL_LINE_MANAGER_OVERRIDDEN => [
				$l->t('Line manager step bypassed'),
				$l->t("A fleet manager bypassed the line-manager approval step for one of your supervised drivers.\n\nVehicle: %s\nDriver: %s\nFrom: %s\nUntil: %s\nReason: %s", [
					$ctx['vehicleName'] ?? '',
					$ctx['driverName'] ?? '',
					$ctx['start'] ?? '',
					$ctx['end'] ?? '',
					$ctx['reason'] ?? '',
				]),
			],
			self::TYPE_APPROVAL_LINE_MANAGER_REASSIGNED => [
				$l->t('Approval reassigned to you'),
				$l->t("A pending booking approval has been reassigned to you.\n\nVehicle: %s\nDriver: %s\nFrom: %s\nUntil: %s", [
					$ctx['vehicleName'] ?? '',
					$ctx['driverName'] ?? '',
					$ctx['start'] ?? '',
					$ctx['end'] ?? '',
				]),
			],
			self::TYPE_BOOKING_PROXY_CREATED => [
				$l->t('A booking was created on your behalf'),
				$l->t("Someone created a MobilityCheck booking on your behalf.\n\nCreated by: %s\nVehicle: %s\nFrom: %s\nUntil: %s\nPurpose: %s\n\nIf you did not request this trip, open MobilityCheck and cancel the booking. The audit log captures who created it.", [
					$ctx['createdByName'] ?? '',
					$ctx['vehicleName'] ?? '',
					$ctx['start'] ?? '',
					$ctx['end'] ?? '',
					$ctx['purpose'] ?? '',
				]),
			],
			self::TYPE_CHARGEBACK_CREATED => [
				$l->t('A chargeback has been raised against you'),
				$l->t("A chargeback was created against you in MobilityCheck.\n\nAmount: %s €\nReason: %s\n\nOpen MobilityCheck → Expenses to acknowledge or dispute it. Disputes must be raised within the configured window.", [
					(string)number_format(((int)($ctx['amountMinor'] ?? 0)) / 100, 2, '.', ''),
					$ctx['reason'] ?? '',
				]),
			],
			self::TYPE_CHARGEBACK_DISPUTED => [
				$l->t('A driver disputed a chargeback'),
				$l->t("A driver disputed a MobilityCheck chargeback.\n\nDriver: %s\nAmount: %s €\nReason: %s\n\nOpen MobilityCheck → Expenses to review and resolve.", [
					$ctx['driverUserId'] ?? '',
					(string)number_format(((int)($ctx['amountMinor'] ?? 0)) / 100, 2, '.', ''),
					$ctx['reason'] ?? '',
				]),
			],
			self::TYPE_CHARGEBACK_RESOLVED => [
				$l->t('Your chargeback dispute was resolved'),
				$l->t("A fleet administrator resolved your MobilityCheck chargeback dispute.\n\nResolution: %s\nNotes: %s\nAmount on file: %s €", [
					$ctx['resolution'] ?? '',
					$ctx['reason'] ?? '',
					(string)number_format(((int)($ctx['amountMinor'] ?? 0)) / 100, 2, '.', ''),
				]),
			],
			self::TYPE_BOOKING_REASSIGNMENT_SUGGESTED => [
				$l->t('Vehicle replacement suggested'),
				$l->t("A future booking is on a vehicle that is now unavailable. MobilityCheck recommends moving it to another eligible vehicle.\n\nBooking id: %s\nOpen “Reassignment suggestions” in MobilityCheck to accept or dismiss.", [
					(string)(int)($ctx['bookingId'] ?? 0),
				]),
			],
			self::TYPE_BOOKING_REASSIGNMENT_MANUAL_REQUIRED => [
				$l->t('Booking needs manual vehicle reassignment'),
				$l->t("No automatic replacement was found for a future booking on an unavailable vehicle.\n\nBooking id: %s\nPlease open MobilityCheck and assign another vehicle manually.", [
					(string)(int)($ctx['bookingId'] ?? 0),
				]),
			],
			self::TYPE_BOOKING_REASSIGNED_DRIVER => [
				$l->t('Your booking was moved to another vehicle'),
				$l->t("Your MobilityCheck booking was reassigned to another vehicle (the original unit is unavailable).\n\nBooking id: %s\nOpen MobilityCheck for the updated vehicle details.", [
					(string)(int)($ctx['bookingId'] ?? 0),
				]),
			],
			self::TYPE_BOOKING_REASSIGNED_MANAGER => [
				$l->t('A booking was automatically reassigned'),
				$l->t("A booking was moved to another vehicle by the intelligent allocation job or a fleet manager.\n\nBooking id: %s\nDriver: %s", [
					(string)(int)($ctx['bookingId'] ?? 0),
					$ctx['driverUserId'] ?? '',
				]),
			],
			default => [
				$l->t('MobilityCheck notification'),
				$l->t('You have a new MobilityCheck notification. Open MobilityCheck for details.'),
			],
		};
	}

	private function logRow(
		string $type,
		string $recipient,
		string $entityType,
		int $entityId,
		string $channel,
		string $dedupeKey,
		string $status,
		?string $error,
	): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('mc_notification_log')
			->values([
				'notification_type' => $qb->createNamedParameter($type),
				'recipient_user_id' => $qb->createNamedParameter($recipient),
				'entity_type' => $qb->createNamedParameter($entityType),
				'entity_id' => $qb->createNamedParameter($entityId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'channel' => $qb->createNamedParameter($channel),
				'dedupe_key' => $qb->createNamedParameter(substr($dedupeKey, 0, 191)),
				'sent_at' => $qb->createNamedParameter(gmdate('Y-m-d H:i:s')),
				'status' => $qb->createNamedParameter($status),
				'error_message' => $qb->createNamedParameter($error),
			]);
		try {
			$qb->executeStatement();
		} catch (\Throwable $e) {
			$this->logger->error('MobilityCheck notification log insert failed', ['e' => $e->getMessage()]);
		}
	}
}
