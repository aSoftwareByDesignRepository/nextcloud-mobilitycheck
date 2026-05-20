<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Appendix A4/A6 — statutory tier reimbursement rates per jurisdiction.
 */
class ReimbursementRateService
{
	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private AuditLogService $audit,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function listActive(?string $jurisdiction = null, ?string $vehicleType = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_reim_rate_cfg')->orderBy('jurisdiction_code')->addOrderBy('vehicle_type')->addOrderBy('rate_tier');
		if ($jurisdiction !== null && $jurisdiction !== '') {
			$qb->andWhere($qb->expr()->eq('jurisdiction_code', $qb->createNamedParameter($jurisdiction)));
		}
		if ($vehicleType !== null && $vehicleType !== '') {
			$qb->andWhere($qb->expr()->eq('vehicle_type', $qb->createNamedParameter($vehicleType)));
		}
		$today = gmdate('Y-m-d');
		$qb->andWhere($qb->expr()->lte('valid_from', $qb->createNamedParameter($today)));
		$qb->andWhere($qb->expr()->orX(
			$qb->expr()->isNull('valid_until'),
			$qb->expr()->gte('valid_until', $qb->createNamedParameter($today)),
		));
		$out = [];
		$res = $qb->executeQuery();
		while (($r = $res->fetch()) !== false) {
			$out[] = $this->hydrateRow($r);
		}
		$res->closeCursor();
		return $out;
	}

	/**
	 * Computes statutory kilometres reimbursement using tiered rows:
	 * first tier applies to the first `tier_threshold_km` kilometres when set,
	 * remainder uses subsequent tiers.
	 *
	 * @return array{amount_claimable_minor:int,amount_taxable_minor:int,effective_rate_per_km_minor:int,jurisdiction_code:string}
	 */
	public function computeStatutoryAmount(string $jurisdiction, string $vehicleType, string $tripDate, int $distanceKm): array
	{
		if ($distanceKm <= 0) {
			throw new ValidationException('DISTANCE_INVALID');
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tripDate)) {
			throw new ValidationException('TRIP_DATE_INVALID');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_reim_rate_cfg')
			->where($qb->expr()->eq('jurisdiction_code', $qb->createNamedParameter($jurisdiction)))
			->andWhere($qb->expr()->eq('vehicle_type', $qb->createNamedParameter($vehicleType)))
			->andWhere($qb->expr()->lte('valid_from', $qb->createNamedParameter($tripDate)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('valid_until'),
				$qb->expr()->gte('valid_until', $qb->createNamedParameter($tripDate)),
			))
			->orderBy('rate_tier', 'ASC');
		$tiers = [];
		$res = $qb->executeQuery();
		while (($r = $res->fetch()) !== false) {
			$tiers[] = $this->hydrateRow($r);
		}
		$res->closeCursor();
		if ($tiers === []) {
			throw new ValidationException('REIMBURSEMENT_RATE_NOT_CONFIGURED');
		}

		$remaining = $distanceKm;
		$amount = 0;
		$tier1 = $tiers[0];
		$thresh = $tier1['tier_threshold_km'];
		$rate1 = (int)$tier1['rate_per_km_minor'];
		if ($thresh !== null && $thresh > 0) {
			$chunk = min($remaining, $thresh);
			$amount += $chunk * $rate1;
			$remaining -= $chunk;
		} else {
			$amount += $remaining * $rate1;
			$remaining = 0;
		}
		if ($remaining > 0 && isset($tiers[1])) {
			$rate2 = (int)$tiers[1]['rate_per_km_minor'];
			$amount += $remaining * $rate2;
			$remaining = 0;
		} elseif ($remaining > 0) {
			$amount += $remaining * $rate1;
			$remaining = 0;
		}

		$effectiveRate = (int) MobilityCheckMoney::roundHalfUp($amount / $distanceKm);

		return [
			'amount_claimable_minor' => $amount,
			'amount_taxable_minor' => 0,
			'effective_rate_per_km_minor' => $effectiveRate,
			'jurisdiction_code' => $jurisdiction,
		];
	}

	/** @param array<string,mixed> $payload */
	public function adminCreateTier(array $payload, string $by): array
	{
		$this->access->requireFleetAdminOrManager($by);
		$jc = strtoupper(trim((string)($payload['jurisdictionCode'] ?? $payload['jurisdiction_code'] ?? '')));
		$vt = trim((string)($payload['vehicleType'] ?? $payload['vehicle_type'] ?? ''));
		if ($jc === '' || strlen($jc) > 10) {
			throw new ValidationException('JURISDICTION_INVALID');
		}
		if (!in_array($vt, ['car', 'electric', 'hybrid', 'motorcycle'], true)) {
			throw new ValidationException('VEHICLE_TYPE_INVALID');
		}
		$tier = (int)($payload['rateTier'] ?? $payload['rate_tier'] ?? 0);
		if ($tier < 1 || $tier > 10) {
			throw new ValidationException('RATE_TIER_INVALID');
		}
		$thresh = $payload['tierThresholdKm'] ?? $payload['tier_threshold_km'] ?? null;
		$thresh = $thresh !== null && $thresh !== '' ? (int)$thresh : null;
		$rate = (int)($payload['ratePerKmMinor'] ?? $payload['rate_per_km_minor'] ?? -1);
		if ($rate < 0) {
			throw new ValidationException('RATE_INVALID');
		}
		$validFrom = trim((string)($payload['validFrom'] ?? $payload['valid_from'] ?? gmdate('Y-m-d')));
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom)) {
			throw new ValidationException('DATE_INVALID');
		}
		$now = gmdate('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('mc_reim_rate_cfg')->values([
			'jurisdiction_code' => $ins->createNamedParameter($jc),
			'vehicle_type' => $ins->createNamedParameter($vt),
			'rate_tier' => $ins->createNamedParameter($tier, IQueryBuilder::PARAM_INT),
			'tier_threshold_km' => $thresh !== null
				? $ins->createNamedParameter($thresh, IQueryBuilder::PARAM_INT)
				: $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'rate_per_km_minor' => $ins->createNamedParameter($rate, IQueryBuilder::PARAM_INT),
			'taxable_above_minor' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'valid_from' => $ins->createNamedParameter($validFrom),
			'valid_until' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'created_by_user_id' => $ins->createNamedParameter($by),
			'created_at' => $ins->createNamedParameter($now),
		]);
		$ins->executeStatement();
		$id = (int)$this->db->lastInsertId('mc_reim_rate_cfg');
		$this->audit->log('reimbursement_rate', $id, 'create', $by, ['jc' => $jc, 'vehicle_type' => $vt]);
		return ['id' => $id];
	}

	/** @param array<string,mixed> $row */
	private function hydrateRow(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'jurisdiction_code' => (string)$row['jurisdiction_code'],
			'vehicle_type' => (string)$row['vehicle_type'],
			'rate_tier' => (int)$row['rate_tier'],
			'tier_threshold_km' => isset($row['tier_threshold_km']) && $row['tier_threshold_km'] !== null ? (int)$row['tier_threshold_km'] : null,
			'rate_per_km_minor' => (int)$row['rate_per_km_minor'],
			'taxable_above_minor' => isset($row['taxable_above_minor']) && $row['taxable_above_minor'] !== null ? (int)$row['taxable_above_minor'] : null,
			'valid_from' => substr((string)$row['valid_from'], 0, 10),
			'valid_until' => isset($row['valid_until']) && $row['valid_until'] !== null ? substr((string)$row['valid_until'], 0, 10) : null,
			'created_by_user_id' => (string)$row['created_by_user_id'],
			'created_at' => (string)$row['created_at'],
		];
	}
}
