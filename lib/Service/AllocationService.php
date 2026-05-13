<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * §A5.4 — Intelligent vehicle allocation: deterministic scoring of
 * candidate vehicles by lease burn-down, age, and recent utilisation.
 * Engine is pure and side-effect-free; callers handle persistence
 * (`suggest_only` vs `auto_commit`).
 *
 * Off by default (`intelligent_allocation_enabled = false`).
 */
class AllocationService
{
	public function __construct(
		private IDBConnection $db,
		private VehicleService $vehicles,
		private VehicleAssignmentService $assignments,
		private SettingsService $settings,
	) {
	}

	public function enabled(): bool
	{
		return $this->settings->intelligentAllocationEnabled();
	}

	/**
	 * Exclude vehicles whose lease contract ends before the trip ends (UTC dates).
	 *
	 * @param array<string,mixed> $vehicle hydrated row
	 */
	public function leaseAllowsBookingThrough(array $vehicle, string $bookingEndUtc): bool
	{
		$leaseEnd = $vehicle['lease_end_date'] ?? null;
		if ($leaseEnd === null || $leaseEnd === '') {
			return true;
		}
		$endDate = substr($bookingEndUtc, 0, 10);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
			return true;
		}
		return $endDate <= (string)$leaseEnd;
	}

	/**
	 * §A5.4.5 — When `min_remaining_lease_km_percent` is greater than zero, exclude vehicles whose
	 * modelled lease-km headroom (linear contract curve vs odometer delta) falls
	 * below the threshold at the start of the requested window.
	 *
	 * Partial lease data → neutral (returns true). Same anchor rules as
	 * {@see self::scoreVehicle()} (lease start or vehicle `created_at`, lease end).
	 */
	public function passesMinRemainingLeaseKmBudget(array $vehicle, string $windowStartUtc): bool
	{
		$minPct = $this->settings->minRemainingLeaseKmPercent();
		if ($minPct <= 0) {
			return true;
		}
		$pct = $this->remainingLeaseKmHeadroomPercentAt($vehicle, $windowStartUtc);
		if ($pct === null) {
			return true;
		}
		return $pct >= (float)$minPct;
	}

	/**
	 * Headroom % of `lease_included_km` after linear pacing vs odometer delta, or null if not applicable.
	 */
	private function remainingLeaseKmHeadroomPercentAt(array $vehicle, string $windowStartUtc): ?float
	{
		$leaseKm = $vehicle['lease_included_km'] ?? $vehicle['lease_km_budget'] ?? null;
		if ($leaseKm === null || (int)$leaseKm <= 0) {
			return null;
		}
		$leaseKm = (int)$leaseKm;
		$leaseEnd = $vehicle['lease_end_date'] ?? null;
		$leaseStart = $vehicle['lease_start_date'] ?? null;
		if ($leaseEnd === null || $leaseEnd === '' || $leaseStart === null || $leaseStart === '') {
			return null;
		}
		$refDate = substr($windowStartUtc, 0, 10);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $refDate)) {
			return null;
		}
		$created = (string)($vehicle['created_at'] ?? 'now');
		$anchorStart = $leaseStart !== null && $leaseStart !== ''
			? strtotime((string)$leaseStart . ' UTC')
			: strtotime($created . ' UTC');
		$anchorEnd = strtotime((string)$leaseEnd . ' UTC');
		$refTs = strtotime($refDate . ' UTC');
		if ($anchorStart === false || $anchorEnd === false || $refTs === false || $anchorEnd <= $anchorStart) {
			return null;
		}
		$totalDays = max(1, (int)(($anchorEnd - $anchorStart) / 86400));
		$cappedRefTs = min($refTs, $anchorEnd);
		$elapsed = max(0, min($totalDays, (int)(($cappedRefTs - $anchorStart) / 86400)));
		$expectedUsed = (int)round($leaseKm * ($elapsed / $totalDays));
		$odo = (int)($vehicle['odometer_km'] ?? 0);
		$delta = $odo - $expectedUsed;
		$linearKmRemaining = (int)round($leaseKm * (($totalDays - $elapsed) / $totalDays));
		$headroomKm = $linearKmRemaining - $delta;
		if ($leaseKm <= 0) {
			return null;
		}
		return ($headroomKm / $leaseKm) * 100.0;
	}

	/**
	 * Score one vehicle for a candidate booking window. Higher is better.
	 *
	 * @param array<string,mixed> $vehicle
	 * @return array{score:int,reasons:list<string>}
	 */
	public function scoreVehicle(array $vehicle, string $startUtc, string $endUtc): array
	{
		$reasons = [];
		$w = $this->settings->intelligentAllocationWeights();
		$score = 0;

		$leaseEnd = $vehicle['lease_end_date'] ?? null;
		$leaseStart = $vehicle['lease_start_date'] ?? null;
		$leaseKm = $vehicle['lease_included_km'] ?? $vehicle['lease_km_budget'] ?? null;
		$odo = (int)($vehicle['odometer_km'] ?? 0);

		$sLease = 0;
		if ($leaseEnd !== null && $leaseKm !== null && (int)$leaseKm > 0) {
			$created = (string)($vehicle['created_at'] ?? 'now');
			$anchorStart = $leaseStart !== null && $leaseStart !== ''
				? strtotime((string)$leaseStart . ' UTC')
				: strtotime($created . ' UTC');
			$anchorEnd = strtotime((string)$leaseEnd . ' UTC');
			if ($anchorStart !== false && $anchorEnd !== false && $anchorEnd > $anchorStart) {
				$totalDays = max(1, (int)(($anchorEnd - $anchorStart) / 86400));
				$elapsed = max(0, min($totalDays, (int)((time() - $anchorStart) / 86400)));
				$expectedKm = (int)round(((int)$leaseKm) * ($elapsed / $totalDays));
				if ($odo < $expectedKm) {
					$sLease = 20;
					$reasons[] = 'lease_under_plan';
				} elseif ($odo > $expectedKm + 5000) {
					$sLease = -25;
					$reasons[] = 'lease_over_plan';
				}
			}
		}

		$bookingEndTs = strtotime(substr($endUtc, 0, 10) . ' UTC');
		$leaseEndTs = ($leaseEnd !== null && $leaseEnd !== '') ? strtotime((string)$leaseEnd . ' UTC') : false;
		$sLeaseHorizon = 0;
		if ($bookingEndTs !== false && $leaseEndTs !== false && $leaseEndTs > 0) {
			$daysLeft = (int)(($leaseEndTs - $bookingEndTs) / 86400);
			$minDays = $this->settings->minRemainingLeaseDaysForBooking();
			if ($minDays > 0 && $daysLeft < $minDays) {
				$sLeaseHorizon = -1000;
				$reasons[] = 'lease_too_close_to_contract_end';
			} elseif ($daysLeft >= 0 && $daysLeft < 14) {
				$sLeaseHorizon = -15;
				$reasons[] = 'lease_end_imminent';
			}
		}

		$year = (int)($vehicle['year'] ?? (int)date('Y'));
		$ageDelta = (int)date('Y') - $year;
		$sAge = 0;
		if ($ageDelta >= 5) {
			$sAge = min(15, $ageDelta - 4);
			$reasons[] = 'age_bias_to_use_older';
		}

		$recentTrips = $this->recentBookingCount((int)$vehicle['id']);
		$sUtil = 0;
		if ($recentTrips === 0) {
			$sUtil = 15;
			$reasons[] = 'low_utilisation';
		} elseif ($recentTrips >= 6) {
			$sUtil = -10;
			$reasons[] = 'high_utilisation';
		}

		$sOps = 0;
		if (($vehicle['status'] ?? '') === VehicleService::STATUS_BOOKED) {
			$sOps = -5;
			$reasons[] = 'currently_booked_elsewhere';
		}

		$score += ($w['wLease'] * $sLease) + ($w['wLease'] * $sLeaseHorizon) + ($w['wAge'] * $sAge) + ($w['wUtil'] * $sUtil) + ($w['wOps'] * $sOps);

		return ['score' => (int)$score, 'reasons' => $reasons];
	}

	/**
	 * Rank a candidate vehicle set for a window. Returns sorted descending
	 * by score, with each entry's score breakdown attached.
	 *
	 * @param list<array<string,mixed>> $vehicles
	 * @return list<array<string,mixed>>
	 */
	public function rank(array $vehicles, string $startUtc, string $endUtc): array
	{
		$ranked = [];
		foreach ($vehicles as $v) {
			if (!empty($v['do_not_auto_allocate'])) {
				continue;
			}
			$score = $this->scoreVehicle($v, $startUtc, $endUtc);
			$ranked[] = array_merge($v, ['allocation_score' => $score['score'], 'allocation_reasons' => $score['reasons']]);
		}
		usort($ranked, static function ($a, $b) {
			$cmp = ($b['allocation_score'] ?? 0) <=> ($a['allocation_score'] ?? 0);
			if ($cmp !== 0) {
				return $cmp;
			}
			return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
		});
		return $ranked;
	}

	private function recentBookingCount(int $vehicleId): int
	{
		$threshold = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->selectAlias($qb->func()->count('*'), 'c')->from('mc_bookings')
				->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gte('start_datetime', $qb->createNamedParameter($threshold)))
				->andWhere($qb->expr()->in('status', $qb->createNamedParameter([
					BookingService::STATUS_APPROVED,
					BookingService::STATUS_ACTIVE,
					BookingService::STATUS_COMPLETED,
				], IQueryBuilder::PARAM_STR_ARRAY)));
			return (int)($qb->executeQuery()->fetchOne() ?: 0);
		} catch (\Throwable) {
			return 0;
		}
	}
}
