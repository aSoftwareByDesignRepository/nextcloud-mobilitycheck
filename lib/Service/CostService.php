<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * §4.8 — Cost and expense management.
 *
 * Money is stored as integer minor units (Euro cents) — see {@see MobilityCheckMoney}.
 * Net / VAT / gross are server-computed; the API accepts either a decimal
 * gross string or a minor int value. VAT split uses Kaufmännische Rundung
 * (half-up) so `net + vat = gross` is always exact (§11.12).
 *
 * Cost entries are soft-deletable by fleet admins with a mandatory reason
 * (§7.2 — fleet admin only).
 */
class CostService
{
	public function __construct(
		private IDBConnection $db,
		private VehicleService $vehicles,
		private AccessControlService $access,
		private AuditLogService $audit,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function list(array $filters = []): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_cost_entries')
			->where($qb->expr()->eq('is_deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('entry_date', 'DESC')
			->addOrderBy('id', 'DESC');
		if (!empty($filters['vehicleId'])) {
			$qb->andWhere($qb->expr()->eq('vehicle_id', $qb->createNamedParameter((int)$filters['vehicleId'], IQueryBuilder::PARAM_INT)));
		} elseif (!empty($filters['vehicleIds']) && is_array($filters['vehicleIds'])) {
			$vids = array_values(array_filter(array_map('intval', $filters['vehicleIds']), static fn ($v) => $v > 0));
			if ($vids !== []) {
				$qb->andWhere($qb->expr()->in('vehicle_id', $qb->createNamedParameter($vids, IQueryBuilder::PARAM_INT_ARRAY)));
			}
		}
		if (!empty($filters['categoryId'])) {
			$qb->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter((int)$filters['categoryId'], IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filters['from'])) {
			$qb->andWhere($qb->expr()->gte('entry_date', $qb->createNamedParameter((string)$filters['from'])));
		}
		if (!empty($filters['to'])) {
			$qb->andWhere($qb->expr()->lte('entry_date', $qb->createNamedParameter((string)$filters['to'])));
		}
		$qb->setMaxResults(min(1000, max(1, (int)($filters['limit'] ?? 500))));
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = $this->hydrate($r);
		}
		$res->closeCursor();
		return $this->decorate($out);
	}

	public function get(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_cost_entries')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if (!$row || (int)$row['is_deleted'] === 1) {
			throw new NotFoundException('COST_ENTRY_NOT_FOUND');
		}
		$decorated = $this->decorate([$this->hydrate($row)]);
		return $decorated[0];
	}

	/**
	 * Attach `vehicle_internal_name`, `vehicle_licence_plate` and
	 * `category_name` to each entry so the frontend list table can render
	 * meaningful labels without a second roundtrip.
	 *
	 * @param list<array<string,mixed>> $rows
	 * @return list<array<string,mixed>>
	 */
	private function decorate(array $rows): array
	{
		if ($rows === []) {
			return $rows;
		}
		$vehicleIds = array_map(static fn ($r) => (int)($r['vehicle_id'] ?? 0), $rows);
		$summaries = $this->vehicles->summariesByIds($vehicleIds);
		$catIds = array_values(array_unique(array_filter(array_map(static fn ($r) => (int)($r['category_id'] ?? 0), $rows))));
		$cats = [];
		if ($catIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'name')->from('mc_cost_categories')
				->where($qb->expr()->in('id', $qb->createNamedParameter($catIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$res = $qb->executeQuery();
			while (($r = $res->fetch()) !== false) {
				$cats[(int)$r['id']] = (string)$r['name'];
			}
			$res->closeCursor();
		}
		foreach ($rows as &$r) {
			$v = $summaries[(int)($r['vehicle_id'] ?? 0)] ?? null;
			$r['vehicle_internal_name'] = $v['internal_name'] ?? null;
			$r['vehicle_licence_plate'] = $v['licence_plate'] ?? null;
			$r['category_name'] = $cats[(int)($r['category_id'] ?? 0)] ?? null;
		}
		return $rows;
	}

	/** @param array<string,mixed> $payload */
	public function create(array $payload, string $performedBy): array
	{
		$this->access->requireFleetAdminOrManager($performedBy);
		$data = $this->validate($payload, isCreate: true);
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('mc_cost_entries')->values([
			'vehicle_id' => $qb->createNamedParameter($data['vehicle_id'], IQueryBuilder::PARAM_INT),
			'booking_id' => $data['booking_id'] !== null
				? $qb->createNamedParameter($data['booking_id'], IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'repair_job_id' => $data['repair_job_id'] !== null
				? $qb->createNamedParameter($data['repair_job_id'], IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'category_id' => $qb->createNamedParameter($data['category_id'], IQueryBuilder::PARAM_INT),
			'entry_date' => $qb->createNamedParameter($data['entry_date']),
			'amount_gross_minor' => $qb->createNamedParameter($data['amount_gross_minor'], IQueryBuilder::PARAM_INT),
			'vat_rate_bp' => $qb->createNamedParameter($data['vat_rate_bp'], IQueryBuilder::PARAM_INT),
			'amount_net_minor' => $qb->createNamedParameter($data['amount_net_minor'], IQueryBuilder::PARAM_INT),
			'vat_amount_minor' => $qb->createNamedParameter($data['vat_amount_minor'], IQueryBuilder::PARAM_INT),
			'receipt_reference' => $qb->createNamedParameter($data['receipt_reference']),
			'notes' => $qb->createNamedParameter($data['notes']),
			'is_deleted' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'created_by_user_id' => $qb->createNamedParameter($performedBy),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
		]);
		$qb->executeStatement();
		$id = (int)$this->db->lastInsertId('mc_cost_entries');
		$this->audit->log('cost_entry', $id, 'create', $performedBy, [
			'vehicle_id' => $data['vehicle_id'],
			'amount_gross_minor' => $data['amount_gross_minor'],
			'category_id' => $data['category_id'],
		]);
		return $this->get($id);
	}

	/** @param array<string,mixed> $payload */
	public function update(int $id, array $payload, string $performedBy): array
	{
		$this->access->requireFleetAdminOrManager($performedBy);
		$current = $this->get($id);
		$data = $this->validate(array_merge($current, $payload), isCreate: false);
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_cost_entries')
			->set('vehicle_id', $qb->createNamedParameter($data['vehicle_id'], IQueryBuilder::PARAM_INT))
			->set('category_id', $qb->createNamedParameter($data['category_id'], IQueryBuilder::PARAM_INT))
			->set('entry_date', $qb->createNamedParameter($data['entry_date']))
			->set('amount_gross_minor', $qb->createNamedParameter($data['amount_gross_minor'], IQueryBuilder::PARAM_INT))
			->set('vat_rate_bp', $qb->createNamedParameter($data['vat_rate_bp'], IQueryBuilder::PARAM_INT))
			->set('amount_net_minor', $qb->createNamedParameter($data['amount_net_minor'], IQueryBuilder::PARAM_INT))
			->set('vat_amount_minor', $qb->createNamedParameter($data['vat_amount_minor'], IQueryBuilder::PARAM_INT))
			->set('receipt_reference', $qb->createNamedParameter($data['receipt_reference']))
			->set('notes', $qb->createNamedParameter($data['notes']))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('cost_entry', $id, 'update', $performedBy, [
			'amount_gross_minor' => [$current['amount_gross_minor'], $data['amount_gross_minor']],
			'category_id' => [$current['category_id'], $data['category_id']],
		]);
		return $this->get($id);
	}

	public function softDelete(int $id, string $performedBy, string $reason): void
	{
		$this->access->requireFleetAdmin($performedBy);
		$reason = trim($reason);
		if (mb_strlen($reason) < 5) {
			throw new ValidationException('DELETE_REASON_REQUIRED', 'reason');
		}
		$current = $this->get($id);
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_cost_entries')
			->set('is_deleted', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->set('delete_reason', $qb->createNamedParameter($reason))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('cost_entry', $id, 'soft_delete', $performedBy, [
			'amount_gross_minor' => $current['amount_gross_minor'],
		], $reason);
	}

	/**
	 * §4.8 fleet summary — totals per vehicle / per category for the
	 * given window. Returns flat arrays for the Reports page.
	 *
	 * @return array{
	 *   total:int,
	 *   byVehicle:list<array{vehicleId:int,total:int,internalName:string}>,
	 *   byCategory:list<array{categoryId:int,total:int,name:string}>,
	 * }
	 */
	public function summary(?string $from, ?string $to, ?int $vehicleId, ?array $vehicleIdsIn = null): array
	{
		$where = function (IQueryBuilder $qb) use ($from, $to, $vehicleId, $vehicleIdsIn): void {
			$qb->where($qb->expr()->eq('is_deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
			if ($from !== null && $from !== '') {
				$qb->andWhere($qb->expr()->gte('entry_date', $qb->createNamedParameter($from)));
			}
			if ($to !== null && $to !== '') {
				$qb->andWhere($qb->expr()->lte('entry_date', $qb->createNamedParameter($to)));
			}
			if ($vehicleId !== null) {
				$qb->andWhere($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
			} elseif ($vehicleIdsIn !== null) {
				$vids = array_values(array_filter(array_map('intval', $vehicleIdsIn), static fn ($v) => $v > 0));
				if ($vids !== []) {
					$qb->andWhere($qb->expr()->in('vehicle_id', $qb->createNamedParameter($vids, IQueryBuilder::PARAM_INT_ARRAY)));
				} else {
					$qb->andWhere($qb->expr()->eq('vehicle_id', $qb->createNamedParameter(-1, IQueryBuilder::PARAM_INT)));
				}
			}
		};

		$qb1 = $this->db->getQueryBuilder();
		$qb1->select($qb1->func()->sum('amount_gross_minor'))->from('mc_cost_entries');
		$where($qb1);
		$total = (int)($qb1->executeQuery()->fetchOne() ?: 0);

		$qb2 = $this->db->getQueryBuilder();
		$qb2->select('vehicle_id', $qb2->func()->sum('amount_gross_minor', 'tot'))
			->from('mc_cost_entries')
			->groupBy('vehicle_id');
		$where($qb2);
		$res = $qb2->executeQuery();
		$byVehicle = [];
		$vehicleNames = [];
		while (($r = $res->fetch()) !== false) {
			$vid = (int)$r['vehicle_id'];
			try {
				$v = $this->vehicles->get($vid);
				$vehicleNames[$vid] = $v['internal_name'];
			} catch (NotFoundException) {
				$vehicleNames[$vid] = 'Vehicle #' . $vid;
			}
			$byVehicle[] = [
				'vehicleId' => $vid,
				'total' => (int)$r['tot'],
				'internalName' => $vehicleNames[$vid],
			];
		}
		$res->closeCursor();
		usort($byVehicle, fn ($a, $b) => $b['total'] <=> $a['total']);

		$qb3 = $this->db->getQueryBuilder();
		$qb3->select('category_id', $qb3->func()->sum('amount_gross_minor', 'tot'))
			->from('mc_cost_entries')
			->groupBy('category_id');
		$where($qb3);
		$res = $qb3->executeQuery();
		$byCategory = [];
		$cats = $this->listCategories();
		$catNames = [];
		foreach ($cats as $c) {
			$catNames[$c['id']] = $c['name'];
		}
		while (($r = $res->fetch()) !== false) {
			$cid = (int)$r['category_id'];
			$byCategory[] = [
				'categoryId' => $cid,
				'total' => (int)$r['tot'],
				'name' => $catNames[$cid] ?? ('Category #' . $cid),
			];
		}
		$res->closeCursor();
		usort($byCategory, fn ($a, $b) => $b['total'] <=> $a['total']);

		return [
			'total' => $total,
			'byVehicle' => $byVehicle,
			'byCategory' => $byCategory,
		];
	}

	/** @return list<array<string,mixed>> */
	public function listCategories(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_cost_categories')->orderBy('name', 'ASC');
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = [
				'id' => (int)$r['id'],
				'name' => (string)$r['name'],
				'isActive' => (int)$r['is_active'] === 1,
			];
		}
		$res->closeCursor();
		return $out;
	}

	public function createCategory(string $name, string $performedBy): array
	{
		$this->access->requireFleetAdminOrManager($performedBy);
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 80) {
			throw new ValidationException('NAME_INVALID', 'name');
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('mc_cost_categories')->values([
				'name' => $qb->createNamedParameter($name),
				'is_active' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			]);
			$qb->executeStatement();
		} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
			throw new ValidationException('CATEGORY_TAKEN', 'name');
		}
		$id = (int)$this->db->lastInsertId('mc_cost_categories');
		$this->audit->log('cost_category', $id, 'create', $performedBy, ['name' => $name]);
		return ['id' => $id, 'name' => $name, 'isActive' => true];
	}

	public function updateCategory(int $id, array $payload, string $performedBy): array
	{
		$this->access->requireFleetAdminOrManager($performedBy);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_cost_categories')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if (!$row) {
			throw new NotFoundException('CATEGORY_NOT_FOUND');
		}
		$name = isset($payload['name']) ? trim((string)$payload['name']) : (string)$row['name'];
		if ($name === '' || mb_strlen($name) > 80) {
			throw new ValidationException('NAME_INVALID', 'name');
		}
		$isActive = isset($payload['isActive']) ? (bool)$payload['isActive'] : ((int)$row['is_active'] === 1);
		try {
			$upd = $this->db->getQueryBuilder();
			$upd->update('mc_cost_categories')
				->set('name', $upd->createNamedParameter($name))
				->set('is_active', $upd->createNamedParameter($isActive ? 1 : 0, IQueryBuilder::PARAM_INT))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$upd->executeStatement();
		} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
			throw new ValidationException('CATEGORY_TAKEN', 'name');
		}
		$this->audit->log('cost_category', $id, 'update', $performedBy, [
			'name' => [$row['name'], $name],
			'is_active' => [(int)$row['is_active'] === 1, $isActive],
		]);
		return ['id' => $id, 'name' => $name, 'isActive' => $isActive];
	}

	private function validate(array $p, bool $isCreate): array
	{
		$vehicleId = (int)($p['vehicleId'] ?? $p['vehicle_id'] ?? 0);
		if ($vehicleId <= 0) {
			throw new ValidationException('VEHICLE_REQUIRED', 'vehicle_id');
		}
		// Validate the vehicle exists (raises NotFoundException → 404).
		$this->vehicles->get($vehicleId);

		$bookingId = $p['bookingId'] ?? $p['booking_id'] ?? null;
		$bookingId = ($bookingId !== null && $bookingId !== '') ? (int)$bookingId : null;
		$repairJobId = $p['repairJobId'] ?? $p['repair_job_id'] ?? null;
		$repairJobId = ($repairJobId !== null && $repairJobId !== '') ? (int)$repairJobId : null;

		$categoryId = (int)($p['categoryId'] ?? $p['category_id'] ?? 0);
		if ($categoryId <= 0) {
			throw new ValidationException('CATEGORY_REQUIRED', 'category_id');
		}
		// Validate the category exists & is active.
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'is_active')->from('mc_cost_categories')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)));
		$cat = $qb->executeQuery()->fetch();
		if (!$cat) {
			throw new ValidationException('CATEGORY_NOT_FOUND', 'category_id');
		}
		if ((int)$cat['is_active'] !== 1 && $isCreate) {
			throw new ValidationException('CATEGORY_INACTIVE', 'category_id');
		}

		$entryDate = trim((string)($p['entryDate'] ?? $p['entry_date'] ?? ''));
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
			throw new ValidationException('DATE_INVALID', 'entry_date');
		}
		[$y, $m, $d] = array_map('intval', explode('-', $entryDate));
		if (!checkdate($m, $d, $y)) {
			throw new ValidationException('DATE_INVALID', 'entry_date');
		}

		// Gross can be supplied as decimal string ("12,34") or as minor units (int).
		if (array_key_exists('amountGrossDecimal', $p) || array_key_exists('amount_gross_decimal', $p)) {
			$gross = MobilityCheckMoney::decimalToMinor((string)($p['amountGrossDecimal'] ?? $p['amount_gross_decimal']));
		} elseif (array_key_exists('amountGrossMinor', $p) || array_key_exists('amount_gross_minor', $p)) {
			$gross = (int)($p['amountGrossMinor'] ?? $p['amount_gross_minor']);
		} else {
			throw new ValidationException('AMOUNT_REQUIRED', 'amount_gross_minor');
		}
		if ($gross < 0) {
			throw new ValidationException('MONEY_NEGATIVE_NOT_ALLOWED', 'amount_gross_minor');
		}
		$vatRateBp = (int)($p['vatRateBp'] ?? $p['vat_rate_bp'] ?? 1900);
		if (!in_array($vatRateBp, MobilityCheckMoney::VAT_RATE_BP_VALID, true) && ($vatRateBp < 0 || $vatRateBp > 9999)) {
			throw new ValidationException('VAT_RATE_INVALID', 'vat_rate_bp');
		}
		$split = MobilityCheckMoney::splitVat($gross, $vatRateBp);

		$receiptRef = $this->stringOrNull($p['receiptReference'] ?? $p['receipt_reference'] ?? null, 128);
		$notes = isset($p['notes']) && trim((string)$p['notes']) !== '' ? (string)$p['notes'] : null;

		return [
			'vehicle_id' => $vehicleId,
			'booking_id' => $bookingId,
			'repair_job_id' => $repairJobId,
			'category_id' => $categoryId,
			'entry_date' => $entryDate,
			'amount_gross_minor' => $gross,
			'vat_rate_bp' => $vatRateBp,
			'amount_net_minor' => $split['net'],
			'vat_amount_minor' => $split['vat'],
			'receipt_reference' => $receiptRef,
			'notes' => $notes,
		];
	}

	private function stringOrNull(mixed $v, int $max): ?string
	{
		if ($v === null) return null;
		$s = trim((string)$v);
		if ($s === '') return null;
		if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
		return $s;
	}

	private function hydrate(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'vehicle_id' => (int)$row['vehicle_id'],
			'booking_id' => $row['booking_id'] !== null ? (int)$row['booking_id'] : null,
			'repair_job_id' => $row['repair_job_id'] !== null ? (int)$row['repair_job_id'] : null,
			'category_id' => (int)$row['category_id'],
			'entry_date' => substr((string)$row['entry_date'], 0, 10),
			'amount_gross_minor' => (int)$row['amount_gross_minor'],
			'vat_rate_bp' => (int)$row['vat_rate_bp'],
			'amount_net_minor' => (int)$row['amount_net_minor'],
			'vat_amount_minor' => (int)$row['vat_amount_minor'],
			'receipt_reference' => $row['receipt_reference'] !== null ? (string)$row['receipt_reference'] : null,
			'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
			'created_by_user_id' => (string)$row['created_by_user_id'],
			'created_at' => (string)$row['created_at'],
			'updated_at' => (string)$row['updated_at'],
		];
	}
}
