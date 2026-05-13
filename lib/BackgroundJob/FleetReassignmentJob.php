<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\BackgroundJob;

use OCA\MobilityCheck\Service\ReassignmentService;
use OCA\MobilityCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * §A5.4.4 — Periodic sweep: future bookings on unavailable vehicles get
 * replacement suggestions or automatic vehicle swaps per org policy.
 */
final class FleetReassignmentJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private ReassignmentService $reassignment,
		private SettingsService $settings,
	) {
		parent::__construct($time);
		$this->setInterval(10 * 60);
	}

	protected function run($argument): void
	{
		if (!$this->settings->intelligentAllocationEnabled()) {
			return;
		}
		$this->reassignment->runScheduledSweep();
	}
}
