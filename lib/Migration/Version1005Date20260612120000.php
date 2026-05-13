<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Server;

/**
 * §4.2a stations, §4.5a §E approval chain snapshots, §4.5b/c booking fields,
 * §4.6.4 handover photos, §4.6.8 fuel chargeback, §4.6.9 QR tokens,
 * §4.7.5 damage chargeback, mc_cost_centres, mc_relocation_tasks.
 */
class Version1005Date20260612120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('mc_stations')) {
			$t = $schema->createTable('mc_stations');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 40, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 120, 'notnull' => true]);
			$t->addColumn('address_line_1', Types::STRING, ['length' => 120, 'notnull' => false]);
			$t->addColumn('address_line_2', Types::STRING, ['length' => 120, 'notnull' => false]);
			$t->addColumn('postal_code', Types::STRING, ['length' => 20, 'notnull' => false]);
			$t->addColumn('city', Types::STRING, ['length' => 80, 'notnull' => false]);
			$t->addColumn('country_code', Types::STRING, ['length' => 2, 'notnull' => true, 'default' => 'DE']);
			$t->addColumn('timezone', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => 'Europe/Berlin']);
			$t->addColumn('latitude', Types::DECIMAL, ['notnull' => false, 'precision' => 9, 'scale' => 6]);
			$t->addColumn('longitude', Types::DECIMAL, ['notnull' => false, 'precision' => 9, 'scale' => 6]);
			$t->addColumn('default_language', Types::STRING, ['length' => 2, 'notnull' => true, 'default' => 'de']);
			$t->addColumn('is_active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('notification_recipient_user_ids', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_by_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_st_pk');
			$t->addUniqueIndex(['code'], 'mc_st_code_uidx');
			$t->addIndex(['is_active'], 'mc_st_active_idx');
		}

		if (!$schema->hasTable('mc_relocation_tasks')) {
			$t = $schema->createTable('mc_relocation_tasks');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('source_booking_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('from_station_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('to_station_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('opened_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'open']);
			$t->addColumn('assigned_to_user_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('completed_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'mc_rt_pk');
			$t->addIndex(['vehicle_id'], 'mc_rt_veh_idx');
			$t->addIndex(['status'], 'mc_rt_stat_idx');
		}

		if (!$schema->hasTable('mc_cost_centres')) {
			$t = $schema->createTable('mc_cost_centres');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 40, 'notnull' => true]);
			$t->addColumn('label', Types::STRING, ['length' => 120, 'notnull' => true]);
			$t->addColumn('owner_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('is_active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_cco_pk');
			$t->addUniqueIndex(['code'], 'mc_cco_code_uidx');
		}

		if ($schema->hasTable('mc_bookings')) {
			$t = $schema->getTable('mc_bookings');
			if ($t->hasColumn('status')) {
				$c = $t->getColumn('status');
				if ($c->getLength() < 64) {
					$c->setLength(64);
				}
			}
			if (!$t->hasColumn('approval_chain_snapshot_json')) {
				$t->addColumn('approval_chain_snapshot_json', Types::TEXT, ['notnull' => false]);
			}
			if (!$t->hasColumn('pickup_station_id')) {
				$t->addColumn('pickup_station_id', Types::BIGINT, ['notnull' => false]);
			}
			if (!$t->hasColumn('return_station_id')) {
				$t->addColumn('return_station_id', Types::BIGINT, ['notnull' => false]);
			}
			if (!$t->hasColumn('cross_station_reason')) {
				$t->addColumn('cross_station_reason', Types::TEXT, ['notnull' => false]);
			}
			if (!$t->hasColumn('proxy_unacknowledged')) {
				$t->addColumn('proxy_unacknowledged', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			}
			if (!$t->hasColumn('passengers')) {
				$t->addColumn('passengers', Types::STRING, ['length' => 500, 'notnull' => false]);
			}
			if (!$t->hasColumn('passenger_user_ids')) {
				$t->addColumn('passenger_user_ids', Types::TEXT, ['notnull' => false]);
			}
		}

		if ($schema->hasTable('mc_vehicles')) {
			$t = $schema->getTable('mc_vehicles');
			if (!$t->hasColumn('station_id')) {
				$t->addColumn('station_id', Types::BIGINT, ['notnull' => false]);
			}
			if (!$t->hasColumn('self_service_enabled')) {
				$t->addColumn('self_service_enabled', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			}
			if (!$t->hasColumn('photo_evidence_required_at_checkout')) {
				$t->addColumn('photo_evidence_required_at_checkout', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			}
			if (!$t->hasColumn('photo_evidence_required_at_checkin')) {
				$t->addColumn('photo_evidence_required_at_checkin', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			}
			if (!$t->hasColumn('photo_evidence_minimum_count')) {
				$t->addColumn('photo_evidence_minimum_count', Types::INTEGER, ['notnull' => true, 'default' => 4]);
			}
			if (!$t->hasColumn('fuel_minimum_at_return')) {
				$t->addColumn('fuel_minimum_at_return', Types::STRING, ['length' => 24, 'notnull' => false]);
			}
			if (!$t->hasColumn('charge_minimum_at_return_percent')) {
				$t->addColumn('charge_minimum_at_return_percent', Types::INTEGER, ['notnull' => false]);
			}
			if (!$t->hasColumn('qr_token_hash')) {
				$t->addColumn('qr_token_hash', Types::STRING, ['length' => 64, 'notnull' => false]);
			}
			if (!$t->hasColumn('qr_token_rotated_at')) {
				$t->addColumn('qr_token_rotated_at', Types::DATETIME, ['notnull' => false]);
			}
		}

		if ($schema->hasTable('mc_driver_profiles')) {
			$t = $schema->getTable('mc_driver_profiles');
			if (!$t->hasColumn('home_station_id')) {
				$t->addColumn('home_station_id', Types::BIGINT, ['notnull' => false]);
			}
		}

		if ($schema->hasTable('mc_booking_approvals')) {
			$t = $schema->getTable('mc_booking_approvals');
			if ($t->hasColumn('step')) {
				$t->getColumn('step')->setLength(64);
			}
			if (!$t->hasColumn('is_superseded')) {
				$t->addColumn('is_superseded', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			}
			if (!$t->hasColumn('supersede_reason')) {
				$t->addColumn('supersede_reason', Types::TEXT, ['notnull' => false]);
			}
		}

		if ($schema->hasTable('mc_damage_photos')) {
			$t = $schema->getTable('mc_damage_photos');
			if ($t->hasColumn('damage_report_id')) {
				$t->getColumn('damage_report_id')->setNotnull(false);
			}
			if (!$t->hasColumn('vehicle_id')) {
				$t->addColumn('vehicle_id', Types::BIGINT, ['notnull' => false]);
			}
			if (!$t->hasColumn('booking_id')) {
				$t->addColumn('booking_id', Types::BIGINT, ['notnull' => false]);
			}
			if (!$t->hasColumn('evidence_type')) {
				$t->addColumn('evidence_type', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'damage']);
			}
			try {
				$t->addIndex(['booking_id', 'evidence_type'], 'mc_dmp_book_ev_idx');
			} catch (\Throwable) {
			}
		}

		if ($schema->hasTable('mc_cost_entries')) {
			$t = $schema->getTable('mc_cost_entries');
			if (!$t->hasColumn('chargeable_to_driver')) {
				$t->addColumn('chargeable_to_driver', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			}
			if (!$t->hasColumn('charge_driver_user_id')) {
				$t->addColumn('charge_driver_user_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			}
			if (!$t->hasColumn('linked_booking_id')) {
				$t->addColumn('linked_booking_id', Types::BIGINT, ['notnull' => false]);
			}
			if (!$t->hasColumn('driver_acknowledged_at')) {
				$t->addColumn('driver_acknowledged_at', Types::DATETIME, ['notnull' => false]);
			}
			if (!$t->hasColumn('driver_disputed_at')) {
				$t->addColumn('driver_disputed_at', Types::DATETIME, ['notnull' => false]);
			}
			if (!$t->hasColumn('driver_dispute_reason')) {
				$t->addColumn('driver_dispute_reason', Types::TEXT, ['notnull' => false]);
			}
			if (!$t->hasColumn('dispute_resolved_at')) {
				$t->addColumn('dispute_resolved_at', Types::DATETIME, ['notnull' => false]);
			}
			if (!$t->hasColumn('dispute_resolution')) {
				$t->addColumn('dispute_resolution', Types::TEXT, ['notnull' => false]);
			}
		}

		if ($schema->hasTable('mc_damage_reports')) {
			$t = $schema->getTable('mc_damage_reports');
			if (!$t->hasColumn('chargeable_to_user_id')) {
				$t->addColumn('chargeable_to_user_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			}
			if (!$t->hasColumn('chargeback_driver_acknowledged_at')) {
				$t->addColumn('chargeback_driver_acknowledged_at', Types::DATETIME, ['notnull' => false]);
			}
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$connection = Server::get(IDBConnection::class);
		$qb = $connection->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('mc_cost_categories')
			->where($qb->expr()->eq('name', $qb->createNamedParameter('driver_chargeback_fuel')));
		if ((int)($qb->executeQuery()->fetchOne() ?: 0) > 0) {
			return;
		}
		$ins = $connection->getQueryBuilder();
		$ins->insert('mc_cost_categories')->values([
			'name' => $ins->createNamedParameter('driver_chargeback_fuel'),
			'is_active' => $ins->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		]);
		try {
			$ins->executeStatement();
		} catch (\Throwable) {
			// ignore duplicate
		}
	}
}
