<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Appendix A5 — availability-aware vehicle discovery for booking slots. */
class VehicleSearchService
{
	public function __construct(
		private IDBConnection $db,
		private VehicleService $vehicles,
		private VehicleAssignmentService $assignments,
		private AccessControlService $access,
		private SettingsService $settings,
		private DriverService $drivers,
		private AllocationService $allocation,
	) {
	}

	/**
	 * §A5.4 — Replacement pool for automation. **Caller** must only invoke
	 * from trusted fleet code (background job). No HTTP actor checks.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function eligibleVehiclesForDriverWindow(
		string $driverUserId,
		string $fromUtc,
		string $toUtc,
		array $requirements = [],
		?int $excludeVehicleId = null,
	): array {
		if ($fromUtc === '' || $toUtc === '') {
			throw new ValidationException('WINDOW_REQUIRED');
		}
		if (strtotime($fromUtc . ' UTC') >= strtotime($toUtc . ' UTC')) {
			throw new ValidationException('WINDOW_INVALID');
		}
		return $this->collectMatchesForSubject($driverUserId, $fromUtc, $toUtc, $requirements, $excludeVehicleId);
	}

	/**
	 * @param array<string,mixed> $requirements arbitrary JSON filters (best-effort)
	 * @return array{
	 *   matches:list<array<string,mixed>>,
	 *   alternatives:list<array<string,mixed>>,
	 *   failedConstraints:list<string>,
	 *   recommendedVehicleId?:?int,
	 *   allocationSummary?:list<string>
	 * }
	 */
	public function search(string $actorUserId, string $fromUtc, string $toUtc, array $requirements = [], ?string $subjectUserId = null): array
	{
		$subject = ($subjectUserId !== null && trim($subjectUserId) !== '') ? trim($subjectUserId) : $actorUserId;
		$this->access->requireAnyAppRole($actorUserId);
		if ($subject !== $actorUserId) {
			if (!$this->access->isFleetAdminOrManager($actorUserId) && !$this->access->isAppAdmin($actorUserId)) {
				throw new ForbiddenException('INSUFFICIENT_ROLE');
			}
			if (!$this->access->isDriver($subject)) {
				throw new ValidationException('SUBJECT_NOT_DRIVER');
			}
		} else {
			$this->access->requireDriver($actorUserId);
		}
		if ($fromUtc === '' || $toUtc === '') {
			throw new ValidationException('WINDOW_REQUIRED');
		}
		if (strtotime($fromUtc . ' UTC') >= strtotime($toUtc . ' UTC')) {
			throw new ValidationException('WINDOW_INVALID');
		}
		$matches = $this->collectMatchesForSubject($subject, $fromUtc, $toUtc, $requirements, null);
		$failed = $this->lastFailedConstraintKeys;
		$out = [
			'matches' => $matches,
			'alternatives' => [],
			'failedConstraints' => array_values(array_unique($failed)),
		];
		if ($this->settings->intelligentAllocationEnabled() && $matches !== []) {
			$policy = $this->settings->vehicleChoicePolicy();
			$ranked = $this->allocation->rank($matches, $fromUtc, $toUtc);
			$out['matches'] = $ranked;
			$top = $ranked[0] ?? null;
			if (is_array($top) && isset($top['id'])) {
				$out['recommendedVehicleId'] = (int)$top['id'];
				$out['allocationSummary'] = array_values(array_filter(array_map(
					static fn ($r) => is_string($r) ? $r : null,
					$top['allocation_reasons'] ?? [],
				)));
			}
			if ($policy === 'auto_assign_no_choice' && isset($out['recommendedVehicleId'])) {
				foreach ($out['matches'] as &$m) {
					$m['allocation_locked_choice'] = ((int)($m['id'] ?? 0)) === $out['recommendedVehicleId'];
				}
				unset($m);
			}
		}
		return $out;
	}

	/** @var list<string> */
	private array $lastFailedConstraintKeys = [];

	/**
	 * @return list<array<string,mixed>>
	 */
	private function collectMatchesForSubject(
		string $subject,
		string $fromUtc,
		string $toUtc,
		array $requirements,
		?int $excludeVehicleId,
	): array {
		$this->lastFailedConstraintKeys = [];
		$stationFilterIds = null;
		if (isset($requirements['stationIds']) && is_array($requirements['stationIds'])) {
			$stationFilterIds = array_values(array_unique(array_filter(
				array_map(static fn ($x) => (int)$x, $requirements['stationIds']),
				static fn ($x) => $x > 0,
			)));
			if ($stationFilterIds === []) {
				$stationFilterIds = null;
			}
		}
		$list = $this->vehicles->list(['activeOnly' => true]);
		$matches = [];
		foreach ($list as $v) {
			$id = (int)$v['id'];
			if ($excludeVehicleId !== null && $id === $excludeVehicleId) {
				continue;
			}
			$st = (string)($v['status'] ?? '');
			if ($st === VehicleService::STATUS_DECOMMISSIONED || $st === VehicleService::STATUS_IN_MAINTENANCE) {
				continue;
			}
			try {
				$this->assignments->assertUserMayBookVehicle($subject, $id);
			} catch (\Throwable) {
				continue;
			}
			if (!$this->assignments->userMaySeeVehicle($subject, $id)) {
				continue;
			}
			if (!$this->stationVisibilityAllows($subject, $v)) {
				continue;
			}
			if ($stationFilterIds !== null) {
				$sid = isset($v['station_id']) && $v['station_id'] !== null ? (int)$v['station_id'] : null;
				if ($sid === null || !in_array($sid, $stationFilterIds, true)) {
					continue;
				}
			}
			if (!$this->allocation->leaseAllowsBookingThrough($v, $toUtc)) {
				$this->lastFailedConstraintKeys[] = 'vehicle_' . $id . '_lease_end';
				continue;
			}
			if (!$this->allocation->passesMinRemainingLeaseKmBudget($v, $fromUtc)) {
				$this->lastFailedConstraintKeys[] = 'vehicle_' . $id . '_lease_km_headroom';
				continue;
			}
			if ($this->bookingOverlaps($id, $fromUtc, $toUtc)) {
				$this->lastFailedConstraintKeys[] = 'vehicle_' . $id . '_booked';
				continue;
			}
			if (!$this->meetsFeatureRequirements($id, $requirements)) {
				$this->lastFailedConstraintKeys[] = 'vehicle_' . $id . '_features';
				continue;
			}
			$a = $this->assignments->getActiveAssignment($id);
			$v['assignment'] = $a;
			$matches[] = $v;
		}
		return $matches;
	}

	/** @param array<string,mixed> $vehicle row from {@see VehicleService::list()} */
	private function stationVisibilityAllows(string $subjectUserId, array $vehicle): bool
	{
		if (!$this->settings->stationStrictMode()) {
			return true;
		}
		$p = $this->drivers->getByUserId($subjectUserId);
		if ($p === null || !isset($p['home_station_id']) || $p['home_station_id'] === null) {
			return true;
		}
		$home = (int)$p['home_station_id'];
		$sid = isset($vehicle['station_id']) && $vehicle['station_id'] !== null ? (int)$vehicle['station_id'] : null;
		if ($sid === null) {
			return true;
		}
		return $sid === $home;
	}

	private function bookingOverlaps(int $vehicleId, string $startUtc, string $endUtc): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'c')->from('mc_bookings')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('start_datetime', $qb->createNamedParameter($endUtc)))
			->andWhere($qb->expr()->gt('end_datetime', $qb->createNamedParameter($startUtc)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('status', $qb->createNamedParameter(BookingService::STATUS_APPROVED)),
				$qb->expr()->eq('status', $qb->createNamedParameter(BookingService::STATUS_ACTIVE)),
				$qb->expr()->like('status', $qb->createNamedParameter('pending%', IQueryBuilder::PARAM_STR)),
			));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	/** @param array<string,mixed> $requirements */
	private function meetsFeatureRequirements(int $vehicleId, array $requirements): bool
	{
		if ($requirements === []) {
			return true;
		}
		$minSeats = isset($requirements['minSeats']) ? (int)$requirements['minSeats'] : 0;
		if ($minSeats <= 0) {
			return true;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('seating_capacity')->from('mc_vehicles')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
		$cap = (int)($qb->executeQuery()->fetchOne() ?: 0);
		return $cap >= $minSeats;
	}
}
