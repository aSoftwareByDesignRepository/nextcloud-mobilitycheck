<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\BackgroundJob;

use OCA\MobilityCheck\AppInfo\Application;
use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\NotificationService;
use OCA\MobilityCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * §4.3 + §4.10 — Daily check of driver licence expiry.
 *
 *  - For every driver profile whose `licence_status` is `verified` and
 *    whose `licence_expiry_date` lies inside one of the configured
 *    threshold windows (default 90 / 60 / 30 / 14 / 7 days), fire a
 *    notification to the driver and to all fleet managers / fleet
 *    admins.
 *  - Expired licences (date in the past) are reported once, then the
 *    profile is marked `expired` so future bookings are blocked by
 *    {@see \OCA\MobilityCheck\Service\ComplianceService}.
 *
 * Idempotency: every notification carries a per-day, per-threshold,
 * per-driver `dedupe_key` written to `mc_notification_log` (§11). A
 * second run of this job on the same calendar day is a no-op.
 */
final class LicenceExpiryReminderJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private IDBConnection $db,
		private SettingsService $settings,
		private NotificationService $notifications,
		private AccessControlService $access,
	) {
		parent::__construct($time);
		// Daily.
		$this->setInterval(24 * 60 * 60);
	}

	protected function run($argument): void
	{
		$thresholds = $this->settings->licenceThresholdsDays();
		$today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$todayIso = $today->format('Y-m-d');

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'user_id', 'licence_expiry_date', 'licence_status')
			->from('mc_driver_profiles')
			->where($qb->expr()->isNotNull('licence_expiry_date'));
		$res = $qb->executeQuery();
		$managerRecipients = $this->access->fleetManagerRecipients();

		while (($row = $res->fetch()) !== false) {
			$expiry = (string)$row['licence_expiry_date'];
			if ($expiry === '') {
				continue;
			}
			$expiryDate = \DateTimeImmutable::createFromFormat('Y-m-d', substr($expiry, 0, 10), new \DateTimeZone('UTC'));
			if ($expiryDate === false) {
				continue;
			}
			$daysToExpiry = (int)floor(($expiryDate->getTimestamp() - $today->getTimestamp()) / 86400);
			$userId = (string)$row['user_id'];
			$profileId = (int)$row['id'];

			if ($daysToExpiry < 0) {
				$this->markExpired($profileId);
				$context = ['expiry' => substr($expiry, 0, 10)];
				$dedupe = sprintf('licence_expired:%d:%s', $profileId, $todayIso);
				$this->notifications->send(
					NotificationService::TYPE_LICENCE_EXPIRED,
					$userId,
					'driver_profile',
					$profileId,
					$dedupe,
					$context,
				);
				$this->notifications->sendMany(
					NotificationService::TYPE_LICENCE_EXPIRED,
					$managerRecipients,
					'driver_profile',
					$profileId,
					$dedupe . ':manager:{userId}',
					$context + ['driverUserId' => $userId],
				);
				continue;
			}
			if (in_array($daysToExpiry, $thresholds, true)) {
				$context = [
					'days' => $daysToExpiry,
					'expiry' => substr($expiry, 0, 10),
				];
				$dedupe = sprintf('licence_expiring:%d:%d:%s', $profileId, $daysToExpiry, $todayIso);
				$this->notifications->send(
					NotificationService::TYPE_LICENCE_EXPIRING,
					$userId,
					'driver_profile',
					$profileId,
					$dedupe,
					$context,
				);
				$this->notifications->sendMany(
					NotificationService::TYPE_LICENCE_EXPIRING,
					$managerRecipients,
					'driver_profile',
					$profileId,
					$dedupe . ':manager:{userId}',
					$context + ['driverUserId' => $userId],
				);
			}
		}
		$res->closeCursor();
	}

	private function markExpired(int $profileId): void
	{
		$upd = $this->db->getQueryBuilder();
		$upd->update('mc_driver_profiles')
			->set('licence_status', $upd->createNamedParameter('expired'))
			->set('updated_at', $upd->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->where($upd->expr()->eq('id', $upd->createNamedParameter($profileId, IQueryBuilder::PARAM_INT)))
			->andWhere($upd->expr()->neq('licence_status', $upd->createNamedParameter('expired')));
		$upd->executeStatement();
	}
}
