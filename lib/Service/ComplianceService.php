<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * §4.4 + §4.3 — combined licence and yearly-instruction evaluator.
 *
 * `evaluate()` answers "may this user book this vehicle?" by joining
 * the driver profile, the latest verification, the current-year
 * instruction record, and the vehicle's required licence class.
 * Used by {@see BookingService} before allowing a new booking and by
 * the dashboard / compliance pages for listings.
 */
class ComplianceService
{
	public function __construct(
		private IDBConnection $db,
		private DriverService $drivers,
		private AuditLogService $audit,
	) {
	}

	/**
	 * @return array{
	 *   eligible:bool,
	 *   reasons:list<string>,
	 *   driver:?array<string,mixed>,
	 *   vehicle:?array<string,mixed>,
	 *   licenceVerified:bool,
	 *   instructionCurrent:bool,
	 *   licenceClassOk:bool,
	 *   daysToExpiry:?int,
	 * }
	 */
	public function evaluate(string $userId, int $vehicleId): array
	{
		$driver = $this->drivers->getByUserId($userId);
		$reasons = [];
		$eligible = true;
		$licenceVerified = false;
		$instructionCurrent = false;
		$licenceClassOk = true;
		$daysToExpiry = null;
		if ($driver === null) {
			$reasons[] = 'NO_DRIVER_PROFILE';
			$eligible = false;
		} else {
			$detail = $this->drivers->complianceDetail((int)$driver['id']);
			$licenceVerified = $driver['licence_status'] === DriverService::STATUS_VERIFIED;
			$instructionCurrent = (bool)($detail['currentYearInstructionComplete'] ?? false);
			$daysToExpiry = $detail['daysToExpiry'];
			if (!$licenceVerified) {
				$reasons[] = 'LICENCE_NOT_VERIFIED';
				$eligible = false;
			}
			if ($daysToExpiry !== null && $daysToExpiry < 0) {
				$reasons[] = 'LICENCE_EXPIRED';
				$eligible = false;
			}
			if (!$instructionCurrent) {
				$reasons[] = 'INSTRUCTION_MISSING_CURRENT_YEAR';
				$eligible = false;
			}
		}
		$vehicle = null;
		if ($vehicleId > 0) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'internal_name', 'required_licence_class', 'status')
				->from('mc_vehicles')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
			$row = $qb->executeQuery()->fetch();
			if ($row) {
				$vehicle = [
					'id' => (int)$row['id'],
					'internal_name' => (string)$row['internal_name'],
					'required_licence_class' => (string)$row['required_licence_class'],
					'status' => (string)$row['status'],
				];
				if ($driver !== null && $driver['licence_classes'] !== null) {
					$held = array_map('trim', array_filter(explode(',', (string)$driver['licence_classes'])));
					$required = trim($vehicle['required_licence_class']);
					$licenceClassOk = $required === '' || in_array($required, $held, true);
					if (!$licenceClassOk) {
						$reasons[] = 'LICENCE_CLASS_INSUFFICIENT';
						$eligible = false;
					}
				}
				if ($vehicle['status'] === VehicleService::STATUS_DECOMMISSIONED) {
					$reasons[] = 'VEHICLE_DECOMMISSIONED';
					$eligible = false;
				}
				// Any "in_maintenance" vehicle is operationally blocked. The state is used both for
				// scheduled blocking maintenance and for safety-critical damage auto-blocking.
				if ($vehicle['status'] === VehicleService::STATUS_IN_MAINTENANCE) {
					$reasons[] = 'VEHICLE_IN_MAINTENANCE';
					$eligible = false;
				}
			}
		}
		return [
			'eligible' => $eligible,
			'reasons' => $reasons,
			'driver' => $driver,
			'vehicle' => $vehicle,
			'licenceVerified' => $licenceVerified,
			'instructionCurrent' => $instructionCurrent,
			'licenceClassOk' => $licenceClassOk,
			'daysToExpiry' => $daysToExpiry,
		];
	}

	public function hasBlockingMaintenance(int $vehicleId): bool
	{
		$today = gmdate('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('mc_maintenance_schedules')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_blocking', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->andX(
				$qb->expr()->isNotNull('next_due_date'),
				$qb->expr()->lte('next_due_date', $qb->createNamedParameter($today)),
			))
			->setMaxResults(1);
		return $qb->executeQuery()->fetchOne() !== false;
	}

	/**
	 * §4.4 — record completion of the yearly Fahrunterweisung for a driver.
	 * Immutable row; corrections must add a new row in a different year
	 * or, for a correction in the same year, the policy is "amendment row"
	 * which is documented in §11.11 (rare break-glass; not exposed in UI).
	 */
	public function recordInstruction(
		int $driverProfileId,
		int $year,
		string $completedDate,
		string $recordedBy,
		?string $reference,
	): array {
		if ($year < 2000 || $year > (int)gmdate('Y') + 1) {
			throw new ValidationException('YEAR_INVALID', 'calendarYear');
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $completedDate)) {
			throw new ValidationException('DATE_INVALID', 'completedDate');
		}
		$todayUtc = gmdate('Y-m-d');
		if ($completedDate > $todayUtc) {
			throw new ValidationException('COMPLETED_DATE_IN_FUTURE', 'completedDate');
		}
		$refNorm = $reference === null ? '' : trim($reference);
		if ($refNorm !== '') {
			$refLen = function_exists('mb_strlen') ? mb_strlen($refNorm, 'UTF-8') : strlen($refNorm);
			if ($refLen > 120) {
				throw new ValidationException('INSTRUCTION_REFERENCE_TOO_LONG', 'reference', ['max' => 120]);
			}
		}
		$refForDb = $refNorm === '' ? null : $refNorm;
		$this->drivers->get($driverProfileId);
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('mc_instruction_records')->values([
				'driver_profile_id' => $qb->createNamedParameter($driverProfileId, IQueryBuilder::PARAM_INT),
				'calendar_year' => $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT),
				'completed_date' => $qb->createNamedParameter($completedDate),
				'recorded_by_user_id' => $qb->createNamedParameter($recordedBy),
				'reference' => $qb->createNamedParameter($refForDb),
				'recorded_at' => $qb->createNamedParameter(gmdate('Y-m-d H:i:s')),
			]);
			$qb->executeStatement();
		} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
			throw new ValidationException('INSTRUCTION_ALREADY_RECORDED');
		}
		$id = (int)$this->db->lastInsertId('mc_instruction_records');
		$this->audit->log('instruction', $id, 'create', $recordedBy, [
			'driver_profile_id' => $driverProfileId,
			'year' => $year,
			'completed_date' => $completedDate,
		]);
		$this->drivers->recomputeCompliance($driverProfileId);
		return [
			'id' => $id,
			'driverProfileId' => $driverProfileId,
			'year' => $year,
			'completedDate' => $completedDate,
			'recordedBy' => $recordedBy,
			'reference' => $refForDb,
		];
	}

	/**
	 * §7.2 GET /api/compliance/instructions?year= — list per-driver status
	 * for the calendar year.
	 *
	 * @param list<string>|null $driverUserIdsOnly When set, restrict to these Nextcloud user ids.
	 * @return list<array{driverProfileId:int,userId:string,status:string,completedDate:?string,recordedBy:?string,reference:?string}>
	 */
	public function listInstructionsByYear(int $year, ?array $driverUserIdsOnly = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'user_id')->from('mc_driver_profiles')->orderBy('user_id');
		if ($driverUserIdsOnly !== null) {
			$ids = array_values(array_filter($driverUserIdsOnly, static fn ($id) => is_string($id) && $id !== ''));
			if ($ids === []) {
				return [];
			}
			$qb->andWhere($qb->expr()->in('user_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		$res = $qb->executeQuery();
		$drivers = [];
		while (($r = $res->fetch()) !== false) {
			$drivers[(int)$r['id']] = (string)$r['user_id'];
		}
		$res->closeCursor();
		if ($drivers === []) {
			return [];
		}
		$qb2 = $this->db->getQueryBuilder();
		$qb2->select('driver_profile_id', 'completed_date', 'recorded_by_user_id', 'reference')
			->from('mc_instruction_records')
			->where($qb2->expr()->in('driver_profile_id', $qb2->createNamedParameter(array_keys($drivers), IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb2->expr()->eq('calendar_year', $qb2->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		$res2 = $qb2->executeQuery();
		$recorded = [];
		while (($r = $res2->fetch()) !== false) {
			$recorded[(int)$r['driver_profile_id']] = $r;
		}
		$res2->closeCursor();
		$out = [];
		foreach ($drivers as $id => $userId) {
			$row = $recorded[$id] ?? null;
			$out[] = [
				'driverProfileId' => $id,
				'userId' => $userId,
				'status' => $row !== null ? 'completed' : 'not_completed',
				'completedDate' => $row !== null ? substr((string)$row['completed_date'], 0, 10) : null,
				'recordedBy' => $row !== null ? (string)$row['recorded_by_user_id'] : null,
				'reference' => $row !== null && $row['reference'] !== null ? (string)$row['reference'] : null,
			];
		}
		return $out;
	}

	/**
	 * §7.2 GET /api/compliance/licences — list every driver with
	 * licence status + days-to-expiry. Read-only for managers / auditors.
	 *
	 * @param list<string>|null $driverUserIdsOnly When set, restrict to these Nextcloud user ids.
	 * @return list<array<string,mixed>>
	 */
	public function listLicences(?array $driverUserIdsOnly = null): array
	{
		$drivers = $this->drivers->list($driverUserIdsOnly);
		$today = gmdate('Y-m-d');
		$out = [];
		foreach ($drivers as $d) {
			$days = null;
			if ($d['licence_expiry_date'] !== null) {
				$days = (int)floor((strtotime($d['licence_expiry_date']) - strtotime($today)) / 86400);
			}
			$out[] = [
				'id' => $d['id'],
				'userId' => $d['user_id'],
				'licenceStatus' => $d['licence_status'],
				'licenceExpiryDate' => $d['licence_expiry_date'],
				'licenceClasses' => $d['licence_classes'],
				'complianceStatus' => $d['compliance_status'],
				'daysToExpiry' => $days,
				'scanFileId' => $d['licence_scan_file_id'],
			];
		}
		return $out;
	}

	public function recomputeAll(): void
	{
		foreach ($this->drivers->list() as $d) {
			try {
				$this->drivers->recomputeCompliance((int)$d['id']);
			} catch (NotFoundException) {
				// ignore — driver vanished between list and recompute
			}
		}
	}
}
