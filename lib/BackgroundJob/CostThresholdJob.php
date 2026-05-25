<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\BackgroundJob;

use OCA\MobilityCheck\AppInfo\Application;
use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\MobilityCheckMoney;
use OCA\MobilityCheck\Service\NotificationService;
use OCA\MobilityCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * §4.8 cost threshold — end-of-month cap check.
 *
 * Admins may set a per-vehicle monthly cost threshold (in minor units)
 * via the `cost_threshold:{vehicleId}` app config key. The job runs
 * daily; on the **first day of a month** it computes the previous
 * month's gross spend per vehicle and fires a single notification to
 * the fleet manager recipient group when the cap was exceeded.
 *
 * Dedupe key: `cost_threshold:{vehicleId}:{YYYY-MM}` so a re-run never
 * duplicates the alert.
 */
final class CostThresholdJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private IDBConnection $db,
		private IConfig $config,
		private NotificationService $notifications,
		private AccessControlService $access,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);
	}

	protected function run($argument): void
	{
		$today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		// Only act on day 1–2 of a month (small window so a missed run still catches up).
		if ((int)$today->format('d') > 2) {
			return;
		}
		$previousMonth = $today->modify('first day of previous month');
		$periodKey = $previousMonth->format('Y-m');
		$start = $previousMonth->format('Y-m-d');
		$end = $previousMonth->modify('last day of this month')->format('Y-m-d');

		$managers = $this->access->fleetManagerRecipients();
		if ($managers === []) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('vehicle_id')
			->selectAlias($qb->func()->sum('amount_gross_minor'), 'total_minor')
			->from('mc_cost_entries')
			->where($qb->expr()->gte('entry_date', $qb->createNamedParameter($start)))
			->andWhere($qb->expr()->lte('entry_date', $qb->createNamedParameter($end)))
			->andWhere($qb->expr()->eq('is_deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->groupBy('vehicle_id');
		$res = $qb->executeQuery();
		while (($row = $res->fetch()) !== false) {
			$vehicleId = (int)$row['vehicle_id'];
			$totalMinor = (int)$row['total_minor'];
			$thresholdMinor = (int)$this->config->getAppValue(
				Application::APP_ID,
				'cost_threshold:' . $vehicleId,
				'0'
			);
			if ($thresholdMinor <= 0 || $totalMinor <= $thresholdMinor) {
				continue;
			}
			$vehicleName = $this->vehicleName($vehicleId);
			$dedupe = sprintf('cost_threshold:%d:%s', $vehicleId, $periodKey);
			$this->notifications->sendMany(
				NotificationService::TYPE_COST_THRESHOLD,
				$managers,
				'vehicle',
				$vehicleId,
				$dedupe . ':manager:{userId}',
				[
					'vehicleId' => $vehicleId,
					'vehicleName' => $vehicleName,
					'amount' => MobilityCheckMoney::formatMinorLabel(
						$totalMinor,
						strtoupper(trim($this->config->getAppValue(Application::APP_ID, SettingsService::KEY_CURRENCY, 'EUR')))
					),
					'thresholdAmount' => MobilityCheckMoney::formatMinorLabel(
						$thresholdMinor,
						strtoupper(trim($this->config->getAppValue(Application::APP_ID, SettingsService::KEY_CURRENCY, 'EUR')))
					),
					'period' => $periodKey,
				],
			);
		}
		$res->closeCursor();
	}

	private function vehicleName(int $vehicleId): string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('internal_name')->from('mc_vehicles')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
		$n = $qb->executeQuery()->fetchOne();
		return is_string($n) ? $n : ('#' . $vehicleId);
	}
}
