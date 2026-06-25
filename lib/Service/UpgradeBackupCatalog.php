<?php

declare(strict_types=1);

/**
 * Tables and app-data paths included in pre-update upgrade backups.
 *
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Regenerate via:
 *     php scripts/sync-upgrade-backup.php --app=mobilitycheck
 */
namespace OCA\MobilityCheck\Service;

final class UpgradeBackupCatalog
{
	public const APP_ID = 'mobilitycheck';

	public const FORMAT_VERSION = 1;

	public const APPDATA_ROOT = 'upgrade-backups';

	/** @var list<string> App-data folder names (under appdata_<instance>/mobilitycheck/) to include in snapshots. */
	public const APPDATA_FOLDERS = [

	];

	public const CONFIG_MAX_SNAPSHOTS = 'upgrade_backup_max_snapshots';

	public const CONFIG_LAST_SNAPSHOT_ID = 'upgrade_backup_last_snapshot_id';

	public const DEFAULT_MAX_SNAPSHOTS = 5;

	public const MAX_SNAPSHOTS_LIMIT = 20;

	/** @var list<string> */
	public const BACKUP_TABLES = [
		'mc_audit_log',
		'mc_booking_approvals',
		'mc_booking_reassign_sug',
		'mc_bookings',
		'mc_checkout_logs',
		'mc_cost_categories',
		'mc_cost_centres',
		'mc_cost_entries',
		'mc_damage_photos',
		'mc_damage_reports',
		'mc_driver_profiles',
		'mc_export_downloads',
		'mc_group_roles',
		'mc_instruction_records',
		'mc_licence_verifications',
		'mc_line_manager_assignments',
		'mc_logbook_entries',
		'mc_maintenance_schedules',
		'mc_notification_log',
		'mc_odometer_readings',
		'mc_private_vehicles',
		'mc_rate_limits',
		'mc_reim_rate_cfg',
		'mc_reimbursement_claims',
		'mc_relocation_tasks',
		'mc_repair_jobs',
		'mc_search_profiles',
		'mc_stations',
		'mc_user_preferences',
		'mc_user_roles',
		'mc_vehicle_assignments',
		'mc_vehicle_features',
		'mc_vehicle_group_members',
		'mc_vehicles',
	];

	/** @var list<string> */
	public const RESTORE_TABLE_ORDER = [
		'mc_audit_log',
		'mc_booking_approvals',
		'mc_booking_reassign_sug',
		'mc_bookings',
		'mc_checkout_logs',
		'mc_cost_categories',
		'mc_cost_centres',
		'mc_cost_entries',
		'mc_damage_photos',
		'mc_damage_reports',
		'mc_driver_profiles',
		'mc_export_downloads',
		'mc_group_roles',
		'mc_instruction_records',
		'mc_licence_verifications',
		'mc_line_manager_assignments',
		'mc_logbook_entries',
		'mc_maintenance_schedules',
		'mc_notification_log',
		'mc_odometer_readings',
		'mc_private_vehicles',
		'mc_rate_limits',
		'mc_reim_rate_cfg',
		'mc_reimbursement_claims',
		'mc_relocation_tasks',
		'mc_repair_jobs',
		'mc_search_profiles',
		'mc_stations',
		'mc_user_preferences',
		'mc_user_roles',
		'mc_vehicle_assignments',
		'mc_vehicle_features',
		'mc_vehicle_group_members',
		'mc_vehicles',
	];

	public static function isBackupTable(string $table): bool
	{
		return in_array($table, self::BACKUP_TABLES, true);
	}

	public static function clampMaxSnapshots(int $requested): int
	{
		return max(1, min(self::MAX_SNAPSHOTS_LIMIT, $requested));
	}

	/**
	 * @return list<string>
	 */
	public static function existingBackupTables(callable $tableExists): array
	{
		$existing = [];
		foreach (self::BACKUP_TABLES as $table) {
			if ($tableExists($table)) {
				$existing[] = $table;
			}
		}

		return $existing;
	}

	/**
	 * @return list<string>
	 */
	public static function sortedRestoreTables(array $presentTables): array
	{
		$present = array_fill_keys($presentTables, true);
		$ordered = [];
		foreach (self::RESTORE_TABLE_ORDER as $table) {
			if (isset($present[$table])) {
				$ordered[] = $table;
			}
		}

		foreach ($presentTables as $table) {
			if (!in_array($table, $ordered, true)) {
				$ordered[] = $table;
			}
		}

		return $ordered;
	}
}
