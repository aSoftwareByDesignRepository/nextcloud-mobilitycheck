<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Exception;

use OCP\AppFramework\Http;

/**
 * §8.5 — Raised by {@see \OCA\MobilityCheck\Service\RateLimitService} when a
 * sliding-window bucket is exhausted. The exception carries the seconds the
 * client must wait before the next attempt so the controller can render the
 * standard `Retry-After` header.
 */
class RateLimitedException extends \RuntimeException
{
	public function __construct(
		private string $bucket,
		private int $retryAfterSeconds,
	) {
		parent::__construct('RATE_LIMITED', Http::STATUS_TOO_MANY_REQUESTS);
	}

	public function getBucket(): string
	{
		return $this->bucket;
	}

	public function getRetryAfterSeconds(): int
	{
		return max(1, $this->retryAfterSeconds);
	}
}
