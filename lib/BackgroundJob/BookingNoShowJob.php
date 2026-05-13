<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\BackgroundJob;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\BookingService;
use OCA\MobilityCheck\Service\LineManagerService;
use OCA\MobilityCheck\Service\NotificationService;
use OCA\MobilityCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * §4.5a / §11 — No-show auto-cancel.
 *
 * An `approved` booking whose `start_datetime` is older than now minus the
 * `booking_no_show_grace_minutes` setting **and** that never produced a
 * matching `checkout` row is cancelled with reason `auto_cancel_no_show`.
 * The driver and fleet managers receive a single deduped notification.
 *
 * Why a dedicated job (rather than reusing {@see BookingOverdueJob}):
 *  - Different signal: no-show is "vehicle never picked up", overdue is
 *    "vehicle never returned". Mixing them in one job hides the
 *    distinct operator workflows.
 *  - Different idempotency boundary: no-show flips state once and
 *    notifies once. Overdue notifies repeatedly with the same dedupe key.
 *
 * Runs every 15 minutes; minimum sensible cadence given the 60-minute
 * default grace.
 */
final class BookingNoShowJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private IDBConnection $db,
		private BookingService $bookings,
		private NotificationService $notifications,
		private AccessControlService $access,
		private SettingsService $settings,
		private IUserManager $userManager,
		private LineManagerService $lineManagers,
	) {
		parent::__construct($time);
		$this->setInterval(15 * 60);
	}

	protected function run($argument): void
	{
		$graceMinutes = $this->settings->bookingNoShowGraceMinutes();
		if ($graceMinutes <= 0) {
			return;
		}
		$cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
			->modify('-' . $graceMinutes . ' minutes')
			->format('Y-m-d H:i:s');

		$qb = $this->db->getQueryBuilder();
		$qb->select('b.id', 'b.driver_user_id', 'b.vehicle_id', 'b.start_datetime', 'b.end_datetime', 'v.internal_name')
			->from('mc_bookings', 'b')
			->leftJoin('b', 'mc_vehicles', 'v', 'v.id = b.vehicle_id')
			->where($qb->expr()->eq('b.status', $qb->createNamedParameter(BookingService::STATUS_APPROVED)))
			->andWhere($qb->expr()->lte('b.start_datetime', $qb->createNamedParameter($cutoff)));
		$res = $qb->executeQuery();
		$candidates = [];
		while (($r = $res->fetch()) !== false) {
			$candidates[] = $r;
		}
		$res->closeCursor();
		if ($candidates === []) {
			return;
		}

		$managers = $this->access->fleetManagerRecipients();
		foreach ($candidates as $row) {
			$bookingId = (int)$row['id'];
			if ($this->hasCheckout($bookingId)) {
				continue;
			}
			try {
				$flipped = $this->bookings->autoCancelNoShow($bookingId, $graceMinutes);
			} catch (\Throwable) {
				continue;
			}
			if (!$flipped) {
				continue;
			}
			$driverId = (string)$row['driver_user_id'];
			$vehicleName = (string)($row['internal_name'] ?? '');
			$dedupe = sprintf('booking_no_show:%d', $bookingId);
			$context = [
				'bookingId' => $bookingId,
				'vehicleId' => (int)$row['vehicle_id'],
				'vehicleName' => $vehicleName,
				'start' => (string)$row['start_datetime'],
				'graceMinutes' => $graceMinutes,
				'driverName' => $this->displayName($driverId),
			];
			$this->notifications->send(
				NotificationService::TYPE_BOOKING_NO_SHOW,
				$driverId,
				'booking',
				$bookingId,
				$dedupe,
				$context,
			);
			if ($managers !== []) {
				$this->notifications->sendMany(
					NotificationService::TYPE_BOOKING_NO_SHOW,
					$managers,
					'booking',
					$bookingId,
					$dedupe . ':manager:{userId}',
					$context,
				);
			}
			$lmId = $this->lineManagers->getActiveLineManagerUserIdForDriver($driverId);
			if ($lmId !== null && $lmId !== '' && $lmId !== $driverId) {
				$this->notifications->send(
					NotificationService::TYPE_BOOKING_NO_SHOW,
					$lmId,
					'booking',
					$bookingId,
					$dedupe . ':lm:' . $lmId,
					$context,
				);
			}
		}
	}

	private function hasCheckout(int $bookingId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('mc_checkout_logs')
			->where($qb->expr()->eq('booking_id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('event_type', $qb->createNamedParameter('checkout')))
			->setMaxResults(1);
		return $qb->executeQuery()->fetchOne() !== false;
	}

	private function displayName(string $userId): string
	{
		if ($userId === '') {
			return '';
		}
		$u = $this->userManager->get($userId);
		return $u !== null ? (string)($u->getDisplayName() ?: $userId) : $userId;
	}
}
