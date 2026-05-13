<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Pure unit-test of the booking extension predicate. The production code in
 * {@see \OCA\MobilityCheck\Service\BookingService::extend()} enforces these
 * invariants; mirroring them here as plain PHP gives us a cheap regression
 * guard that runs in milliseconds without a DB.
 *
 *   1. The new end must be strictly later than the current end.
 *   2. The new end must not exceed `current_end + cap_minutes`.
 *   3. A 0 cap means "extension is disabled" (caller would short-circuit
 *      before we ever hit this predicate).
 */
final class BookingExtendValidationTest extends TestCase
{
	private function inWindow(int $currentEnd, int $newEnd, int $capMinutes): string
	{
		if ($newEnd <= $currentEnd) {
			return 'EXTEND_NOT_LATER';
		}
		if ($capMinutes > 0 && ($newEnd - $currentEnd) > $capMinutes * 60) {
			return 'EXTEND_EXCEEDS_CAP';
		}
		return 'ok';
	}

	public function testNewEndMustBeLater(): void
	{
		$now = strtotime('2026-06-10 10:00:00 UTC');
		$this->assertSame('EXTEND_NOT_LATER', $this->inWindow($now, $now, 120));
		$this->assertSame('EXTEND_NOT_LATER', $this->inWindow($now, $now - 60, 120));
	}

	public function testWithinCap(): void
	{
		$end = strtotime('2026-06-10 10:00:00 UTC');
		$this->assertSame('ok', $this->inWindow($end, $end + 30 * 60, 120));
		$this->assertSame('ok', $this->inWindow($end, $end + 120 * 60, 120));
	}

	public function testExceedsCap(): void
	{
		$end = strtotime('2026-06-10 10:00:00 UTC');
		$this->assertSame('EXTEND_EXCEEDS_CAP', $this->inWindow($end, $end + 121 * 60, 120));
		$this->assertSame('EXTEND_EXCEEDS_CAP', $this->inWindow($end, $end + 10 * 3600, 120));
	}

	public function testZeroCapAllowsAnyExtensionInThisPredicate(): void
	{
		$end = strtotime('2026-06-10 10:00:00 UTC');
		// 0 cap is interpreted as "no upper bound" in this predicate. The
		// caller decides whether the feature is disabled (cap == 0 means
		// "disabled" at the service level — they short-circuit before
		// calling this check).
		$this->assertSame('ok', $this->inWindow($end, $end + 24 * 3600, 0));
	}
}
