<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\BackgroundJob;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\AuditLogService;
use OCA\MobilityCheck\Service\BookingService;
use OCA\MobilityCheck\Service\NotificationService;
use OCA\MobilityCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * §4.6 / Edge-case 13 — Booking end passed with no check-in.
 *
 * Fires after a configurable grace period (default 2h, see
 * `checkin_grace_minutes`) for any booking still in `active` state
 * past its `end_datetime`. Notifies the driver and fleet managers
 * once per booking.
 */
final class BookingOverdueJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private IDBConnection $db,
		private NotificationService $notifications,
		private AccessControlService $access,
		private SettingsService $settings,
		private AuditLogService $audit,
	) {
		parent::__construct($time);
		$this->setInterval(15 * 60);
	}

	protected function run($argument): void
	{
		$graceMinutes = $this->settings->overdueReturnGraceMinutes();
		$cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
			->modify('-' . $graceMinutes . ' minutes')
			->format('Y-m-d H:i:s');

		$qb = $this->db->getQueryBuilder();
		$qb->select('b.id', 'b.driver_user_id', 'b.vehicle_id', 'b.end_datetime', 'v.internal_name')
			->from('mc_bookings', 'b')
			->leftJoin('b', 'mc_vehicles', 'v', 'v.id = b.vehicle_id')
			->where($qb->expr()->eq('b.status', $qb->createNamedParameter(BookingService::STATUS_ACTIVE)))
			->andWhere($qb->expr()->lte('b.end_datetime', $qb->createNamedParameter($cutoff)));
		$res = $qb->executeQuery();

		$managers = $this->access->fleetManagerRecipients();
		while (($row = $res->fetch()) !== false) {
			$bookingId = (int)$row['id'];
			$driver = (string)$row['driver_user_id'];
			$dedupe = sprintf('booking_overdue:%d', $bookingId);
			$context = [
				'bookingId' => $bookingId,
				'vehicleId' => (int)$row['vehicle_id'],
				'vehicleName' => (string)($row['internal_name'] ?? ''),
				'endDatetime' => (string)$row['end_datetime'],
				'graceMinutes' => $graceMinutes,
			];
			$alreadyAlerted = $this->notifications->wasSent(
				NotificationService::TYPE_BOOKING_OVERDUE,
				$driver,
				$dedupe,
			);
			$this->notifications->send(
				NotificationService::TYPE_BOOKING_OVERDUE,
				$driver,
				'booking',
				$bookingId,
				$dedupe,
				$context,
			);
			if ($managers !== []) {
				$this->notifications->sendMany(
					NotificationService::TYPE_BOOKING_OVERDUE,
					$managers,
					'booking',
					$bookingId,
					$dedupe . ':manager:{userId}',
					$context + ['driverUserId' => $driver],
				);
			}
			// §T3.7 — one audit row per logical overdue event so the
			// auditor can reconstruct "we did notice this booking went
			// past its end time" even if the notification log is purged.
			if (!$alreadyAlerted) {
				$this->audit->log('booking', $bookingId, 'overdue_detected', '__system__', [
					'end_datetime' => (string)$row['end_datetime'],
					'grace_minutes' => $graceMinutes,
				]);
			}
		}
		$res->closeCursor();
	}
}
