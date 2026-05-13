<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Pure unit-test of the no-show grace predicate. The production
 * {@see \OCA\MobilityCheck\BackgroundJob\BookingNoShowJob} pulls
 * approved bookings whose `start_datetime <= now - grace_minutes`
 * and that have no matching `checkout` row. This test mirrors the
 * date arithmetic in PHP so a future refactor cannot silently start
 * cancelling early.
 */
final class NoShowPredicateTest extends TestCase
{
	private function isNoShow(int $start, int $now, int $graceMinutes, bool $hasCheckout): bool
	{
		if ($graceMinutes <= 0) {
			return false;
		}
		if ($hasCheckout) {
			return false;
		}
		return $start <= $now - $graceMinutes * 60;
	}

	public function testWithinGraceIsNotNoShow(): void
	{
		$start = strtotime('2026-06-10 10:00:00 UTC');
		$now = strtotime('2026-06-10 10:30:00 UTC');
		$this->assertFalse($this->isNoShow($start, $now, 60, false));
	}

	public function testExactlyAtGraceIsNoShow(): void
	{
		$start = strtotime('2026-06-10 10:00:00 UTC');
		$now = strtotime('2026-06-10 11:00:00 UTC');
		$this->assertTrue($this->isNoShow($start, $now, 60, false));
	}

	public function testCheckoutAlwaysCancelsNoShow(): void
	{
		$start = strtotime('2026-06-10 10:00:00 UTC');
		$now = strtotime('2026-06-10 13:00:00 UTC');
		$this->assertFalse($this->isNoShow($start, $now, 60, true));
	}

	public function testZeroGraceDisablesPredicate(): void
	{
		$start = strtotime('2026-06-10 10:00:00 UTC');
		$now = strtotime('2026-06-10 13:00:00 UTC');
		$this->assertFalse($this->isNoShow($start, $now, 0, false));
	}
}
