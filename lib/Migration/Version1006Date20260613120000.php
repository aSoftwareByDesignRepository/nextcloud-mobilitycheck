<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * §A5.4 — Intelligent allocation: lease metadata on vehicles, booking
 * reassignment trace + manual flag, and `mc_booking_reassign_sug`.
 */
class Version1006Date20260613120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('mc_vehicles')) {
			$t = $schema->getTable('mc_vehicles');
			if (!$t->hasColumn('lease_start_date')) {
				$t->addColumn('lease_start_date', Types::DATE, ['notnull' => false]);
			}
			if (!$t->hasColumn('lease_end_date')) {
				$t->addColumn('lease_end_date', Types::DATE, ['notnull' => false]);
			}
			if (!$t->hasColumn('lease_included_km')) {
				$t->addColumn('lease_included_km', Types::INTEGER, ['notnull' => false]);
			}
			if (!$t->hasColumn('lease_reference')) {
				$t->addColumn('lease_reference', Types::STRING, ['length' => 120, 'notnull' => false]);
			}
			if (!$t->hasColumn('do_not_auto_allocate')) {
				$t->addColumn('do_not_auto_allocate', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			}
		}

		if ($schema->hasTable('mc_bookings')) {
			$t = $schema->getTable('mc_bookings');
			if (!$t->hasColumn('reassigned_from_vehicle_id')) {
				$t->addColumn('reassigned_from_vehicle_id', Types::BIGINT, ['notnull' => false]);
			}
			if (!$t->hasColumn('flag_requires_manual_reassignment')) {
				$t->addColumn('flag_requires_manual_reassignment', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			}
		}

		if (!$schema->hasTable('mc_booking_reassign_sug')) {
			$t = $schema->createTable('mc_booking_reassign_sug');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('booking_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('from_vehicle_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('to_vehicle_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('score_breakdown_json', Types::TEXT, ['notnull' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'open']);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('resolved_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('resolved_by_user_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->setPrimaryKey(['id'], 'mc_brs_pk');
			$t->addIndex(['booking_id', 'status'], 'mc_brs_bk_stat_idx');
			$t->addIndex(['from_vehicle_id'], 'mc_brs_from_veh_idx');
		}

		return $schema;
	}
}
