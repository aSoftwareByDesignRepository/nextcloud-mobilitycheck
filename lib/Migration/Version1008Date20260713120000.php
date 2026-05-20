<?php

declare(strict_types=1);

/**
 * Rename MobilityCheck tables whose logical names exceeded the 30-character
 * physical identifier limit enforced by Nextcloud for Oracle-compatible installs
 * (`strlen(dbtableprefix)+strlen(logical_name)` with default prefix `oc_`).
 *
 * Legacy names are renamed in place (no data copy). Fresh installs already use
 * the short names from the corrected earlier migration steps.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\MobilityCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use RuntimeException;

class Version1008Date20260713120000 extends SimpleMigrationStep
{
	/** @var array<string, string> */
	private const LOGICAL_RENAMES = [
		'mc_reimbursement_rate_config' => 'mc_reim_rate_cfg',
		'mc_booking_reassignment_suggestions' => 'mc_booking_reassign_sug',
	];

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
	) {
	}

	private function tablePrefix(): string
	{
		return (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		foreach (self::LOGICAL_RENAMES as $oldLogical => $newLogical) {
			$this->renameLogicalTableIfNeeded($oldLogical, $newLogical, $output);
		}
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES) {
			foreach (self::LOGICAL_RENAMES as $oldLogical => $newLogical) {
				$this->renamePostgresSequenceIfNeeded($oldLogical, $newLogical, $output);
			}
		}
	}

	private function renameLogicalTableIfNeeded(string $oldLogical, string $newLogical, IOutput $output): void
	{
		$oldExists = $this->db->tableExists($oldLogical);
		$newExists = $this->db->tableExists($newLogical);

		if (!$oldExists && $newExists) {
			return;
		}
		if (!$oldExists && !$newExists) {
			return;
		}
		if ($oldExists && $newExists) {
			if ($this->isTableEmpty($newLogical)) {
				$this->dropEmptyTable($newLogical, $output);
			} else {
				throw new RuntimeException(sprintf(
					'MobilityCheck: refusing to rename %s -> %s because the target table already exists and is not empty.',
					$oldLogical,
					$newLogical
				));
			}
		}

		if (!$this->db->tableExists($oldLogical)) {
			return;
		}

		$prefix = $this->tablePrefix();
		$oldTable = $prefix . $oldLogical;
		$newTable = $prefix . $newLogical;
		$this->assertSafeSqlIdentifier($oldTable);
		$this->assertSafeSqlIdentifier($newTable);

		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_MYSQL) {
			$this->db->executeStatement(sprintf(
				'RENAME TABLE `%s` TO `%s`',
				$oldTable,
				$newTable
			));
		} else {
			$this->db->executeStatement(sprintf(
				'ALTER TABLE "%s" RENAME TO "%s"',
				$oldTable,
				$newTable
			));
		}
		$output->info(sprintf('MobilityCheck: renamed table %s to %s.', $oldLogical, $newLogical));
	}

	private function dropEmptyTable(string $logicalName, IOutput $output): void
	{
		$prefixed = $this->tablePrefix() . $logicalName;
		$this->assertSafeSqlIdentifier($prefixed);
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_MYSQL) {
			$this->db->executeStatement('DROP TABLE IF EXISTS `' . $prefixed . '`');
		} else {
			$this->db->executeStatement('DROP TABLE IF EXISTS "' . $prefixed . '"');
		}
		$output->info(sprintf('MobilityCheck: dropped empty %s before rename from legacy name.', $logicalName));
	}

	private function renamePostgresSequenceIfNeeded(string $oldLogical, string $newLogical, IOutput $output): void
	{
		$prefix = $this->tablePrefix();
		$oldSeq = $prefix . $oldLogical . '_id_seq';
		$newSeq = $prefix . $newLogical . '_id_seq';
		$this->assertSafeSqlIdentifier($oldSeq);
		$this->assertSafeSqlIdentifier($newSeq);

		if (!$this->postgresSequenceExists($oldSeq)) {
			return;
		}
		if ($this->postgresSequenceExists($newSeq)) {
			return;
		}

		try {
			$this->db->executeStatement(sprintf(
				'ALTER SEQUENCE "%s" RENAME TO "%s"',
				$oldSeq,
				$newSeq
			));
			$output->info(sprintf('MobilityCheck (PostgreSQL): renamed sequence %s to %s.', $oldSeq, $newSeq));
		} catch (\Throwable $e) {
			$output->warning(sprintf(
				'MobilityCheck (PostgreSQL): could not rename sequence %s: %s',
				$oldSeq,
				$e->getMessage()
			));
		}
	}

	private function postgresSequenceExists(string $sequenceName): bool
	{
		try {
			$rs = $this->db->executeQuery(
				'SELECT 1 FROM pg_class WHERE relkind = \'S\' AND relname = ?',
				[$sequenceName]
			);
			$found = $rs->fetchOne();
			$rs->closeCursor();
			return $found !== false;
		} catch (\Throwable $e) {
			return false;
		}
	}

	private function isTableEmpty(string $logicalTable): bool
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'c'))
				->from($logicalTable);
			$rs = $qb->executeQuery();
			$row = $rs->fetch();
			$rs->closeCursor();
			return ((int)($row['c'] ?? 0)) === 0;
		} catch (\Throwable $e) {
			return false;
		}
	}

	private function assertSafeSqlIdentifier(string $name): void
	{
		if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
			throw new \InvalidArgumentException('Invalid SQL identifier: ' . $name);
		}
	}
}
