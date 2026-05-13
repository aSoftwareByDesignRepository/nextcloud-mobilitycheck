<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\BackgroundJob;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\MaintenanceService;
use OCA\MobilityCheck\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * §4.9 — Daily check for overdue maintenance schedules.
 *
 * Notifies the fleet manager recipient group once per logical event
 * (vehicle × schedule × overdue-day). Blocking schedules trigger an
 * additional `maintenance.overdue` priority notification — see
 * {@see NotificationService::TYPE_MAINTENANCE_OVERDUE}.
 */
final class MaintenanceReminderJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private IDBConnection $db,
		private MaintenanceService $maintenance,
		private NotificationService $notifications,
		private AccessControlService $access,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);
	}

	protected function run($argument): void
	{
		$today = gmdate('Y-m-d');
		$managers = $this->access->fleetManagerRecipients();
		if ($managers === []) {
			return;
		}

		$overdue = $this->maintenance->overdue();
		foreach ($overdue as $row) {
			$dueDate = (string)($row['next_due_date'] ?? '');
			$type = !empty($row['is_blocking'])
				? NotificationService::TYPE_MAINTENANCE_OVERDUE
				: NotificationService::TYPE_MAINTENANCE_DUE;
			$dedupe = sprintf(
				'%s:%d:%d:%s',
				$type,
				(int)$row['vehicle_id'],
				(int)$row['id'],
				$today,
			);
			$context = [
				'scheduleName' => (string)($row['name'] ?? ''),
				'vehicleId' => (int)$row['vehicle_id'],
				'vehicleName' => $this->vehicleName((int)$row['vehicle_id']),
				'due' => $dueDate,
			];
			$this->notifications->sendMany(
				$type,
				$managers,
				'maintenance_schedule',
				(int)$row['id'],
				$dedupe . ':manager:{userId}',
				$context,
			);
		}
	}

	private function vehicleName(int $vehicleId): string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('internal_name')->from('mc_vehicles')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
		$name = $qb->executeQuery()->fetchOne();
		return is_string($name) ? $name : '';
	}
}
