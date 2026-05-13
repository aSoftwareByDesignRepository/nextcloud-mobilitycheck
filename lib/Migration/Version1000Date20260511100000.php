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
 * Initial schema for MobilityCheck core (§6 of the spec).
 *
 * One migration covers the entire core data model because every
 * table has dependencies on at least one other, and partial states
 * (e.g. "vehicles exist but no role table") would corrupt the
 * permission model. Appendix A tables ship in a separate migration
 * (Version1001) so an operator can opt out of the extended modules
 * by skipping later versions.
 */
class Version1000Date20260511100000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// 6.13 mc_user_roles
		if (!$schema->hasTable('mc_user_roles')) {
			$t = $schema->createTable('mc_user_roles');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('role', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_urole_pk');
			$t->addUniqueIndex(['user_id', 'role'], 'mc_urole_uidx');
			$t->addIndex(['role'], 'mc_urole_role_idx');
		}

		// 6.1 mc_vehicles
		if (!$schema->hasTable('mc_vehicles')) {
			$t = $schema->createTable('mc_vehicles');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('internal_name', Types::STRING, ['length' => 120, 'notnull' => true]);
			$t->addColumn('make', Types::STRING, ['length' => 80, 'notnull' => true]);
			$t->addColumn('model', Types::STRING, ['length' => 80, 'notnull' => true]);
			$t->addColumn('year', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('licence_plate', Types::STRING, ['length' => 20, 'notnull' => true]);
			$t->addColumn('colour', Types::STRING, ['length' => 40, 'notnull' => false]);
			$t->addColumn('fuel_type', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'petrol']);
			$t->addColumn('transmission', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'manual']);
			$t->addColumn('seating_capacity', Types::SMALLINT, ['notnull' => true, 'default' => 5]);
			$t->addColumn('required_licence_class', Types::STRING, ['length' => 20, 'notnull' => true, 'default' => 'B']);
			$t->addColumn('base_location', Types::STRING, ['length' => 120, 'notnull' => false]);
			$t->addColumn('status', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'available']);
			$t->addColumn('odometer_km', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('insurance_policy_number', Types::STRING, ['length' => 80, 'notnull' => false]);
			$t->addColumn('insurance_expiry_date', Types::DATE, ['notnull' => false]);
			$t->addColumn('road_tax_expiry_date', Types::DATE, ['notnull' => false]);
			$t->addColumn('next_service_date', Types::DATE, ['notnull' => false]);
			$t->addColumn('next_service_odometer_km', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('photo_file_id', Types::STRING, ['length' => 128, 'notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('is_active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_veh_pk');
			$t->addUniqueIndex(['licence_plate'], 'mc_veh_plate_uidx');
			$t->addIndex(['status'], 'mc_veh_status_idx');
			$t->addIndex(['is_active'], 'mc_veh_active_idx');
		}

		// 6.2 mc_driver_profiles
		if (!$schema->hasTable('mc_driver_profiles')) {
			$t = $schema->createTable('mc_driver_profiles');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('licence_number', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('licence_classes', Types::STRING, ['length' => 80, 'notnull' => false]);
			$t->addColumn('licence_issue_date', Types::DATE, ['notnull' => false]);
			$t->addColumn('licence_expiry_date', Types::DATE, ['notnull' => false]);
			$t->addColumn('licence_authority', Types::STRING, ['length' => 120, 'notnull' => false]);
			$t->addColumn('licence_scan_file_id', Types::STRING, ['length' => 128, 'notnull' => false]);
			$t->addColumn('licence_status', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => 'not_provided']);
			$t->addColumn('compliance_status', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => 'blocked']);
			$t->addColumn('commute_distance_km', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_drv_pk');
			$t->addUniqueIndex(['user_id'], 'mc_drv_uidx');
			$t->addIndex(['licence_status'], 'mc_drv_lstat_idx');
		}

		// 6.3 mc_licence_verifications (immutable)
		if (!$schema->hasTable('mc_licence_verifications')) {
			$t = $schema->createTable('mc_licence_verifications');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('driver_profile_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('verified_by_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('verified_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('licence_expiry_at_verification', Types::DATE, ['notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'mc_lvr_pk');
			$t->addIndex(['driver_profile_id'], 'mc_lvr_drv_idx');
		}

		// 6.4 mc_instruction_records (immutable)
		if (!$schema->hasTable('mc_instruction_records')) {
			$t = $schema->createTable('mc_instruction_records');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('driver_profile_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('calendar_year', Types::SMALLINT, ['notnull' => true]);
			$t->addColumn('completed_date', Types::DATE, ['notnull' => true]);
			$t->addColumn('recorded_by_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('reference', Types::STRING, ['length' => 120, 'notnull' => false]);
			$t->addColumn('recorded_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_ir_pk');
			$t->addUniqueIndex(['driver_profile_id', 'calendar_year'], 'mc_ir_uidx');
		}

		// 6.5 mc_bookings
		if (!$schema->hasTable('mc_bookings')) {
			$t = $schema->createTable('mc_bookings');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('driver_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('start_datetime', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('end_datetime', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'pending_approval']);
			$t->addColumn('purpose', Types::STRING, ['length' => 250, 'notnull' => false]);
			$t->addColumn('destination', Types::STRING, ['length' => 250, 'notnull' => false]);
			$t->addColumn('cost_centre', Types::STRING, ['length' => 80, 'notnull' => false]);
			$t->addColumn('expected_distance_km', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('rejection_reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('cancellation_reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('auto_rescheduled_from_booking_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('approved_by_user_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('approved_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('created_by_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_bk_pk');
			$t->addIndex(['vehicle_id', 'start_datetime', 'end_datetime'], 'mc_bk_veh_window_idx');
			$t->addIndex(['driver_user_id'], 'mc_bk_drv_idx');
			$t->addIndex(['status'], 'mc_bk_status_idx');
		}

		// 6.6 mc_checkout_logs (immutable)
		if (!$schema->hasTable('mc_checkout_logs')) {
			$t = $schema->createTable('mc_checkout_logs');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('booking_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('event_type', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('odometer_km', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('fuel_level', Types::STRING, ['length' => 16, 'notnull' => false]);
			$t->addColumn('condition_notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('condition_zones_ok', Types::TEXT, ['notnull' => false]);
			$t->addColumn('recorded_by_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('recorded_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_col_pk');
			$t->addIndex(['booking_id', 'event_type'], 'mc_col_bk_idx');
		}

		// 6.7 mc_damage_reports
		if (!$schema->hasTable('mc_damage_reports')) {
			$t = $schema->createTable('mc_damage_reports');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('booking_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('reported_by_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('discovery_datetime', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('description', Types::TEXT, ['notnull' => true]);
			$t->addColumn('zone', Types::STRING, ['length' => 24, 'notnull' => true]);
			$t->addColumn('severity', Types::STRING, ['length' => 24, 'notnull' => true]);
			$t->addColumn('is_driveable', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('status', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'reported']);
			$t->addColumn('amendment_of_report_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('amendment_reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_dm_pk');
			$t->addIndex(['vehicle_id'], 'mc_dm_veh_idx');
			$t->addIndex(['status'], 'mc_dm_status_idx');
			$t->addIndex(['severity'], 'mc_dm_sev_idx');
		}

		// 6.8 mc_damage_photos
		if (!$schema->hasTable('mc_damage_photos')) {
			$t = $schema->createTable('mc_damage_photos');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('damage_report_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('file_id', Types::STRING, ['length' => 128, 'notnull' => true]);
			$t->addColumn('uploaded_by_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('uploaded_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_dmp_pk');
			$t->addIndex(['damage_report_id'], 'mc_dmp_rep_idx');
		}

		// 6.9 mc_repair_jobs
		if (!$schema->hasTable('mc_repair_jobs')) {
			$t = $schema->createTable('mc_repair_jobs');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('damage_report_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('assigned_workshop_user_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('external_workshop_name', Types::STRING, ['length' => 120, 'notnull' => false]);
			$t->addColumn('external_workshop_contact', Types::STRING, ['length' => 120, 'notnull' => false]);
			$t->addColumn('estimated_cost_minor', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('actual_cost_minor', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('target_date', Types::DATE, ['notnull' => false]);
			$t->addColumn('actual_completion_date', Types::DATE, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'open']);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('invoice_file_id', Types::STRING, ['length' => 128, 'notnull' => false]);
			$t->addColumn('created_by_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_rj_pk');
			$t->addIndex(['vehicle_id'], 'mc_rj_veh_idx');
			$t->addIndex(['assigned_workshop_user_id'], 'mc_rj_ws_idx');
			$t->addIndex(['status'], 'mc_rj_status_idx');
		}

		// 6.11 mc_cost_categories
		if (!$schema->hasTable('mc_cost_categories')) {
			$t = $schema->createTable('mc_cost_categories');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 80, 'notnull' => true]);
			$t->addColumn('is_active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->setPrimaryKey(['id'], 'mc_cc_pk');
			$t->addUniqueIndex(['name'], 'mc_cc_name_uidx');
		}

		// 6.10 mc_cost_entries
		if (!$schema->hasTable('mc_cost_entries')) {
			$t = $schema->createTable('mc_cost_entries');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('booking_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('repair_job_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('category_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('entry_date', Types::DATE, ['notnull' => true]);
			$t->addColumn('amount_gross_minor', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('vat_rate_bp', Types::INTEGER, ['notnull' => true, 'default' => 1900]);
			$t->addColumn('amount_net_minor', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('vat_amount_minor', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('receipt_reference', Types::STRING, ['length' => 128, 'notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('is_deleted', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('delete_reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_by_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_ce_pk');
			$t->addIndex(['vehicle_id', 'entry_date'], 'mc_ce_veh_date_idx');
			$t->addIndex(['category_id'], 'mc_ce_cat_idx');
		}

		// 6.12 mc_maintenance_schedules
		if (!$schema->hasTable('mc_maintenance_schedules')) {
			$t = $schema->createTable('mc_maintenance_schedules');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 120, 'notnull' => true]);
			$t->addColumn('trigger_type', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('calendar_interval_months', Types::SMALLINT, ['notnull' => false]);
			$t->addColumn('odometer_interval_km', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('next_due_date', Types::DATE, ['notnull' => false]);
			$t->addColumn('next_due_odometer_km', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('is_blocking', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('last_completed_date', Types::DATE, ['notnull' => false]);
			$t->addColumn('last_completed_odometer_km', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('is_active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_by_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_ms_pk');
			$t->addIndex(['vehicle_id'], 'mc_ms_veh_idx');
			$t->addIndex(['next_due_date'], 'mc_ms_due_date_idx');
		}

		// 6.14 mc_audit_log (append-only)
		if (!$schema->hasTable('mc_audit_log')) {
			$t = $schema->createTable('mc_audit_log');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('entity_type', Types::STRING, ['length' => 80, 'notnull' => true]);
			$t->addColumn('entity_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('action', Types::STRING, ['length' => 40, 'notnull' => true]);
			$t->addColumn('field_name', Types::STRING, ['length' => 80, 'notnull' => false]);
			$t->addColumn('old_value', Types::TEXT, ['notnull' => false]);
			$t->addColumn('new_value', Types::TEXT, ['notnull' => false]);
			$t->addColumn('performed_by_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('performed_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('ip_address', Types::STRING, ['length' => 45, 'notnull' => false]);
			$t->addColumn('reason', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'mc_al_pk');
			$t->addIndex(['entity_type', 'entity_id'], 'mc_al_ent_idx');
			$t->addIndex(['performed_at'], 'mc_al_at_idx');
			$t->addIndex(['performed_by_user_id'], 'mc_al_who_idx');
		}

		// 6.15 mc_notification_log
		if (!$schema->hasTable('mc_notification_log')) {
			$t = $schema->createTable('mc_notification_log');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('notification_type', Types::STRING, ['length' => 80, 'notnull' => true]);
			$t->addColumn('recipient_user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('entity_type', Types::STRING, ['length' => 80, 'notnull' => false]);
			$t->addColumn('entity_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('channel', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('dedupe_key', Types::STRING, ['length' => 191, 'notnull' => false]);
			$t->addColumn('sent_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'sent']);
			$t->addColumn('error_message', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'mc_nl_pk');
			$t->addIndex(['recipient_user_id'], 'mc_nl_rcpt_idx');
			$t->addIndex(['dedupe_key'], 'mc_nl_dedupe_idx');
			$t->addIndex(['notification_type'], 'mc_nl_type_idx');
		}

		// User preferences (onboarding dismiss, notification prefs)
		if (!$schema->hasTable('mc_user_preferences')) {
			$t = $schema->createTable('mc_user_preferences');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('pref_key', Types::STRING, ['length' => 80, 'notnull' => true]);
			$t->addColumn('pref_value', Types::TEXT, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_up_pk');
			$t->addUniqueIndex(['user_id', 'pref_key'], 'mc_up_uidx');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		// Seed default cost categories (§4.8) if the table is empty.
		$connection = Server::get(IDBConnection::class);
		$qb = $connection->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('mc_cost_categories');
		$count = (int)($qb->executeQuery()->fetchOne() ?: 0);
		if ($count > 0) {
			return;
		}
		$defaults = ['fuel', 'repair', 'insurance', 'road_tax', 'fine', 'parking', 'maintenance', 'cleaning', 'other'];
		foreach ($defaults as $name) {
			$ins = $connection->getQueryBuilder();
			$ins->insert('mc_cost_categories')
				->values([
					'name' => $ins->createNamedParameter($name),
					'is_active' => $ins->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				]);
			$ins->executeStatement();
		}
	}
}
