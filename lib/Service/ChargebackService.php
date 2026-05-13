<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * §4.6.8 (fuel return-minimum) + §4.7.5 (damage chargeback) — central
 * service for creating driver chargeback cost rows and managing their
 * acknowledge / dispute / resolve lifecycle.
 *
 * Release gate **§13.36**: every auto-created row carries
 *  - `chargeable_to_driver = 1`
 *  - non-null `charge_driver_user_id`
 *  - non-null `linked_booking_id` (and `repair_job_id` is null on auto-rows
 *    that are not yet linked to a repair invoice)
 *  - non-empty `notes` referencing the triggering policy
 *  - a `mc_audit_log` row.
 */
class ChargebackService
{
	public const CATEGORY_FUEL = 'driver_chargeback_fuel';
	public const CATEGORY_DAMAGE = 'driver_chargeback_damage';

	private const FUEL_ORDER = ['empty' => 0, 'quarter' => 1, 'half' => 2, 'three_quarter' => 3, 'full' => 4];

	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private AuditLogService $audit,
		private SettingsService $settings,
	) {
	}

	/**
	 * Compute how many fuel-enum steps a return value is below the configured
	 * minimum. Returns 0 when at-or-above the minimum or when either argument
	 * is not a recognised enum value.
	 */
	public static function fuelStepsBelow(string $minimum, string $actual): int
	{
		if (!isset(self::FUEL_ORDER[$minimum], self::FUEL_ORDER[$actual])) {
			return 0;
		}
		return max(0, self::FUEL_ORDER[$minimum] - self::FUEL_ORDER[$actual]);
	}

	/**
	 * Auto-create a fuel chargeback row at check-in. Returns the new cost row
	 * id or null if the configured rate is 0 (chargeback disabled).
	 *
	 * @param array{vehicleId:int,bookingId:int,driverUserId:string,stepsBelow:int,actorUserId:string,minimum:string,actual:string} $ctx
	 */
	public function createFuelChargeback(array $ctx): ?int
	{
		$ratePerStep = $this->settings->fuelMinimumChargebackRatePerStepMinor();
		$steps = max(0, (int)$ctx['stepsBelow']);
		if ($ratePerStep <= 0 || $steps <= 0) {
			return null;
		}
		$amount = $ratePerStep * $steps;
		$categoryId = $this->ensureCategory(self::CATEGORY_FUEL);
		$now = gmdate('Y-m-d H:i:s');
		$today = gmdate('Y-m-d');
		$vatBp = 0; // chargebacks are inside-company recoupments — no VAT.
		$notes = sprintf(
			'Auto-chargeback (fuel-minimum policy): return level %s below required %s by %d enum step(s). Rate %d cents per step. Trigger: §4.6.8.',
			$ctx['actual'],
			$ctx['minimum'],
			$steps,
			$ratePerStep,
		);
		$qb = $this->db->getQueryBuilder();
		$qb->insert('mc_cost_entries')->values([
			'vehicle_id' => $qb->createNamedParameter((int)$ctx['vehicleId'], IQueryBuilder::PARAM_INT),
			'booking_id' => $qb->createNamedParameter((int)$ctx['bookingId'], IQueryBuilder::PARAM_INT),
			'repair_job_id' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'category_id' => $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT),
			'entry_date' => $qb->createNamedParameter($today),
			'amount_gross_minor' => $qb->createNamedParameter($amount, IQueryBuilder::PARAM_INT),
			'vat_rate_bp' => $qb->createNamedParameter($vatBp, IQueryBuilder::PARAM_INT),
			'amount_net_minor' => $qb->createNamedParameter($amount, IQueryBuilder::PARAM_INT),
			'vat_amount_minor' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'receipt_reference' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'notes' => $qb->createNamedParameter($notes),
			'is_deleted' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'created_by_user_id' => $qb->createNamedParameter('__system__'),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
			'chargeable_to_driver' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'charge_driver_user_id' => $qb->createNamedParameter((string)$ctx['driverUserId']),
			'linked_booking_id' => $qb->createNamedParameter((int)$ctx['bookingId'], IQueryBuilder::PARAM_INT),
		]);
		$qb->executeStatement();
		$id = (int)$this->db->lastInsertId('mc_cost_entries');
		$this->audit->log('cost_entry', $id, 'create_fuel_chargeback', (string)($ctx['actorUserId'] ?? '__system__'), [
			'booking_id' => (int)$ctx['bookingId'],
			'vehicle_id' => (int)$ctx['vehicleId'],
			'driver_user_id' => (string)$ctx['driverUserId'],
			'amount_minor' => $amount,
			'steps_below' => $steps,
			'minimum' => $ctx['minimum'],
			'actual' => $ctx['actual'],
		]);
		return $id;
	}

	/**
	 * Create a damage-chargeback cost row. The fleet manager picks the amount
	 * and the chargeable user. Returns the new cost row id.
	 *
	 * @param array{
	 *   damageReportId:int,
	 *   bookingId:?int,
	 *   vehicleId:int,
	 *   chargeableUserId:string,
	 *   amountMinor:int,
	 *   notes:?string,
	 * } $ctx
	 */
	public function createDamageChargeback(array $ctx, string $performedBy): int
	{
		$this->access->requireFleetAdminOrManager($performedBy);
		$amount = (int)$ctx['amountMinor'];
		if ($amount <= 0) {
			throw new ValidationException('AMOUNT_REQUIRED', 'amount_minor');
		}
		$chargeable = trim((string)$ctx['chargeableUserId']);
		if ($chargeable === '') {
			throw new ValidationException('DRIVER_REQUIRED', 'charge_driver_user_id');
		}
		$categoryId = $this->ensureCategory(self::CATEGORY_DAMAGE);
		$now = gmdate('Y-m-d H:i:s');
		$today = gmdate('Y-m-d');
		$baseNotes = trim((string)($ctx['notes'] ?? ''));
		$notes = sprintf(
			'Damage chargeback for damage report #%d.%s Trigger: §4.7.5.',
			(int)$ctx['damageReportId'],
			$baseNotes !== '' ? ' ' . $baseNotes : '',
		);
		$qb = $this->db->getQueryBuilder();
		$qb->insert('mc_cost_entries')->values([
			'vehicle_id' => $qb->createNamedParameter((int)$ctx['vehicleId'], IQueryBuilder::PARAM_INT),
			'booking_id' => $ctx['bookingId'] !== null
				? $qb->createNamedParameter((int)$ctx['bookingId'], IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'repair_job_id' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'category_id' => $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT),
			'entry_date' => $qb->createNamedParameter($today),
			'amount_gross_minor' => $qb->createNamedParameter($amount, IQueryBuilder::PARAM_INT),
			'vat_rate_bp' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'amount_net_minor' => $qb->createNamedParameter($amount, IQueryBuilder::PARAM_INT),
			'vat_amount_minor' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'receipt_reference' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'notes' => $qb->createNamedParameter($notes),
			'is_deleted' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'created_by_user_id' => $qb->createNamedParameter($performedBy),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
			'chargeable_to_driver' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'charge_driver_user_id' => $qb->createNamedParameter($chargeable),
			'linked_booking_id' => $ctx['bookingId'] !== null
				? $qb->createNamedParameter((int)$ctx['bookingId'], IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
		]);
		$qb->executeStatement();
		$id = (int)$this->db->lastInsertId('mc_cost_entries');
		$this->audit->log('cost_entry', $id, 'create_damage_chargeback', $performedBy, [
			'damage_report_id' => (int)$ctx['damageReportId'],
			'vehicle_id' => (int)$ctx['vehicleId'],
			'driver_user_id' => $chargeable,
			'amount_minor' => $amount,
		]);
		return $id;
	}

	/**
	 * Driver counter-signs a chargeback row. Idempotent: re-acknowledging is a no-op.
	 * §4.6.8 / §4.7.5.
	 */
	public function acknowledge(int $costId, string $performedBy): array
	{
		$row = $this->getRow($costId);
		if ((int)$row['chargeable_to_driver'] !== 1) {
			throw new ValidationException('NOT_A_CHARGEBACK');
		}
		if ((string)$row['charge_driver_user_id'] !== $performedBy && !$this->access->isFleetAdminOrManager($performedBy)) {
			throw new ForbiddenException('ACK_NOT_ALLOWED');
		}
		if ($row['driver_acknowledged_at'] !== null) {
			return $this->hydrate($row);
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_cost_entries')
			->set('driver_acknowledged_at', $qb->createNamedParameter($now))
			->set('driver_disputed_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('driver_dispute_reason', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('dispute_resolved_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('dispute_resolution', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($costId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('cost_entry', $costId, 'acknowledge_chargeback', $performedBy, []);
		return $this->hydrate($this->getRow($costId));
	}

	/**
	 * Driver disputes a chargeback row with mandatory reason ≥ 10 chars.
	 */
	public function dispute(int $costId, string $performedBy, string $reason): array
	{
		$reason = trim($reason);
		if (mb_strlen($reason) < 10) {
			throw new ValidationException('DISPUTE_REASON_TOO_SHORT', 'reason');
		}
		$row = $this->getRow($costId);
		if ((int)$row['chargeable_to_driver'] !== 1) {
			throw new ValidationException('NOT_A_CHARGEBACK');
		}
		if ((string)$row['charge_driver_user_id'] !== $performedBy) {
			throw new ForbiddenException('DISPUTE_NOT_ALLOWED');
		}
		if ($row['dispute_resolved_at'] !== null) {
			throw new ValidationException('CHARGEBACK_RESOLVED');
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_cost_entries')
			->set('driver_disputed_at', $qb->createNamedParameter($now))
			->set('driver_dispute_reason', $qb->createNamedParameter($reason))
			->set('driver_acknowledged_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($costId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('cost_entry', $costId, 'dispute_chargeback', $performedBy, [], $reason);
		return $this->hydrate($this->getRow($costId));
	}

	/**
	 * Fleet admin resolves a dispute. `resolution` is one of `uphold`, `partial_waive`, `full_waive`.
	 * Writes the resolution + reason; only fleet admins may resolve (§4.7.5).
	 */
	public function resolveDispute(int $costId, string $performedBy, string $resolution, string $reason, ?int $newAmountMinor = null): array
	{
		$this->access->requireFleetAdmin($performedBy);
		$reason = trim($reason);
		if (mb_strlen($reason) < 5) {
			throw new ValidationException('RESOLUTION_REASON_REQUIRED', 'reason');
		}
		if (!in_array($resolution, ['uphold', 'partial_waive', 'full_waive'], true)) {
			throw new ValidationException('RESOLUTION_INVALID', 'resolution');
		}
		$row = $this->getRow($costId);
		if ((int)$row['chargeable_to_driver'] !== 1) {
			throw new ValidationException('NOT_A_CHARGEBACK');
		}
		if ($row['driver_disputed_at'] === null) {
			throw new ValidationException('NOT_DISPUTED');
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_cost_entries')
			->set('dispute_resolved_at', $qb->createNamedParameter($now))
			->set('dispute_resolution', $qb->createNamedParameter($resolution . ': ' . $reason))
			->set('updated_at', $qb->createNamedParameter($now));
		if ($resolution === 'full_waive') {
			$qb->set('is_deleted', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT));
			$qb->set('delete_reason', $qb->createNamedParameter('dispute full_waive: ' . $reason));
		} elseif ($resolution === 'partial_waive') {
			if ($newAmountMinor === null || $newAmountMinor < 0 || $newAmountMinor >= (int)$row['amount_gross_minor']) {
				throw new ValidationException('PARTIAL_AMOUNT_INVALID', 'new_amount_minor');
			}
			$qb->set('amount_gross_minor', $qb->createNamedParameter($newAmountMinor, IQueryBuilder::PARAM_INT));
			$qb->set('amount_net_minor', $qb->createNamedParameter($newAmountMinor, IQueryBuilder::PARAM_INT));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($costId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('cost_entry', $costId, 'resolve_dispute', $performedBy, [
			'resolution' => $resolution,
			'new_amount_minor' => $newAmountMinor,
		], $reason);
		return $this->hydrate($this->getRow($costId));
	}

	/**
	 * List chargeback rows visible to the viewer. Drivers see their own;
	 * fleet admins/managers see all. Auditors see all (read-only).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listForViewer(string $viewerId, ?string $driverFilter = null): array
	{
		$this->access->requireAnyAppRole($viewerId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_cost_entries')
			->where($qb->expr()->eq('chargeable_to_driver', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC');
		if ($this->access->isFleetAdminOrManager($viewerId) || $this->access->isAuditor($viewerId) || $this->access->isAppAdmin($viewerId)) {
			if ($driverFilter !== null && $driverFilter !== '') {
				$qb->andWhere($qb->expr()->eq('charge_driver_user_id', $qb->createNamedParameter($driverFilter)));
			}
		} else {
			$qb->andWhere($qb->expr()->eq('charge_driver_user_id', $qb->createNamedParameter($viewerId)));
		}
		$qb->setMaxResults(500);
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = $this->hydrate($r);
		}
		$res->closeCursor();
		return $out;
	}

	/** @return array<string,mixed> */
	private function getRow(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_cost_entries')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if (!$row) {
			throw new NotFoundException('COST_ENTRY_NOT_FOUND');
		}
		return $row;
	}

	private function ensureCategory(string $name): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('mc_cost_categories')
			->where($qb->expr()->eq('name', $qb->createNamedParameter($name)))
			->setMaxResults(1);
		$id = $qb->executeQuery()->fetchOne();
		if ($id !== false) {
			return (int)$id;
		}
		$ins = $this->db->getQueryBuilder();
		$ins->insert('mc_cost_categories')->values([
			'name' => $ins->createNamedParameter($name),
			'is_active' => $ins->createNamedParameter(1, IQueryBuilder::PARAM_INT),
		]);
		try {
			$ins->executeStatement();
			return (int)$this->db->lastInsertId('mc_cost_categories');
		} catch (\Throwable) {
			// Race-safe: a parallel request created the same category between
			// our SELECT and INSERT. Re-select to obtain the canonical id —
			// `lastInsertId` is unreliable when the INSERT was rejected on a
			// unique constraint by the other transaction.
			$qb2 = $this->db->getQueryBuilder();
			$qb2->select('id')->from('mc_cost_categories')
				->where($qb2->expr()->eq('name', $qb2->createNamedParameter($name)))
				->setMaxResults(1);
			$existing = $qb2->executeQuery()->fetchOne();
			if ($existing === false) {
				throw new \RuntimeException('Cost category ensure failed for "' . $name . '"');
			}
			return (int)$existing;
		}
	}

	/** @param array<string,mixed> $row */
	private function hydrate(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'vehicle_id' => (int)$row['vehicle_id'],
			'booking_id' => $row['booking_id'] !== null ? (int)$row['booking_id'] : null,
			'linked_booking_id' => $row['linked_booking_id'] !== null ? (int)$row['linked_booking_id'] : null,
			'category_id' => (int)$row['category_id'],
			'entry_date' => substr((string)$row['entry_date'], 0, 10),
			'amount_gross_minor' => (int)$row['amount_gross_minor'],
			'chargeable_to_driver' => (int)$row['chargeable_to_driver'] === 1,
			'charge_driver_user_id' => $row['charge_driver_user_id'] !== null ? (string)$row['charge_driver_user_id'] : null,
			'driver_acknowledged_at' => $row['driver_acknowledged_at'] !== null ? (string)$row['driver_acknowledged_at'] : null,
			'driver_disputed_at' => $row['driver_disputed_at'] !== null ? (string)$row['driver_disputed_at'] : null,
			'driver_dispute_reason' => $row['driver_dispute_reason'] !== null ? (string)$row['driver_dispute_reason'] : null,
			'dispute_resolved_at' => $row['dispute_resolved_at'] !== null ? (string)$row['dispute_resolved_at'] : null,
			'dispute_resolution' => $row['dispute_resolution'] !== null ? (string)$row['dispute_resolution'] : null,
			'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
			'is_deleted' => (int)$row['is_deleted'] === 1,
			'created_at' => (string)$row['created_at'],
		];
	}
}
