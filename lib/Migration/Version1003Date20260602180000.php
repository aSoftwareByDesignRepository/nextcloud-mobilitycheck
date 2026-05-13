<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Server;

/**
 * Line manager workflow: assignments + booking approval audit + booking columns.
 * Normalises legacy `pending_approval` → `pending_fleet`.
 */
class Version1003Date20260602180000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('mc_bookings')) {
			$t = $schema->getTable('mc_bookings');
			if (!$t->hasColumn('approval_mode_snapshot')) {
				$t->addColumn('approval_mode_snapshot', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => 'none']);
			}
			if (!$t->hasColumn('is_escalated')) {
				$t->addColumn('is_escalated', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			}
			if (!$t->hasColumn('assisted_handover_required')) {
				$t->addColumn('assisted_handover_required', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			}
		}

		if (!$schema->hasTable('mc_line_manager_assignments')) {
			$t = $schema->createTable('mc_line_manager_assignments');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('driver_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('line_manager_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('valid_from', Types::STRING, ['length' => 10, 'notnull' => true]);
			$t->addColumn('valid_until', Types::STRING, ['length' => 10, 'notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_by_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_lma_pk');
			$t->addIndex(['driver_user_id', 'valid_from'], 'mc_lma_drv_from_idx');
			$t->addIndex(['line_manager_user_id'], 'mc_lma_lm_idx');
		}

		if (!$schema->hasTable('mc_booking_approvals')) {
			$t = $schema->createTable('mc_booking_approvals');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('booking_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('step', Types::STRING, ['length' => 24, 'notnull' => true]);
			$t->addColumn('decision', Types::STRING, ['length' => 24, 'notnull' => true]);
			$t->addColumn('approver_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('approver_role', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('decided_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('reason', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'mc_ba_pk');
			$t->addIndex(['booking_id', 'step'], 'mc_ba_bk_step_idx');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$connection = Server::get(IDBConnection::class);
		$qb = $connection->getQueryBuilder();
		$qb->update('mc_bookings')
			->set('status', $qb->createNamedParameter('pending_fleet'))
			->where($qb->expr()->eq('status', $qb->createNamedParameter('pending_approval')));
		$qb->executeStatement();

		$config = Server::get(IConfig::class);
		$appId = 'mobilitycheck';
		if ($config->getAppValue($appId, 'approval_mode', '') === '') {
			$wf = $config->getAppValue($appId, 'approval_workflow', '0');
			$config->setAppValue($appId, 'approval_mode', $wf === '1' ? 'fleet_manager' : 'none');
		}

		$upd = $connection->getQueryBuilder();
		$upd->update('mc_bookings')
			->set('approval_mode_snapshot', $upd->createNamedParameter('fleet_manager'))
			->where($upd->expr()->eq('status', $upd->createNamedParameter('pending_fleet')));
		$upd->executeStatement();
	}
}
