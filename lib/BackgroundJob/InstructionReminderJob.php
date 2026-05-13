<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\BackgroundJob;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * §4.4 — Yearly driver instruction (Fahrunterweisung) reminder job.
 *
 * Reminds every driver who is missing the current-year instruction
 * record. The schedule (§4.4) is:
 *   - January, April, July, October: a single courtesy ping;
 *   - From 1 October the manager group is CC'd on every reminder;
 *   - From 1 November the reminder fires biweekly.
 *
 * Dedupe keys carry both the user id and an ISO month/cycle marker
 * so the job is idempotent on retry within the same window.
 */
final class InstructionReminderJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private IDBConnection $db,
		private NotificationService $notifications,
		private AccessControlService $access,
	) {
		parent::__construct($time);
		// Run daily — internal cycle logic decides whether to actually send.
		$this->setInterval(24 * 60 * 60);
	}

	protected function run($argument): void
	{
		$today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$cycleKey = $this->currentCycleKey($today);
		if ($cycleKey === null) {
			return;
		}
		$year = (int)$today->format('Y');
		$ccManagers = (int)$today->format('m') >= 10;

		$drivers = $this->findDriversMissingInstruction($year);
		$managers = $ccManagers ? $this->access->fleetManagerRecipients() : [];

		foreach ($drivers as $driver) {
			$dedupe = sprintf('instruction_due:%d:%d:%s', (int)$driver['id'], $year, $cycleKey);
			$this->notifications->send(
				$ccManagers ? NotificationService::TYPE_INSTRUCTION_OVERDUE : NotificationService::TYPE_INSTRUCTION_DUE,
				(string)$driver['user_id'],
				'driver_profile',
				(int)$driver['id'],
				$dedupe,
				['year' => $year],
			);
			if ($ccManagers && $managers !== []) {
				$this->notifications->sendMany(
					NotificationService::TYPE_INSTRUCTION_OVERDUE,
					$managers,
					'driver_profile',
					(int)$driver['id'],
					$dedupe . ':manager:{userId}',
					['year' => $year, 'driverUserId' => (string)$driver['user_id']],
				);
			}
		}
	}

	private function currentCycleKey(\DateTimeImmutable $today): ?string
	{
		$m = (int)$today->format('m');
		$d = (int)$today->format('d');
		// Courtesy months: send once on the first run of the month.
		if (in_array($m, [1, 4, 7, 10], true) && $d <= 2) {
			return $today->format('Y-m-courtesy');
		}
		// From November: biweekly (1st and 15th).
		if (in_array($m, [11, 12], true)) {
			if ($d <= 2) {
				return $today->format('Y-m-first');
			}
			if ($d >= 15 && $d <= 16) {
				return $today->format('Y-m-mid');
			}
		}
		return null;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function findDriversMissingInstruction(int $year): array
	{
		$qb = $this->db->getQueryBuilder();
		// Drivers without an instruction record for the year.
		$sub = $this->db->getQueryBuilder();
		$sub->select('driver_profile_id')->from('mc_instruction_records')
			->where($sub->expr()->eq('calendar_year', $sub->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		$qb->select('id', 'user_id')->from('mc_driver_profiles')
			->where($qb->expr()->notIn('id', $qb->createFunction('(' . $sub->getSQL() . ')')))
			->andWhere($qb->expr()->neq('licence_status', $qb->createNamedParameter('rejected')));
		foreach ($sub->getParameters() as $name => $value) {
			$qb->setParameter($name, $value);
		}
		$res = $qb->executeQuery();
		$out = [];
		while (($row = $res->fetch()) !== false) {
			$out[] = $row;
		}
		$res->closeCursor();
		return $out;
	}
}
