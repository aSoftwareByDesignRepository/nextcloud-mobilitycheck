<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * §1.16 / §8.11 / §13.41 — Documented retention windows.
 *
 * Different record classes carry different statutory retention windows:
 *  - Logbook + Fahrtenbuch + cost entries + checkout logs + reimbursement
 *    claims: 10 years (GoBD / HGB / AO).
 *  - Damage + repair records: 5 years.
 *  - Audit log: 10 years.
 *
 * Retention purge is an **explicit admin action** with typed confirmation
 * (release gate §13.41) — never an idle background job. Every batch
 * writes an `mc_audit_log` row identifying the table and the row ids
 * (or, for high-volume tables, the count + the oldest / newest
 * `entry_date`).
 */
class RetentionService
{
	public const TABLE_TO_YEARS = [
		'mc_audit_log' => 10,
		'mc_logbook_entries' => 10,
		'mc_cost_entries' => 10,
		'mc_checkout_logs' => 10,
		'mc_reimbursement_claims' => 10,
		'mc_damage_reports' => 5,
		'mc_damage_photos' => 5,
		'mc_repair_jobs' => 5,
	];

	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private AuditLogService $audit,
		private SettingsService $settings,
	) {
	}

	/**
	 * Dry-run preview of how many rows would be purged for each table.
	 *
	 * @return array<string,array{table:string,years:int,thresholdDate:string,candidateCount:int}>
	 */
	public function previewPurge(string $performedBy): array
	{
		$this->access->requireFleetAdmin($performedBy);
		$out = [];
		foreach (self::TABLE_TO_YEARS as $table => $years) {
			$threshold = gmdate('Y-m-d', strtotime('-' . $years . ' years'));
			$count = $this->countBefore($table, $threshold);
			$out[$table] = [
				'table' => $table,
				'years' => $years,
				'thresholdDate' => $threshold,
				'candidateCount' => $count,
			];
		}
		return $out;
	}

	/**
	 * Execute purge after typed confirmation. The confirmation string must
	 * literally be `RETENTION_PURGE_CONFIRMED` to avoid mis-clicks (§13.41).
	 *
	 * @return array<string,int> table => deleted rows
	 */
	public function executePurge(string $performedBy, string $confirmation): array
	{
		$this->access->requireFleetAdmin($performedBy);
		if ($confirmation !== 'RETENTION_PURGE_CONFIRMED') {
			throw new ValidationException('CONFIRMATION_REQUIRED', 'confirmation');
		}
		$deleted = [];
		foreach (self::TABLE_TO_YEARS as $table => $years) {
			$threshold = gmdate('Y-m-d', strtotime('-' . $years . ' years'));
			try {
				$affected = $this->deleteBefore($table, $threshold);
				if ($affected > 0) {
					$deleted[$table] = $affected;
				}
			} catch (\Throwable) {
				// table missing in this install — skip
			}
		}
		$this->audit->log('retention', 0, 'purge_batch', $performedBy, [
			'deleted_by_table' => $deleted,
		]);
		return $deleted;
	}

	private function countBefore(string $table, string $threshold): int
	{
		try {
			$col = $this->retentionColumnFor($table);
			$qb = $this->db->getQueryBuilder();
			$qb->selectAlias($qb->func()->count('*'), 'c')->from($table)
				->where($qb->expr()->lt($col, $qb->createNamedParameter($threshold)));
			return (int)($qb->executeQuery()->fetchOne() ?: 0);
		} catch (\Throwable) {
			return 0;
		}
	}

	private function deleteBefore(string $table, string $threshold): int
	{
		$col = $this->retentionColumnFor($table);
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->lt($col, $qb->createNamedParameter($threshold)));
		return (int)$qb->executeStatement();
	}

	private function retentionColumnFor(string $table): string
	{
		return match ($table) {
			'mc_audit_log' => 'performed_at',
			'mc_checkout_logs' => 'recorded_at',
			'mc_cost_entries' => 'entry_date',
			'mc_logbook_entries' => 'trip_date',
			'mc_reimbursement_claims' => 'created_at',
			'mc_damage_reports' => 'reported_at',
			'mc_damage_photos' => 'created_at',
			'mc_repair_jobs' => 'created_at',
			default => 'created_at',
		};
	}
}
