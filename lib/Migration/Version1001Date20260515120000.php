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
 * Appendix A schema — assignments, logbook, reimbursement, search,
 * odometer readings, export tokens.
 */
class Version1001Date20260515120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('mc_checkout_logs')) {
			$t = $schema->getTable('mc_checkout_logs');
			if (!$t->hasColumn('confirmed_business_only')) {
				$t->addColumn('confirmed_business_only', Types::SMALLINT, ['notnull' => false]);
			}
		}

		// A2.1 mc_vehicle_assignments
		if (!$schema->hasTable('mc_vehicle_assignments')) {
			$t = $schema->createTable('mc_vehicle_assignments');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('assignment_mode', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('assigned_user_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('assigned_group_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('valid_from', Types::DATE, ['notnull' => true]);
			$t->addColumn('valid_until', Types::DATE, ['notnull' => false]);
			$t->addColumn('tax_treatment', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'business_only']);
			$t->addColumn('monthly_gross_list_price_minor', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_by_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_va_pk');
			$t->addIndex(['vehicle_id', 'valid_from'], 'mc_va_veh_from_idx');
			$t->addIndex(['assigned_user_id'], 'mc_va_user_idx');
		}

		// A2.2 mc_vehicle_group_members
		if (!$schema->hasTable('mc_vehicle_group_members')) {
			$t = $schema->createTable('mc_vehicle_group_members');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('group_name', Types::STRING, ['length' => 120, 'notnull' => true]);
			$t->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_vgm_pk');
			$t->addUniqueIndex(['vehicle_id', 'user_id'], 'mc_vgm_uidx');
			$t->addIndex(['vehicle_id'], 'mc_vgm_veh_idx');
		}

		// A2.3 mc_vehicle_features
		if (!$schema->hasTable('mc_vehicle_features')) {
			$t = $schema->createTable('mc_vehicle_features');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('feature_key', Types::STRING, ['length' => 80, 'notnull' => true]);
			$t->addColumn('feature_value', Types::STRING, ['length' => 120, 'notnull' => false]);
			$t->setPrimaryKey(['id'], 'mc_vf_pk');
			$t->addIndex(['vehicle_id', 'feature_key'], 'mc_vf_veh_key_idx');
		}

		// A3.2.1 mc_logbook_entries
		if (!$schema->hasTable('mc_logbook_entries')) {
			$t = $schema->createTable('mc_logbook_entries');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('assignment_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('booking_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('driver_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('trip_type', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('trip_date', Types::DATE, ['notnull' => true]);
			$t->addColumn('departure_time', Types::STRING, ['length' => 12, 'notnull' => false]);
			$t->addColumn('arrival_time', Types::STRING, ['length' => 12, 'notnull' => false]);
			$t->addColumn('start_address', Types::STRING, ['length' => 250, 'notnull' => true]);
			$t->addColumn('end_address', Types::STRING, ['length' => 250, 'notnull' => true]);
			$t->addColumn('odometer_start_km', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('odometer_end_km', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('distance_km', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('purpose', Types::TEXT, ['notnull' => false]);
			$t->addColumn('client_or_contact', Types::STRING, ['length' => 250, 'notnull' => false]);
			$t->addColumn('project_reference', Types::STRING, ['length' => 120, 'notnull' => false]);
			$t->addColumn('is_round_trip', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('late_entry', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('confirmed_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('confirmed_by_user_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('amendment_of_entry_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('amendment_reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('is_superseded', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_lb_pk');
			$t->addIndex(['vehicle_id', 'trip_date'], 'mc_lb_veh_date_idx');
			$t->addIndex(['driver_user_id'], 'mc_lb_drv_idx');
			$t->addIndex(['booking_id'], 'mc_lb_bk_idx');
			$t->addIndex(['confirmed_at'], 'mc_lb_conf_idx');
		}

		// A6 mc_odometer_readings
		if (!$schema->hasTable('mc_odometer_readings')) {
			$t = $schema->createTable('mc_odometer_readings');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('driver_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('reading_km', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('reading_date', Types::DATE, ['notnull' => true]);
			$t->addColumn('source', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_od_pk');
			$t->addIndex(['vehicle_id', 'reading_date'], 'mc_od_veh_date_idx');
		}

		// A4.2 mc_private_vehicles
		if (!$schema->hasTable('mc_private_vehicles')) {
			$t = $schema->createTable('mc_private_vehicles');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('driver_profile_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('make', Types::STRING, ['length' => 80, 'notnull' => true]);
			$t->addColumn('model', Types::STRING, ['length' => 80, 'notnull' => true]);
			$t->addColumn('licence_plate', Types::STRING, ['length' => 20, 'notnull' => true]);
			$t->addColumn('engine_type', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('is_active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_pv_pk');
			$t->addIndex(['driver_profile_id'], 'mc_pv_drv_idx');
		}

		// A4.3 mc_reimbursement_claims
		if (!$schema->hasTable('mc_reimbursement_claims')) {
			$t = $schema->createTable('mc_reimbursement_claims');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('driver_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('private_vehicle_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('trip_date', Types::DATE, ['notnull' => true]);
			$t->addColumn('departure_time', Types::STRING, ['length' => 12, 'notnull' => false]);
			$t->addColumn('arrival_time', Types::STRING, ['length' => 12, 'notnull' => false]);
			$t->addColumn('start_address', Types::STRING, ['length' => 250, 'notnull' => true]);
			$t->addColumn('end_address', Types::STRING, ['length' => 250, 'notnull' => true]);
			$t->addColumn('distance_km', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('distance_verified_km', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('purpose', Types::TEXT, ['notnull' => true]);
			$t->addColumn('client_or_contact', Types::STRING, ['length' => 250, 'notnull' => false]);
			$t->addColumn('project_reference', Types::STRING, ['length' => 120, 'notnull' => false]);
			$t->addColumn('passengers', Types::TEXT, ['notnull' => false]);
			$t->addColumn('rate_per_km_minor', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('amount_claimable_minor', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('amount_taxable_minor', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'draft']);
			$t->addColumn('submitted_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('reviewed_by_user_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('reviewed_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('rejection_reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('payment_reference', Types::STRING, ['length' => 120, 'notnull' => false]);
			$t->addColumn('receipt_file_id', Types::STRING, ['length' => 128, 'notnull' => false]);
			$t->addColumn('jurisdiction_code', Types::STRING, ['length' => 10, 'notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_rc_pk');
			$t->addIndex(['driver_user_id', 'trip_date'], 'mc_rc_drv_date_idx');
			$t->addIndex(['status'], 'mc_rc_stat_idx');
		}

		// A6 mc_reim_rate_cfg (short name: `oc_` + logical must be ≤30 chars for Oracle-safe installs)
		if (!$schema->hasTable('mc_reim_rate_cfg')) {
			$t = $schema->createTable('mc_reim_rate_cfg');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('jurisdiction_code', Types::STRING, ['length' => 10, 'notnull' => true]);
			$t->addColumn('vehicle_type', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('rate_tier', Types::SMALLINT, ['notnull' => true]);
			$t->addColumn('tier_threshold_km', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('rate_per_km_minor', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('taxable_above_minor', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('valid_from', Types::DATE, ['notnull' => true]);
			$t->addColumn('valid_until', Types::DATE, ['notnull' => false]);
			$t->addColumn('created_by_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_rr_pk');
			$t->addIndex(['jurisdiction_code', 'vehicle_type', 'valid_from'], 'mc_rr_jvt_idx');
		}

		// A5.3 mc_search_profiles
		if (!$schema->hasTable('mc_search_profiles')) {
			$t = $schema->createTable('mc_search_profiles');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 120, 'notnull' => true]);
			$t->addColumn('requirements_json', Types::TEXT, ['notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_sp_pk');
			$t->addIndex(['user_id'], 'mc_sp_user_idx');
		}

		// Export download tokens (A7 stream hand-off)
		if (!$schema->hasTable('mc_export_downloads')) {
			$t = $schema->createTable('mc_export_downloads');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('token', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('mime_type', Types::STRING, ['length' => 80, 'notnull' => true]);
			$t->addColumn('filename', Types::STRING, ['length' => 180, 'notnull' => true]);
			$t->addColumn('storage_path', Types::STRING, ['length' => 512, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('expires_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_ed_pk');
			$t->addUniqueIndex(['token'], 'mc_ed_tok_uidx');
			$t->addIndex(['user_id'], 'mc_ed_user_idx');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$connection = Server::get(IDBConnection::class);

		// Default pool assignment per vehicle (Appendix A bootstrap).
		$qb = $connection->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'c')->from('mc_vehicle_assignments');
		if ((int)($qb->executeQuery()->fetchOne() ?: 0) > 0) {
			$this->seedReimbursementRates($connection);
			return;
		}

		$vehicles = $connection->getQueryBuilder();
		$vehicles->select('id')->from('mc_vehicles');
		$res = $vehicles->executeQuery();
		$now = gmdate('Y-m-d H:i:s');
		$today = gmdate('Y-m-d');
		while (($row = $res->fetch()) !== false) {
			$vid = (int)$row['id'];
			$ins = $connection->getQueryBuilder();
			$ins->insert('mc_vehicle_assignments')
				->values([
					'vehicle_id' => $ins->createNamedParameter($vid, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
					'assignment_mode' => $ins->createNamedParameter('pool'),
					'assigned_user_id' => $ins->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL),
					'assigned_group_id' => $ins->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL),
					'valid_from' => $ins->createNamedParameter('1970-01-01'),
					'valid_until' => $ins->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL),
					'tax_treatment' => $ins->createNamedParameter('business_only'),
					'monthly_gross_list_price_minor' => $ins->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL),
					'notes' => $ins->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL),
					'created_by_user_id' => $ins->createNamedParameter('system'),
					'created_at' => $ins->createNamedParameter($now),
					'updated_at' => $ins->createNamedParameter($now),
				]);
			try {
				$ins->executeStatement();
			} catch (\Throwable) {
				// Skip duplicates / races.
			}
		}
		$res->closeCursor();

		$this->seedReimbursementRates($connection);
	}

	private function seedReimbursementRates(IDBConnection $connection): void
	{
		$c = $connection->getQueryBuilder();
		$c->selectAlias($c->func()->count('*'), 'n')->from('mc_reim_rate_cfg');
		if ((int)($c->executeQuery()->fetchOne() ?: 0) > 0) {
			return;
		}
		$now = gmdate('Y-m-d H:i:s');
		$today = gmdate('Y-m-d');
		// Illustrative DE statutory-style tiers (cents per km); admins replace via UI/API.
		$rows = [
			['DE', 'car', 1, 20, 30, null],
			['DE', 'car', 2, null, 38, null],
			['DE', 'electric', 1, 20, 30, null],
			['DE', 'electric', 2, null, 38, null],
		];
		foreach ($rows as $r) {
			$ins = $connection->getQueryBuilder();
			$ins->insert('mc_reim_rate_cfg')->values([
				'jurisdiction_code' => $ins->createNamedParameter($r[0]),
				'vehicle_type' => $ins->createNamedParameter($r[1]),
				'rate_tier' => $ins->createNamedParameter($r[2], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'tier_threshold_km' => $r[3] === null
					? $ins->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL)
					: $ins->createNamedParameter($r[3], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'rate_per_km_minor' => $ins->createNamedParameter($r[4], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'taxable_above_minor' => $ins->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL),
				'valid_from' => $ins->createNamedParameter($today),
				'valid_until' => $ins->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL),
				'created_by_user_id' => $ins->createNamedParameter('system'),
				'created_at' => $ins->createNamedParameter($now),
			]);
			$ins->executeStatement();
		}
	}
}
