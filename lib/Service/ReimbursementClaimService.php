<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Appendix A4.3–A4.5 reimbursement claims lifecycle. */
class ReimbursementClaimService
{
	public const STATUS_DRAFT = 'draft';
	public const STATUS_SUBMITTED = 'submitted';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_PAID = 'paid';

	public function __construct(
		private IDBConnection $db,
		private PrivateVehicleService $privateVehicles,
		private ReimbursementRateService $rates,
		private AccessControlService $access,
		private AuditLogService $audit,
		private SettingsService $settings,
	) {
	}

	/** @param array<string,mixed> $filters */
	public function list(array $filters, string $viewerId): array
	{
		if (!$this->settings->reimbursementEnabled()) {
			throw new ValidationException('REIMBURSEMENT_MODULE_DISABLED');
		}
		$this->access->requireAnyAppRole($viewerId);
		$this->access->requireNotWorkshopOnly($viewerId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_reimbursement_claims')->orderBy('trip_date', 'DESC')->addOrderBy('id', 'DESC');
		if (!$this->access->isFleetAdminOrManager($viewerId) && !$this->access->isAuditor($viewerId)) {
			$qb->where($qb->expr()->eq('driver_user_id', $qb->createNamedParameter($viewerId)));
		} elseif (!empty($filters['driverUserId'])) {
			$qb->where($qb->expr()->eq('driver_user_id', $qb->createNamedParameter((string)$filters['driverUserId'])));
		}
		if (!empty($filters['status'])) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter((string)$filters['status'])));
		}
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = $this->hydrate($r);
		}
		$res->closeCursor();
		return $out;
	}

	public function get(int $id, string $viewerId): array
	{
		if (!$this->settings->reimbursementEnabled()) {
			throw new ValidationException('REIMBURSEMENT_MODULE_DISABLED');
		}
		$this->access->requireAnyAppRole($viewerId);
		$this->access->requireNotWorkshopOnly($viewerId);
		$row = $this->fetchRow($id);
		$this->assertCanReadClaim($viewerId, $row);
		return $this->hydrate($row);
	}

	/** @param array<string,mixed> $payload */
	public function createDraft(array $payload, string $userId): array
	{
		if (!$this->settings->reimbursementEnabled()) {
			throw new ValidationException('REIMBURSEMENT_MODULE_DISABLED');
		}
		$this->access->requireDriver($userId);
		$pvId = (int)($payload['privateVehicleId'] ?? $payload['private_vehicle_id'] ?? 0);
		if ($pvId <= 0) {
			throw new ValidationException('PRIVATE_VEHICLE_REQUIRED');
		}
		$this->privateVehicles->get($pvId, $userId);
		$data = $this->normaliseClaimPayload($payload, requireDistance: true);
		$jc = $data['jurisdiction_code'];
		$vehicleRow = $this->privateVehicles->get($pvId, $userId);
		$rateVt = $this->mapEngineToVehicleType((string)$vehicleRow['engine_type']);
		$calc = $this->rates->computeStatutoryAmount($jc, $rateVt, $data['trip_date'], (int)$data['distance_km']);
		$now = gmdate('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('mc_reimbursement_claims')->values([
			'driver_user_id' => $ins->createNamedParameter($userId),
			'private_vehicle_id' => $ins->createNamedParameter($pvId, IQueryBuilder::PARAM_INT),
			'trip_date' => $ins->createNamedParameter($data['trip_date']),
			'departure_time' => $ins->createNamedParameter($data['departure_time']),
			'arrival_time' => $ins->createNamedParameter($data['arrival_time']),
			'start_address' => $ins->createNamedParameter($data['start_address']),
			'end_address' => $ins->createNamedParameter($data['end_address']),
			'distance_km' => $ins->createNamedParameter((int)$data['distance_km'], IQueryBuilder::PARAM_INT),
			'distance_verified_km' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'purpose' => $ins->createNamedParameter($data['purpose']),
			'client_or_contact' => $ins->createNamedParameter($data['client_or_contact']),
			'project_reference' => $ins->createNamedParameter($data['project_reference']),
			'passengers' => $ins->createNamedParameter($data['passengers']),
			'rate_per_km_minor' => $ins->createNamedParameter((int)$calc['effective_rate_per_km_minor'], IQueryBuilder::PARAM_INT),
			'amount_claimable_minor' => $ins->createNamedParameter((int)$calc['amount_claimable_minor'], IQueryBuilder::PARAM_INT),
			'amount_taxable_minor' => $ins->createNamedParameter((int)$calc['amount_taxable_minor'], IQueryBuilder::PARAM_INT),
			'status' => $ins->createNamedParameter(self::STATUS_DRAFT),
			'submitted_at' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'reviewed_by_user_id' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'reviewed_at' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'rejection_reason' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'payment_reference' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'receipt_file_id' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'jurisdiction_code' => $ins->createNamedParameter($jc),
			'created_at' => $ins->createNamedParameter($now),
			'updated_at' => $ins->createNamedParameter($now),
		]);
		$ins->executeStatement();
		$id = (int)$this->db->lastInsertId('mc_reimbursement_claims');
		$this->audit->log('reimbursement_claim', $id, 'create_draft', $userId, []);
		return $this->get($id, $userId);
	}

	/** @param array<string,mixed> $payload */
	public function updateDraft(int $id, array $payload, string $userId): array
	{
		$row = $this->fetchRow($id);
		if (($row['status'] ?? '') !== self::STATUS_DRAFT) {
			throw new ValidationException('CLAIM_NOT_DRAFT');
		}
		if (($row['driver_user_id'] ?? '') !== $userId) {
			throw new ForbiddenException('CANNOT_EDIT_CLAIM');
		}
		$data = $this->normaliseClaimPayload($payload, requireDistance: true);
		$pvId = (int)$row['private_vehicle_id'];
		$vehicleRow = $this->privateVehicles->get($pvId, $userId);
		$rateVt = $this->mapEngineToVehicleType((string)$vehicleRow['engine_type']);
		$calc = $this->rates->computeStatutoryAmount((string)$data['jurisdiction_code'], $rateVt, $data['trip_date'], max(1, (int)$data['distance_km']));
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_reimbursement_claims')
			->set('trip_date', $qb->createNamedParameter($data['trip_date']))
			->set('departure_time', $qb->createNamedParameter($data['departure_time']))
			->set('arrival_time', $qb->createNamedParameter($data['arrival_time']))
			->set('start_address', $qb->createNamedParameter($data['start_address']))
			->set('end_address', $qb->createNamedParameter($data['end_address']))
			->set('distance_km', $qb->createNamedParameter((int)$data['distance_km'], IQueryBuilder::PARAM_INT))
			->set('purpose', $qb->createNamedParameter($data['purpose']))
			->set('client_or_contact', $qb->createNamedParameter($data['client_or_contact']))
			->set('project_reference', $qb->createNamedParameter($data['project_reference']))
			->set('passengers', $qb->createNamedParameter($data['passengers']))
			->set('rate_per_km_minor', $qb->createNamedParameter((int)$calc['effective_rate_per_km_minor'], IQueryBuilder::PARAM_INT))
			->set('amount_claimable_minor', $qb->createNamedParameter((int)$calc['amount_claimable_minor'], IQueryBuilder::PARAM_INT))
			->set('amount_taxable_minor', $qb->createNamedParameter((int)$calc['amount_taxable_minor'], IQueryBuilder::PARAM_INT))
			->set('jurisdiction_code', $qb->createNamedParameter((string)$data['jurisdiction_code']))
			->set('updated_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		return $this->get($id, $userId);
	}

	public function submit(int $id, string $userId): array
	{
		$row = $this->fetchRow($id);
		if (($row['driver_user_id'] ?? '') !== $userId) {
			throw new ForbiddenException('CANNOT_SUBMIT_CLAIM');
		}
		if (($row['status'] ?? '') !== self::STATUS_DRAFT) {
			throw new ValidationException('CLAIM_NOT_DRAFT');
		}
		$this->recomputeRates($id, $row);
		$warnings = [];
		if ($this->hasApprovedBookingSameDay($userId, (string)$row['trip_date'])) {
			$warnings[] = 'SAME_DAY_POOL_BOOKING_SOFT_WARNING';
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_reimbursement_claims')
			->set('status', $qb->createNamedParameter(self::STATUS_SUBMITTED))
			->set('submitted_at', $qb->createNamedParameter($now))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('reimbursement_claim', $id, 'submit', $userId, []);
		$claim = $this->get($id, $userId);
		$claim['warnings'] = $warnings;
		return $claim;
	}

	public function approve(int $id, string $reviewerId, ?int $verifiedDistanceKm): array
	{
		$this->access->requireFleetAdminOrManager($reviewerId);
		$row = $this->fetchRow($id);
		if (($row['status'] ?? '') !== self::STATUS_SUBMITTED) {
			throw new ValidationException('CLAIM_NOT_SUBMITTED');
		}
		if ($verifiedDistanceKm !== null && $verifiedDistanceKm >= 0) {
			$qb0 = $this->db->getQueryBuilder();
			$qb0->update('mc_reimbursement_claims')
				->set('distance_verified_km', $qb0->createNamedParameter($verifiedDistanceKm, IQueryBuilder::PARAM_INT))
				->where($qb0->expr()->eq('id', $qb0->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$qb0->executeStatement();
			$row = $this->fetchRow($id);
			$this->recomputeRates($id, $row, $verifiedDistanceKm);
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_reimbursement_claims')
			->set('status', $qb->createNamedParameter(self::STATUS_APPROVED))
			->set('reviewed_by_user_id', $qb->createNamedParameter($reviewerId))
			->set('reviewed_at', $qb->createNamedParameter($now))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('reimbursement_claim', $id, 'approve', $reviewerId, []);
		return $this->get($id, $reviewerId);
	}

	public function reject(int $id, string $reviewerId, string $reason): array
	{
		$this->access->requireFleetAdminOrManager($reviewerId);
		$reason = trim($reason);
		if ($reason === '') {
			throw new ValidationException('REASON_REQUIRED', 'reason');
		}
		$row = $this->fetchRow($id);
		if (($row['status'] ?? '') !== self::STATUS_SUBMITTED) {
			throw new ValidationException('CLAIM_NOT_SUBMITTED');
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_reimbursement_claims')
			->set('status', $qb->createNamedParameter(self::STATUS_REJECTED))
			->set('reviewed_by_user_id', $qb->createNamedParameter($reviewerId))
			->set('reviewed_at', $qb->createNamedParameter($now))
			->set('rejection_reason', $qb->createNamedParameter($reason))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('reimbursement_claim', $id, 'reject', $reviewerId, []);
		return $this->get($id, $reviewerId);
	}

	public function markPaid(int $id, string $reviewerId, string $paymentReference): array
	{
		$this->access->requireFleetAdminOrManager($reviewerId);
		$paymentReference = trim($paymentReference);
		if ($paymentReference === '') {
			throw new ValidationException('PAYMENT_REFERENCE_REQUIRED', 'paymentReference');
		}
		$row = $this->fetchRow($id);
		if (($row['status'] ?? '') !== self::STATUS_APPROVED) {
			throw new ValidationException('CLAIM_NOT_APPROVED');
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_reimbursement_claims')
			->set('status', $qb->createNamedParameter(self::STATUS_PAID))
			->set('payment_reference', $qb->createNamedParameter($paymentReference))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('reimbursement_claim', $id, 'mark_paid', $reviewerId, []);
		return $this->get($id, $reviewerId);
	}

	private function hasApprovedBookingSameDay(string $userId, string $tripDate): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'c')->from('mc_bookings')
			->where($qb->expr()->eq('driver_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter([
				BookingService::STATUS_APPROVED,
				BookingService::STATUS_ACTIVE,
				BookingService::STATUS_COMPLETED,
			], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lte('start_datetime', $qb->createNamedParameter($tripDate . ' 23:59:59')))
			->andWhere($qb->expr()->gte('end_datetime', $qb->createNamedParameter($tripDate . ' 00:00:00')));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	/** @param array<string,mixed> $row */
	private function recomputeRates(int $id, array $row, ?int $forceDistanceKm = null): void
	{
		$dKm = $forceDistanceKm ?? (int)$row['distance_km'];
		if ($dKm <= 0) {
			throw new ValidationException('DISTANCE_INVALID');
		}
		$pvId = (int)$row['private_vehicle_id'];
		$pvRow = $this->privateVehicles->get($pvId, (string)$row['driver_user_id']);
		$rateVt = $this->mapEngineToVehicleType((string)$pvRow['engine_type']);
		$jc = (string)$row['jurisdiction_code'];
		$tripDate = (string)$row['trip_date'];
		$calc = $this->rates->computeStatutoryAmount($jc, $rateVt, $tripDate, $dKm);
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_reimbursement_claims')
			->set('rate_per_km_minor', $qb->createNamedParameter((int)$calc['effective_rate_per_km_minor'], IQueryBuilder::PARAM_INT))
			->set('amount_claimable_minor', $qb->createNamedParameter((int)$calc['amount_claimable_minor'], IQueryBuilder::PARAM_INT))
			->set('amount_taxable_minor', $qb->createNamedParameter((int)$calc['amount_taxable_minor'], IQueryBuilder::PARAM_INT))
			->set('distance_km', $qb->createNamedParameter($dKm, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function mapEngineToVehicleType(string $engine): string
	{
		return match ($engine) {
			'electric' => 'electric',
			default => 'car',
		};
	}

	/** @param array<string,mixed> $payload */
	private function normaliseClaimPayload(array $payload, bool $requireDistance): array
	{
		$tripDate = trim((string)($payload['tripDate'] ?? $payload['trip_date'] ?? ''));
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tripDate)) {
			throw new ValidationException('TRIP_DATE_INVALID');
		}
		$jc = strtoupper(trim((string)($payload['jurisdictionCode'] ?? $payload['jurisdiction_code'] ?? 'DE')));
		if ($jc === '' || strlen($jc) > 10) {
			throw new ValidationException('JURISDICTION_INVALID');
		}
		$start = trim((string)($payload['startAddress'] ?? $payload['start_address'] ?? ''));
		$end = trim((string)($payload['endAddress'] ?? $payload['end_address'] ?? ''));
		if (mb_strlen($start) < 3 || mb_strlen($end) < 3) {
			throw new ValidationException('ADDRESS_REQUIRED');
		}
		$dKm = (int)($payload['distanceKm'] ?? $payload['distance_km'] ?? 0);
		if ($requireDistance && $dKm <= 0) {
			throw new ValidationException('DISTANCE_REQUIRED');
		}
		$purpose = trim((string)($payload['purpose'] ?? ''));
		if (mb_strlen($purpose) < 4) {
			throw new ValidationException('PURPOSE_TOO_SHORT');
		}
		return [
			'trip_date' => $tripDate,
			'jurisdiction_code' => $jc,
			'departure_time' => $this->optTime($payload['departureTime'] ?? $payload['departure_time'] ?? null),
			'arrival_time' => $this->optTime($payload['arrivalTime'] ?? $payload['arrival_time'] ?? null),
			'start_address' => mb_substr($start, 0, 250),
			'end_address' => mb_substr($end, 0, 250),
			'distance_km' => max(0, $dKm),
			'purpose' => mb_substr($purpose, 0, 4000),
			'client_or_contact' => $this->nullableStr($payload['clientOrContact'] ?? $payload['client_or_contact'] ?? null, 250),
			'project_reference' => $this->nullableStr($payload['projectReference'] ?? $payload['project_reference'] ?? null, 120),
			'passengers' => $this->nullableStr($payload['passengers'] ?? null, 500),
		];
	}

	private function optTime(mixed $v): ?string
	{
		if ($v === null || $v === '') {
			return null;
		}
		$s = trim((string)$v);
		return strlen($s) === 5 ? $s . ':00' : $s;
	}

	private function nullableStr(mixed $v, int $max): ?string
	{
		if ($v === null) {
			return null;
		}
		$s = trim((string)$v);
		return $s === '' ? null : mb_substr($s, 0, $max);
	}

	private function fetchRow(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_reimbursement_claims')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$r = $qb->executeQuery()->fetch();
		if (!$r) {
			throw new NotFoundException('REIMBURSEMENT_CLAIM_NOT_FOUND');
		}
		return $r;
	}

	/** @param array<string,mixed> $row */
	private function assertCanReadClaim(string $viewerId, array $row): void
	{
		if ($this->access->isFleetAdminOrManager($viewerId) || $this->access->isAuditor($viewerId)) {
			return;
		}
		if (($row['driver_user_id'] ?? '') !== $viewerId) {
			throw new ForbiddenException('CANNOT_VIEW_CLAIM');
		}
	}

	/** @param array<string,mixed> $row */
	private function hydrate(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'driver_user_id' => (string)$row['driver_user_id'],
			'private_vehicle_id' => (int)$row['private_vehicle_id'],
			'trip_date' => substr((string)$row['trip_date'], 0, 10),
			'departure_time' => $row['departure_time'] !== null ? (string)$row['departure_time'] : null,
			'arrival_time' => $row['arrival_time'] !== null ? (string)$row['arrival_time'] : null,
			'start_address' => (string)$row['start_address'],
			'end_address' => (string)$row['end_address'],
			'distance_km' => (int)$row['distance_km'],
			'distance_verified_km' => isset($row['distance_verified_km']) && $row['distance_verified_km'] !== null ? (int)$row['distance_verified_km'] : null,
			'purpose' => (string)$row['purpose'],
			'client_or_contact' => $row['client_or_contact'] !== null ? (string)$row['client_or_contact'] : null,
			'project_reference' => $row['project_reference'] !== null ? (string)$row['project_reference'] : null,
			'passengers' => $row['passengers'] !== null ? (string)$row['passengers'] : null,
			'rate_per_km_minor' => (int)$row['rate_per_km_minor'],
			'amount_claimable_minor' => (int)$row['amount_claimable_minor'],
			'amount_taxable_minor' => (int)$row['amount_taxable_minor'],
			'status' => (string)$row['status'],
			'submitted_at' => $row['submitted_at'] !== null ? (string)$row['submitted_at'] : null,
			'reviewed_by_user_id' => $row['reviewed_by_user_id'] !== null ? (string)$row['reviewed_by_user_id'] : null,
			'reviewed_at' => $row['reviewed_at'] !== null ? (string)$row['reviewed_at'] : null,
			'rejection_reason' => $row['rejection_reason'] !== null ? (string)$row['rejection_reason'] : null,
			'payment_reference' => $row['payment_reference'] !== null ? (string)$row['payment_reference'] : null,
			'jurisdiction_code' => $row['jurisdiction_code'] !== null ? (string)$row['jurisdiction_code'] : null,
			'created_at' => (string)$row['created_at'],
			'updated_at' => (string)$row['updated_at'],
		];
	}
}
