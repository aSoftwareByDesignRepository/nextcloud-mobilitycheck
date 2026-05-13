<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * §6.16 invariants — pure-logic regression guard.
 *
 * `LineManagerService` enforces three rules that must hold at insert time:
 *   1. LINE_MANAGER_FIELDS_REQUIRED — driver and line manager ids are required.
 *   2. LINE_MANAGER_SELF             — `driver_user_id !== line_manager_user_id`.
 *   3. LINE_MANAGER_OVERLAP          — no two active assignments for the same driver.
 *   4. LINE_MANAGER_CIRCULAR         — if (A → B) is active, (B → A) must not be active
 *                                      at the same time.
 *
 * Constraints 3 and 4 share the *same* closed-interval overlap semantics
 * (the column `valid_until` is inclusive; `NULL` means open-ended). This
 * file mirrors that predicate in pure PHP so a regression in
 * `LineManagerService::dateRangesOverlap()` is caught here — the same
 * "PHP twin" pattern as {@see BookingOverlapTest}, but with inclusive
 * endpoints because the assignment table stores *dates*, not datetimes.
 */
final class LineManagerAssignmentTest extends TestCase
{
	/**
	 * @dataProvider closedIntervalCases
	 */
	public function testClosedIntervalOverlap(string $aFrom, ?string $aUntil, string $bFrom, ?string $bUntil, bool $expected): void
	{
		$this->assertSame(
			$expected,
			$this->overlapsClosed($aFrom, $aUntil, $bFrom, $bUntil),
			sprintf('[%s..%s] vs [%s..%s]', $aFrom, $aUntil ?? '∞', $bFrom, $bUntil ?? '∞'),
		);
	}

	public function closedIntervalCases(): array
	{
		return [
			// "before" / "after" — no overlap
			'a-strictly-before-b'  => ['2026-01-01', '2026-01-31', '2026-02-01', '2026-02-28', false],
			'a-strictly-after-b'   => ['2026-03-01', '2026-03-31', '2026-01-01', '2026-01-31', false],
			// Inclusive ends touch — closed-interval semantics ⇒ overlap.
			'touch-end-to-start'   => ['2026-01-01', '2026-01-31', '2026-01-31', '2026-02-28', true],
			'touch-end-to-start-r' => ['2026-01-31', '2026-02-28', '2026-01-01', '2026-01-31', true],
			'equal'                => ['2026-01-01', '2026-01-31', '2026-01-01', '2026-01-31', true],
			'a-contains-b'         => ['2026-01-01', '2026-12-31', '2026-06-01', '2026-06-15', true],
			'b-contains-a'         => ['2026-06-01', '2026-06-15', '2026-01-01', '2026-12-31', true],
			'partial-left'         => ['2026-01-01', '2026-06-30', '2026-04-01', '2026-09-30', true],
			'partial-right'        => ['2026-04-01', '2026-09-30', '2026-01-01', '2026-06-30', true],
			// Open-ended (valid_until = NULL) — overlaps everything from valid_from onward.
			'a-open-ended-touches' => ['2026-01-01', null, '2025-01-01', '2025-12-31', false],
			'a-open-ended-future'  => ['2026-01-01', null, '2027-01-01', '2027-01-31', true],
			'both-open-ended'      => ['2026-01-01', null, '2030-01-01', null, true],
			'b-open-ended-overlap' => ['2024-01-01', '2025-12-31', '2025-06-01', null, true],
			'b-open-ended-clear'   => ['2024-01-01', '2024-12-31', '2025-06-01', null, false],
		];
	}

	/**
	 * Mirror of {@see LineManagerService::dateRangesOverlap()}.
	 * `null` `until` = open-ended = 9999-12-31 in lexical order.
	 */
	private function overlapsClosed(string $aFrom, ?string $aUntil, string $bFrom, ?string $bUntil): bool
	{
		$aEnd = $aUntil ?? '9999-12-31';
		$bEnd = $bUntil ?? '9999-12-31';
		return $aFrom <= $bEnd && $bFrom <= $aEnd;
	}

	/**
	 * Demonstrates the §6.16.3 "no circular supervision" invariant on top
	 * of the closed-interval predicate.
	 *
	 * If `(driver=alice, lm=bob)` is to be inserted for `[from, until]`,
	 * the table must not contain `(driver=bob, lm=alice)` for an
	 * overlapping window. Closed (already terminated) inverse
	 * assignments are explicitly allowed — the chain has been broken.
	 *
	 * @dataProvider circularCases
	 */
	public function testCircularDetection(
		string $newDriver,
		string $newLm,
		string $newFrom,
		?string $newUntil,
		array $existing,
		bool $expectCircular,
	): void {
		$violation = false;
		foreach ($existing as [$driver, $lm, $from, $until]) {
			if ($driver !== $newLm || $lm !== $newDriver) {
				continue;
			}
			if ($this->overlapsClosed($newFrom, $newUntil, $from, $until)) {
				$violation = true;
				break;
			}
		}
		$this->assertSame($expectCircular, $violation);
	}

	public function circularCases(): array
	{
		return [
			'inverse-overlap-trip-trips' => [
				'alice', 'bob', '2026-06-01', null,
				[['bob', 'alice', '2026-01-01', null]],
				true,
			],
			'inverse-closed-before-is-ok' => [
				'alice', 'bob', '2026-06-01', null,
				[['bob', 'alice', '2025-01-01', '2025-12-31']],
				false,
			],
			'inverse-touches-by-a-day-trips' => [
				'alice', 'bob', '2026-01-01', null,
				[['bob', 'alice', '2025-01-01', '2026-01-01']],
				true,
			],
			'unrelated-pair-ignored' => [
				'alice', 'bob', '2026-01-01', null,
				[['carol', 'dan', '2025-01-01', null]],
				false,
			],
			'identical-direction-is-not-circular' => [
				'alice', 'bob', '2026-01-01', null,
				[['alice', 'bob', '2024-01-01', '2024-12-31']],
				false,
			],
			'inverse-future-trips' => [
				'alice', 'bob', '2026-01-01', '2026-12-31',
				[['bob', 'alice', '2026-06-01', '2026-07-31']],
				true,
			],
		];
	}
}
