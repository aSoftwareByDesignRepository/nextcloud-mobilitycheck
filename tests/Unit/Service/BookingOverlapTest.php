<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Pure unit-test of the half-open booking overlap predicate. The
 * production query mirrors this predicate in SQL — keeping a PHP
 * twin here is the cheapest possible regression guard against the
 * "two approved bookings overlap" worst case.
 *
 *   Half-open intervals: A.end > B.start AND A.start < B.end
 *
 * The test matrix covers every relevant geometric case (before,
 * after, touch-left, touch-right, contains, contained, equal,
 * partial-left, partial-right).
 */
final class BookingOverlapTest extends TestCase
{
	/**
	 * @dataProvider overlapCases
	 */
	public function testOverlap(string $aStart, string $aEnd, string $bStart, string $bEnd, bool $expected): void
	{
		$this->assertSame($expected, $this->overlaps($aStart, $aEnd, $bStart, $bEnd), 'A=' . $aStart . '..' . $aEnd . ' vs B=' . $bStart . '..' . $bEnd);
	}

	public function overlapCases(): array
	{
		return [
			'a-before-b'      => ['2026-05-11 09:00:00', '2026-05-11 10:00:00', '2026-05-11 11:00:00', '2026-05-11 12:00:00', false],
			'a-after-b'       => ['2026-05-11 14:00:00', '2026-05-11 15:00:00', '2026-05-11 11:00:00', '2026-05-11 12:00:00', false],
			'touch-left'      => ['2026-05-11 09:00:00', '2026-05-11 11:00:00', '2026-05-11 11:00:00', '2026-05-11 12:00:00', false],
			'touch-right'     => ['2026-05-11 11:00:00', '2026-05-11 12:00:00', '2026-05-11 09:00:00', '2026-05-11 11:00:00', false],
			'a-contains-b'    => ['2026-05-11 08:00:00', '2026-05-11 18:00:00', '2026-05-11 11:00:00', '2026-05-11 12:00:00', true],
			'b-contains-a'    => ['2026-05-11 11:00:00', '2026-05-11 12:00:00', '2026-05-11 08:00:00', '2026-05-11 18:00:00', true],
			'equal'           => ['2026-05-11 09:00:00', '2026-05-11 12:00:00', '2026-05-11 09:00:00', '2026-05-11 12:00:00', true],
			'partial-left'    => ['2026-05-11 09:00:00', '2026-05-11 12:00:00', '2026-05-11 11:00:00', '2026-05-11 13:00:00', true],
			'partial-right'   => ['2026-05-11 11:00:00', '2026-05-11 13:00:00', '2026-05-11 09:00:00', '2026-05-11 12:00:00', true],
			'overnight-touch' => ['2026-05-11 22:00:00', '2026-05-12 06:00:00', '2026-05-12 06:00:00', '2026-05-12 10:00:00', false],
		];
	}

	private function overlaps(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
	{
		return $aStart < $bEnd && $aEnd > $bStart;
	}
}
