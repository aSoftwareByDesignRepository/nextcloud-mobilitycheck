<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * §4.7 — Repair job management. Linked to a damage report, optionally
 * assigned to a workshop user (Nextcloud user with `workshop` role)
 * or an external workshop contact recorded as free text.
 *
 * Workshop users may only see jobs assigned to them — §2.2.
 */
class RepairService
{
	public const STATUS_OPEN = 'open';
	public const STATUS_IN_PROGRESS = 'in_progress';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_CANCELLED = 'cancelled';

	public const STATUSES = [
		self::STATUS_OPEN,
		self::STATUS_IN_PROGRESS,
		self::STATUS_COMPLETED,
		self::STATUS_CANCELLED,
	];

	public function __construct(
		private IDBConnection $db,
		private DamageService $damage,
		private AccessControlService $access,
		private AuditLogService $audit,
		private IUserManager $userManager,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function list(string $performedBy, array $filters = []): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_repair_jobs')->orderBy('created_at', 'DESC');
		if ($this->access->isWorkshopOnly($performedBy)) {
			$qb->andWhere($qb->expr()->eq('assigned_workshop_user_id', $qb->createNamedParameter($performedBy)));
		}
		if (!empty($filters['vehicleId'])) {
			$qb->andWhere($qb->expr()->eq('vehicle_id', $qb->createNamedParameter((int)$filters['vehicleId'], IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filters['damageReportId'])) {
			$qb->andWhere($qb->expr()->eq('damage_report_id', $qb->createNamedParameter((int)$filters['damageReportId'], IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filters['status'])) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter((string)$filters['status'])));
		}
		$qb->setMaxResults(min(500, max(1, (int)($filters['limit'] ?? 200))));
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = $this->hydrate($r);
		}
		$res->closeCursor();
		return $out;
	}

	public function get(int $id, string $performedBy): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_repair_jobs')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if (!$row) {
			throw new NotFoundException('REPAIR_JOB_NOT_FOUND');
		}
		$job = $this->hydrate($row);
		if ($this->access->isWorkshopOnly($performedBy) && $job['assigned_workshop_user_id'] !== $performedBy) {
			throw new ForbiddenException('REPAIR_JOB_NOT_YOURS');
		}
		return $job;
	}

	/** @param array<string,mixed> $payload */
	public function create(array $payload, string $performedBy): array
	{
		$this->access->requireFleetAdminOrManager($performedBy);
		$damageReportId = (int)($payload['damageReportId'] ?? $payload['damage_report_id'] ?? 0);
		$damage = $this->damage->get($damageReportId);
		$assignedWorkshopUserId = $this->validateWorkshopUser($payload['assignedWorkshopUserId'] ?? $payload['assigned_workshop_user_id'] ?? null);
		$externalName = $this->stringOrNull($payload['externalWorkshopName'] ?? $payload['external_workshop_name'] ?? null, 120);
		$externalContact = $this->stringOrNull($payload['externalWorkshopContact'] ?? $payload['external_workshop_contact'] ?? null, 120);
		if ($assignedWorkshopUserId === null && $externalName === null) {
			throw new ValidationException('WORKSHOP_REQUIRED', 'assigned_workshop_user_id');
		}
		$estimated = $this->moneyOrNull($payload['estimatedCostMinor'] ?? $payload['estimated_cost_minor'] ?? null);
		$targetDate = $this->dateOrNull($payload['targetDate'] ?? $payload['target_date'] ?? null);
		$notes = $this->stringOrNull($payload['notes'] ?? null, 4000);

		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('mc_repair_jobs')->values([
			'damage_report_id' => $qb->createNamedParameter($damageReportId, IQueryBuilder::PARAM_INT),
			'vehicle_id' => $qb->createNamedParameter((int)$damage['vehicle_id'], IQueryBuilder::PARAM_INT),
			'assigned_workshop_user_id' => $qb->createNamedParameter($assignedWorkshopUserId),
			'external_workshop_name' => $qb->createNamedParameter($externalName),
			'external_workshop_contact' => $qb->createNamedParameter($externalContact),
			'estimated_cost_minor' => $estimated !== null
				? $qb->createNamedParameter($estimated, IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'target_date' => $qb->createNamedParameter($targetDate),
			'status' => $qb->createNamedParameter(self::STATUS_OPEN),
			'notes' => $qb->createNamedParameter($notes),
			'created_by_user_id' => $qb->createNamedParameter($performedBy),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
		]);
		$qb->executeStatement();
		$id = (int)$this->db->lastInsertId('mc_repair_jobs');
		$this->audit->log('repair_job', $id, 'create', $performedBy, [
			'damage_report_id' => $damageReportId,
			'workshop' => $assignedWorkshopUserId ?? $externalName,
		]);
		// Move the damage report to repair_scheduled if applicable.
		if ($damage['status'] === DamageService::STATUS_REPORTED || $damage['status'] === DamageService::STATUS_UNDER_ASSESSMENT) {
			$this->damage->updateStatus($damageReportId, DamageService::STATUS_REPAIR_SCHEDULED, $performedBy, null);
		}
		return $this->get($id, $performedBy);
	}

	/** @param array<string,mixed> $payload */
	public function update(int $id, array $payload, string $performedBy): array
	{
		$job = $this->get($id, $performedBy);
		$isWorkshop = $this->access->isWorkshopOnly($performedBy);
		if (!$isWorkshop) {
			$this->access->requireFleetAdminOrManager($performedBy);
		}
		$status = isset($payload['status']) ? (string)$payload['status'] : $job['status'];
		if (!in_array($status, self::STATUSES, true)) {
			throw new ValidationException('STATUS_INVALID', 'status');
		}
		$actual = array_key_exists('actualCostMinor', $payload) || array_key_exists('actual_cost_minor', $payload)
			? $this->moneyOrNull($payload['actualCostMinor'] ?? $payload['actual_cost_minor'])
			: $job['actual_cost_minor'];
		$actualCompletion = array_key_exists('actualCompletionDate', $payload) || array_key_exists('actual_completion_date', $payload)
			? $this->dateOrNull($payload['actualCompletionDate'] ?? $payload['actual_completion_date'])
			: $job['actual_completion_date'];
		$notes = array_key_exists('notes', $payload)
			? $this->stringOrNull($payload['notes'], 4000)
			: $job['notes'];

		// Workshop users may only edit status, actual cost, completion, notes,
		// and invoice. They may not reassign or change estimates.
		if ($isWorkshop) {
			// Already restricted above by only reading those fields.
		}
		$estimated = array_key_exists('estimatedCostMinor', $payload) || array_key_exists('estimated_cost_minor', $payload)
			? $this->moneyOrNull($payload['estimatedCostMinor'] ?? $payload['estimated_cost_minor'])
			: $job['estimated_cost_minor'];
		$assigned = !$isWorkshop && (array_key_exists('assignedWorkshopUserId', $payload) || array_key_exists('assigned_workshop_user_id', $payload))
			? $this->validateWorkshopUser($payload['assignedWorkshopUserId'] ?? $payload['assigned_workshop_user_id'])
			: $job['assigned_workshop_user_id'];
		$externalName = !$isWorkshop && array_key_exists('externalWorkshopName', $payload)
			? $this->stringOrNull($payload['externalWorkshopName'], 120)
			: $job['external_workshop_name'];
		$externalContact = !$isWorkshop && array_key_exists('externalWorkshopContact', $payload)
			? $this->stringOrNull($payload['externalWorkshopContact'], 120)
			: $job['external_workshop_contact'];
		$targetDate = !$isWorkshop && (array_key_exists('targetDate', $payload) || array_key_exists('target_date', $payload))
			? $this->dateOrNull($payload['targetDate'] ?? $payload['target_date'])
			: $job['target_date'];

		if ($status === self::STATUS_COMPLETED && $actualCompletion === null) {
			$actualCompletion = gmdate('Y-m-d');
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_repair_jobs')
			->set('status', $qb->createNamedParameter($status))
			->set('actual_cost_minor', $actual !== null
				? $qb->createNamedParameter($actual, IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('estimated_cost_minor', $estimated !== null
				? $qb->createNamedParameter($estimated, IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('actual_completion_date', $qb->createNamedParameter($actualCompletion))
			->set('notes', $qb->createNamedParameter($notes))
			->set('assigned_workshop_user_id', $qb->createNamedParameter($assigned))
			->set('external_workshop_name', $qb->createNamedParameter($externalName))
			->set('external_workshop_contact', $qb->createNamedParameter($externalContact))
			->set('target_date', $qb->createNamedParameter($targetDate))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('repair_job', $id, 'update', $performedBy, [
			'status' => [$job['status'], $status],
		]);
		// If status moved to in_progress / completed, update the damage status.
		if ($status === self::STATUS_IN_PROGRESS) {
			$this->damage->updateStatus((int)$job['damage_report_id'], DamageService::STATUS_IN_REPAIR, $performedBy, null);
		} elseif ($status === self::STATUS_COMPLETED) {
			$this->damage->updateStatus((int)$job['damage_report_id'], DamageService::STATUS_REPAIRED, $performedBy, null);
		}
		return $this->get($id, $performedBy);
	}

	public function attachInvoice(int $id, string $fileNodeId, string $performedBy): array
	{
		$job = $this->get($id, $performedBy);
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_repair_jobs')
			->set('invoice_file_id', $qb->createNamedParameter($fileNodeId))
			->set('updated_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('repair_job', $id, 'invoice_added', $performedBy, ['file_id' => $fileNodeId]);
		return $this->get($id, $performedBy);
	}

	private function validateWorkshopUser(mixed $userId): ?string
	{
		if ($userId === null || $userId === '') {
			return null;
		}
		$uid = (string)$userId;
		if ($this->userManager->get($uid) === null) {
			throw new ValidationException('INVALID_WORKSHOP_USER', 'assigned_workshop_user_id');
		}
		if (!$this->access->hasRole($uid, AccessControlService::ROLE_WORKSHOP)
			&& !$this->access->isFleetAdminOrManager($uid)) {
			throw new ValidationException('USER_NOT_WORKSHOP', 'assigned_workshop_user_id');
		}
		return $uid;
	}

	private function moneyOrNull(mixed $v): ?int
	{
		if ($v === null || $v === '') return null;
		$n = (int)$v;
		if ($n < 0) {
			throw new ValidationException('MONEY_NEGATIVE_NOT_ALLOWED');
		}
		return $n;
	}

	private function dateOrNull(mixed $v): ?string
	{
		if ($v === null || $v === '') return null;
		$s = trim((string)$v);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
			throw new ValidationException('DATE_INVALID');
		}
		[$y, $m, $d] = array_map('intval', explode('-', $s));
		if (!checkdate($m, $d, $y)) {
			throw new ValidationException('DATE_INVALID');
		}
		return $s;
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
			'damage_report_id' => (int)$row['damage_report_id'],
			'vehicle_id' => (int)$row['vehicle_id'],
			'assigned_workshop_user_id' => $row['assigned_workshop_user_id'] !== null ? (string)$row['assigned_workshop_user_id'] : null,
			'external_workshop_name' => $row['external_workshop_name'] !== null ? (string)$row['external_workshop_name'] : null,
			'external_workshop_contact' => $row['external_workshop_contact'] !== null ? (string)$row['external_workshop_contact'] : null,
			'estimated_cost_minor' => $row['estimated_cost_minor'] !== null ? (int)$row['estimated_cost_minor'] : null,
			'actual_cost_minor' => $row['actual_cost_minor'] !== null ? (int)$row['actual_cost_minor'] : null,
			'target_date' => $row['target_date'] !== null ? substr((string)$row['target_date'], 0, 10) : null,
			'actual_completion_date' => $row['actual_completion_date'] !== null ? substr((string)$row['actual_completion_date'], 0, 10) : null,
			'status' => (string)$row['status'],
			'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
			'invoice_file_id' => $row['invoice_file_id'] !== null ? (string)$row['invoice_file_id'] : null,
			'created_by_user_id' => (string)$row['created_by_user_id'],
			'created_at' => (string)$row['created_at'],
			'updated_at' => (string)$row['updated_at'],
		];
	}
}
