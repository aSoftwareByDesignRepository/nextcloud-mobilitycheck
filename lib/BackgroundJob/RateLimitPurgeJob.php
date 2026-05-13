<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\BackgroundJob;

use OCA\MobilityCheck\Service\RateLimitService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * §8.5 — Hourly purge of `mc_rate_limits` rows older than the largest sliding
 * window (plus a buffer). The counters are evaluated on read so unbounded
 * growth would only slow down the SELECT; this job keeps the table compact.
 */
final class RateLimitPurgeJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private RateLimitService $rateLimits,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60);
	}

	protected function run($argument): void
	{
		try {
			$deleted = $this->rateLimits->purgeStale();
			if ($deleted > 0) {
				$this->logger->debug(
					'MobilityCheck: rate-limit purge removed {deleted} rows',
					['app' => 'mobilitycheck', 'deleted' => $deleted],
				);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'MobilityCheck: rate-limit purge failed: {error}',
				[
					'app' => 'mobilitycheck',
					'error' => $e->getMessage(),
					'exception' => $e,
				],
			);
		}
	}
}
