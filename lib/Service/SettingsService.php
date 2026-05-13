<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\AppInfo\Application;
use OCP\IConfig;

/**
 * Typed accessors for MobilityCheck global app settings stored in
 * `oc_appconfig`. Defaults are documented in §4.1 and §A4.5.
 * Fleet admins / app admins edit these via Admin → Settings.
 *
 * Settings that affect policy (directory restriction, app admins)
 * live in {@see AccessControlService}; this service is for
 * operational defaults.
 */
class SettingsService
{
	public const KEY_CURRENCY = 'currency';
	public const KEY_DEFAULT_VAT_BP = 'default_vat_bp';
	public const KEY_DEFAULT_TIMEZONE = 'default_timezone';
	public const KEY_APPROVAL_WORKFLOW = 'approval_workflow';
	/** @see self::approvalMode() — none | fleet_manager | line_manager | line_manager_then_fleet | chain */
	public const KEY_APPROVAL_MODE = 'approval_mode';
	public const KEY_APPROVAL_ACTIVE_CHAIN_ID = 'approval_active_chain_id';
	/** JSON array of chain definitions (§4.5a §E) */
	public const KEY_APPROVAL_CHAIN_DEFINITIONS = 'approval_chain_definitions';
	public const KEY_STATION_STRICT_MODE = 'station_strict_mode';
	/** When true (default), time shifts > ±15 min on approved bookings reset approval (§4.5b). */
	public const KEY_APPROVAL_RESETS_ON_TIME_CHANGE = 'approval_resets_on_time_change';
	public const KEY_FUEL_MINIMUM_CHARGEBACK_ENABLED = 'fuel_minimum_chargeback_enabled';
	public const KEY_FUEL_MINIMUM_CHARGEBACK_RATE_PER_STEP_MINOR = 'fuel_minimum_chargeback_rate_per_step_minor';
	public const KEY_GLOBAL_PHOTO_CHECKOUT = 'global_photo_evidence_required_at_checkout';
	public const KEY_GLOBAL_PHOTO_CHECKIN = 'global_photo_evidence_required_at_checkin';
	public const KEY_GLOBAL_PHOTO_MIN_COUNT = 'global_photo_evidence_minimum_count';
	public const KEY_APPROVAL_FALLBACK_NO_LM = 'approval_fallback_no_lm';
	public const KEY_APPROVAL_LINE_MANAGER_TIMEOUT_HOURS = 'approval_line_manager_timeout_hours';
	public const KEY_APPROVAL_FLEET_TIMEOUT_HOURS = 'approval_fleet_timeout_hours';
	public const KEY_LINE_MANAGER_SELF_APPROVAL_ALLOWED = 'line_manager_self_approval_allowed';
	public const KEY_BOOKING_NO_SHOW_GRACE_MINUTES = 'booking_no_show_grace_minutes';
	public const KEY_BOOKING_EXTENSION_MAX_MINUTES = 'booking_extension_max_minutes';
	public const KEY_CHECKIN_GRACE_MINUTES = 'checkin_grace_minutes';
	// §4.5 Step 10a — the spec calls this `overdue_return_grace_minutes`.
	// We keep `checkin_grace_minutes` as a legacy alias for migrations.
	public const KEY_OVERDUE_RETURN_GRACE_MINUTES = 'overdue_return_grace_minutes';
	public const KEY_LICENCE_THRESHOLDS_DAYS = 'licence_thresholds_days';
	public const KEY_LOGBOOK_GRACE_DAYS = 'logbook_grace_days';
	public const KEY_EXPORT_RETENTION_DAYS = 'export_retention_days';
	public const KEY_EXPORT_ASYNC_THRESHOLD_ROWS = 'export_async_threshold_rows';
	public const KEY_MAX_UPLOAD_BYTES = 'max_upload_bytes';
	public const KEY_REIMBURSEMENT_ENABLED = 'reimbursement_enabled';
	public const KEY_LOGBOOK_ENABLED = 'logbook_enabled';
	public const KEY_DEFAULT_JURISDICTION = 'default_jurisdiction';
	/** When true, booking-related notification emails include a PUBLISH .ics attachment (RFC 5545). */
	public const KEY_BOOKING_EMAIL_ATTACH_ICS = 'booking_email_attach_ics';
	/** §A5.4 — master switch; default off so upgrades stay predictable. */
	public const KEY_INTELLIGENT_ALLOCATION_ENABLED = 'intelligent_allocation_enabled';
	/** `suggest_only` | `auto_commit` */
	public const KEY_INTELLIGENT_ALLOCATION_MODE = 'intelligent_allocation_mode';
	/** `flag_for_manual` | `auto_cancel` when no replacement exists */
	public const KEY_INTELLIGENT_ALLOCATION_ON_NO_REPLACEMENT = 'intelligent_allocation_on_no_replacement';
	/** `driver_may_choose` | `auto_assign_no_choice` | `manager_assigns` */
	public const KEY_VEHICLE_CHOICE_POLICY = 'vehicle_choice_policy';
	/** Optional JSON `{ "wLease":1, "wAge":1, "wUtil":1, "wOps":1 }` — integers 0–10, default 1 each. */
	public const KEY_INTELLIGENT_ALLOCATION_WEIGHTS_JSON = 'intelligent_allocation_weights_json';
	public const KEY_MIN_REMAINING_LEASE_DAYS_FOR_BOOKING = 'min_remaining_lease_days_for_booking';
	public const KEY_MIN_REMAINING_LEASE_KM_PERCENT = 'min_remaining_lease_km_percent';

	public function __construct(private IConfig $config) {}

	/** @return array<string,mixed> */
	public function all(): array
	{
		return [
			'currency' => $this->currency(),
			'defaultVatBp' => $this->defaultVatBp(),
			'defaultTimezone' => $this->defaultTimezone(),
			'approvalWorkflow' => $this->approvalWorkflowEnabled(),
			'approvalMode' => $this->approvalMode(),
			'approvalFallbackNoLm' => $this->approvalFallbackWhenNoLineManager(),
			'approvalLineManagerTimeoutHours' => $this->approvalLineManagerTimeoutHours(),
			'approvalFleetTimeoutHours' => $this->approvalFleetTimeoutHours(),
			'lineManagerSelfApprovalAllowed' => $this->lineManagerSelfApprovalAllowed(),
			'bookingNoShowGraceMinutes' => $this->bookingNoShowGraceMinutes(),
			'bookingExtensionMaxMinutes' => $this->bookingExtensionMaxMinutes(),
			'checkinGraceMinutes' => $this->checkinGraceMinutes(),
			'overdueReturnGraceMinutes' => $this->overdueReturnGraceMinutes(),
			'licenceThresholdsDays' => $this->licenceThresholdsDays(),
			'logbookGraceDays' => $this->logbookGraceDays(),
			'exportRetentionDays' => $this->exportRetentionDays(),
			'exportAsyncThresholdRows' => $this->exportAsyncThresholdRows(),
			'maxUploadBytes' => $this->maxUploadBytes(),
			'reimbursementEnabled' => $this->reimbursementEnabled(),
			'logbookEnabled' => $this->logbookEnabled(),
			'defaultJurisdiction' => $this->defaultJurisdiction(),
			'bookingEmailAttachIcs' => $this->bookingEmailAttachIcs(),
			'intelligentAllocationEnabled' => $this->intelligentAllocationEnabled(),
			'intelligentAllocationMode' => $this->intelligentAllocationMode(),
			'intelligentAllocationOnNoReplacement' => $this->intelligentAllocationOnNoReplacement(),
			'vehicleChoicePolicy' => $this->vehicleChoicePolicy(),
			'intelligentAllocationWeights' => $this->intelligentAllocationWeights(),
			'minRemainingLeaseDaysForBooking' => $this->minRemainingLeaseDaysForBooking(),
			'minRemainingLeaseKmPercent' => $this->minRemainingLeaseKmPercent(),
			'approvalActiveChainId' => $this->approvalActiveChainId(),
			'approvalChainDefinitions' => $this->approvalChainDefinitions(),
			'stationStrictMode' => $this->stationStrictMode(),
			'approvalResetsOnTimeChange' => $this->approvalResetsOnTimeChange(),
			'fuelMinimumChargebackEnabled' => $this->fuelMinimumChargebackEnabled(),
			'fuelMinimumChargebackRatePerStepMinor' => $this->fuelMinimumChargebackRatePerStepMinor(),
			'globalPhotoEvidenceCheckout' => $this->globalPhotoEvidenceRequiredAtCheckout(),
			'globalPhotoEvidenceCheckin' => $this->globalPhotoEvidenceRequiredAtCheckin(),
			'globalPhotoEvidenceMinimumCount' => $this->globalPhotoEvidenceMinimumCount(),
		];
	}

	public function currency(): string
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_CURRENCY, 'EUR');
	}

	public function defaultVatBp(): int
	{
		return (int)$this->config->getAppValue(Application::APP_ID, self::KEY_DEFAULT_VAT_BP, '1900');
	}

	public function defaultTimezone(): string
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_DEFAULT_TIMEZONE, 'Europe/Berlin');
	}

	public function approvalWorkflowEnabled(): bool
	{
		return $this->approvalMode() !== 'none';
	}

	public function approvalMode(): string
	{
		$m = trim($this->config->getAppValue(Application::APP_ID, self::KEY_APPROVAL_MODE, ''));
		if ($m === 'fleet_manager' || $m === 'line_manager' || $m === 'line_manager_then_fleet' || $m === 'none' || $m === 'chain') {
			return $m;
		}
		return $this->config->getAppValue(Application::APP_ID, self::KEY_APPROVAL_WORKFLOW, '0') === '1'
			? 'fleet_manager'
			: 'none';
	}

	public function approvalActiveChainId(): string
	{
		return trim($this->config->getAppValue(Application::APP_ID, self::KEY_APPROVAL_ACTIVE_CHAIN_ID, ''));
	}

	/** @return list<array<string,mixed>> */
	public function approvalChainDefinitions(): array
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::KEY_APPROVAL_CHAIN_DEFINITIONS, '');
		if ($raw === '') {
			return [];
		}
		try {
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
			return is_array($decoded) ? $decoded : [];
		} catch (\Throwable) {
			return [];
		}
	}

	public function stationStrictMode(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_STATION_STRICT_MODE, '0') === '1';
	}

	public function approvalResetsOnTimeChange(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_APPROVAL_RESETS_ON_TIME_CHANGE, '1') !== '0';
	}

	public function fuelMinimumChargebackEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_FUEL_MINIMUM_CHARGEBACK_ENABLED, '0') === '1';
	}

	public function fuelMinimumChargebackRatePerStepMinor(): int
	{
		return max(0, (int)$this->config->getAppValue(Application::APP_ID, self::KEY_FUEL_MINIMUM_CHARGEBACK_RATE_PER_STEP_MINOR, '0'));
	}

	public function globalPhotoEvidenceRequiredAtCheckout(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_GLOBAL_PHOTO_CHECKOUT, '0') === '1';
	}

	public function globalPhotoEvidenceRequiredAtCheckin(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_GLOBAL_PHOTO_CHECKIN, '0') === '1';
	}

	public function globalPhotoEvidenceMinimumCount(): int
	{
		return max(1, min(20, (int)$this->config->getAppValue(Application::APP_ID, self::KEY_GLOBAL_PHOTO_MIN_COUNT, '4')));
	}

	public function approvalFallbackWhenNoLineManager(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_APPROVAL_FALLBACK_NO_LM, '1') !== '0';
	}

	/**
	 * Hours after which a `pending_line_manager` booking is escalated to
	 * the fleet manager pool (§4.5a — escalation invariant). 0 disables
	 * escalation; minimum sensible value is 1.
	 */
	public function approvalLineManagerTimeoutHours(): int
	{
		$v = (int)$this->config->getAppValue(Application::APP_ID, self::KEY_APPROVAL_LINE_MANAGER_TIMEOUT_HOURS, '24');
		return $v >= 0 ? $v : 24;
	}

	/**
	 * Hours after which a `pending_fleet` booking is highlighted as
	 * "escalated" so a manager picks it up (§4.5a). 0 disables. The
	 * booking does not move state — it only gains the `is_escalated`
	 * flag plus a notification fan-out.
	 */
	public function approvalFleetTimeoutHours(): int
	{
		$v = (int)$this->config->getAppValue(Application::APP_ID, self::KEY_APPROVAL_FLEET_TIMEOUT_HOURS, '48');
		return $v >= 0 ? $v : 48;
	}

	/**
	 * §4.5a §D.4 — When a line manager submits a booking and is also the
	 * driver, the spec defaults to **forbid** self-approval. An operator
	 * can flip this on for small teams where the LM doubles as a driver.
	 */
	public function lineManagerSelfApprovalAllowed(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_LINE_MANAGER_SELF_APPROVAL_ALLOWED, '0') === '1';
	}

	public function bookingNoShowGraceMinutes(): int
	{
		$v = (int)$this->config->getAppValue(Application::APP_ID, self::KEY_BOOKING_NO_SHOW_GRACE_MINUTES, '60');
		return max(0, $v);
	}

	public function bookingExtensionMaxMinutes(): int
	{
		$v = (int)$this->config->getAppValue(Application::APP_ID, self::KEY_BOOKING_EXTENSION_MAX_MINUTES, '120');
		return max(0, $v);
	}

	public function checkinGraceMinutes(): int
	{
		$v = (int)$this->config->getAppValue(Application::APP_ID, self::KEY_CHECKIN_GRACE_MINUTES, '120');
		return $v >= 0 ? $v : 120;
	}

	/**
	 * §4.5 Step 10a — minutes after `end_datetime` before an active
	 * booking is flagged as overdue (alerts only, no auto-completion).
	 * Spec-canonical name. New code should call this; legacy callers
	 * may still use {@see self::checkinGraceMinutes()}.
	 */
	public function overdueReturnGraceMinutes(): int
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::KEY_OVERDUE_RETURN_GRACE_MINUTES, '');
		if ($raw !== '') {
			$v = (int)$raw;
			return $v >= 0 ? $v : 120;
		}
		return $this->checkinGraceMinutes();
	}

	/** @return list<int> */
	public function licenceThresholdsDays(): array
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::KEY_LICENCE_THRESHOLDS_DAYS, '90,60,30,14,7');
		$out = [];
		foreach (explode(',', $raw) as $segment) {
			$n = (int)trim($segment);
			if ($n > 0) {
				$out[] = $n;
			}
		}
		if ($out === []) {
			return [90, 60, 30, 14, 7];
		}
		rsort($out);
		return $out;
	}

	public function logbookGraceDays(): int
	{
		$v = (int)$this->config->getAppValue(Application::APP_ID, self::KEY_LOGBOOK_GRACE_DAYS, '7');
		return $v >= 0 ? $v : 7;
	}

	public function exportRetentionDays(): int
	{
		$v = (int)$this->config->getAppValue(Application::APP_ID, self::KEY_EXPORT_RETENTION_DAYS, '30');
		return $v > 0 ? $v : 30;
	}

	public function exportAsyncThresholdRows(): int
	{
		$v = (int)$this->config->getAppValue(Application::APP_ID, self::KEY_EXPORT_ASYNC_THRESHOLD_ROWS, '10000');
		return $v > 0 ? $v : 10000;
	}

	public function maxUploadBytes(): int
	{
		$v = (int)$this->config->getAppValue(Application::APP_ID, self::KEY_MAX_UPLOAD_BYTES, (string)(10 * 1024 * 1024));
		return $v > 0 ? $v : (10 * 1024 * 1024);
	}

	public function reimbursementEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_REIMBURSEMENT_ENABLED, '1') !== '0';
	}

	public function logbookEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_LOGBOOK_ENABLED, '1') !== '0';
	}

	public function defaultJurisdiction(): string
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_DEFAULT_JURISDICTION, 'DE');
	}

	public function bookingEmailAttachIcs(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_BOOKING_EMAIL_ATTACH_ICS, '1') !== '0';
	}

	public function intelligentAllocationEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_INTELLIGENT_ALLOCATION_ENABLED, '0') === '1';
	}

	/** @return 'suggest_only'|'auto_commit' */
	public function intelligentAllocationMode(): string
	{
		$m = trim($this->config->getAppValue(Application::APP_ID, self::KEY_INTELLIGENT_ALLOCATION_MODE, 'suggest_only'));
		return in_array($m, ['suggest_only', 'auto_commit'], true) ? $m : 'suggest_only';
	}

	/** @return 'flag_for_manual'|'auto_cancel' */
	public function intelligentAllocationOnNoReplacement(): string
	{
		$m = trim($this->config->getAppValue(Application::APP_ID, self::KEY_INTELLIGENT_ALLOCATION_ON_NO_REPLACEMENT, 'flag_for_manual'));
		return in_array($m, ['flag_for_manual', 'auto_cancel'], true) ? $m : 'flag_for_manual';
	}

	/**
	 * @return 'driver_may_choose'|'auto_assign_no_choice'|'manager_assigns'
	 */
	public function vehicleChoicePolicy(): string
	{
		$m = trim($this->config->getAppValue(Application::APP_ID, self::KEY_VEHICLE_CHOICE_POLICY, 'driver_may_choose'));
		return in_array($m, ['driver_may_choose', 'auto_assign_no_choice', 'manager_assigns'], true)
			? $m
			: 'driver_may_choose';
	}

	/**
	 * @return array{wLease:int,wAge:int,wUtil:int,wOps:int}
	 */
	public function intelligentAllocationWeights(): array
	{
		$defaults = ['wLease' => 1, 'wAge' => 1, 'wUtil' => 1, 'wOps' => 1];
		$raw = $this->config->getAppValue(Application::APP_ID, self::KEY_INTELLIGENT_ALLOCATION_WEIGHTS_JSON, '');
		if ($raw === '') {
			return $defaults;
		}
		try {
			$j = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
			if (!is_array($j)) {
				return $defaults;
			}
			$out = $defaults;
			foreach (['wLease', 'wAge', 'wUtil', 'wOps'] as $k) {
				if (isset($j[$k])) {
					$out[$k] = max(0, min(10, (int)$j[$k]));
				}
			}
			return $out;
		} catch (\Throwable) {
			return $defaults;
		}
	}

	public function minRemainingLeaseDaysForBooking(): int
	{
		return max(0, (int)$this->config->getAppValue(Application::APP_ID, self::KEY_MIN_REMAINING_LEASE_DAYS_FOR_BOOKING, '0'));
	}

	public function minRemainingLeaseKmPercent(): int
	{
		return max(0, min(100, (int)$this->config->getAppValue(Application::APP_ID, self::KEY_MIN_REMAINING_LEASE_KM_PERCENT, '0')));
	}

	/** @param array<string,mixed> $payload */
	public function save(array $payload): array
	{
		$this->setIfPresent($payload, self::KEY_CURRENCY, fn ($v) => is_string($v) && preg_match('/^[A-Z]{3}$/', $v) ? $v : 'EUR');
		$this->setIfPresent($payload, self::KEY_DEFAULT_VAT_BP, fn ($v) => (string)max(0, min(9999, (int)$v)));
		$this->setIfPresent($payload, self::KEY_DEFAULT_TIMEZONE, fn ($v) => in_array($v, \DateTimeZone::listIdentifiers(), true) ? $v : 'Europe/Berlin');
		$this->setIfPresent($payload, self::KEY_APPROVAL_WORKFLOW, fn ($v) => $v ? '1' : '0');
		$this->setIfPresent($payload, self::KEY_APPROVAL_MODE, function ($v) {
			$s = is_string($v) ? trim($v) : '';
			return in_array($s, ['none', 'fleet_manager', 'line_manager', 'line_manager_then_fleet', 'chain'], true) ? $s : 'none';
		});
		$this->setIfPresent($payload, self::KEY_APPROVAL_ACTIVE_CHAIN_ID, fn ($v) => is_string($v) ? trim($v) : '');
		$this->setIfPresent($payload, self::KEY_APPROVAL_CHAIN_DEFINITIONS, function ($v) {
			if (is_string($v)) {
				json_decode($v, true, 512, JSON_THROW_ON_ERROR);
				return $v;
			}
			if (is_array($v)) {
				return json_encode($v, JSON_THROW_ON_ERROR);
			}
			return '[]';
		});
		$this->setIfPresent($payload, self::KEY_STATION_STRICT_MODE, fn ($v) => $v ? '1' : '0');
		$this->setIfPresent($payload, self::KEY_APPROVAL_RESETS_ON_TIME_CHANGE, fn ($v) => $v ? '1' : '0');
		$this->setIfPresent($payload, self::KEY_FUEL_MINIMUM_CHARGEBACK_ENABLED, fn ($v) => $v ? '1' : '0');
		$this->setIfPresent($payload, self::KEY_FUEL_MINIMUM_CHARGEBACK_RATE_PER_STEP_MINOR, fn ($v) => (string)max(0, (int)$v));
		$this->setIfPresent($payload, self::KEY_GLOBAL_PHOTO_CHECKOUT, fn ($v) => $v ? '1' : '0');
		$this->setIfPresent($payload, self::KEY_GLOBAL_PHOTO_CHECKIN, fn ($v) => $v ? '1' : '0');
		$this->setIfPresent($payload, self::KEY_GLOBAL_PHOTO_MIN_COUNT, fn ($v) => (string)max(1, min(20, (int)$v)));
		$this->setIfPresent($payload, self::KEY_APPROVAL_FALLBACK_NO_LM, fn ($v) => $v ? '1' : '0');
		$this->setIfPresent($payload, self::KEY_APPROVAL_LINE_MANAGER_TIMEOUT_HOURS, fn ($v) => (string)max(0, (int)$v));
		$this->setIfPresent($payload, self::KEY_APPROVAL_FLEET_TIMEOUT_HOURS, fn ($v) => (string)max(0, (int)$v));
		$this->setIfPresent($payload, self::KEY_LINE_MANAGER_SELF_APPROVAL_ALLOWED, fn ($v) => $v ? '1' : '0');
		$this->setIfPresent($payload, self::KEY_BOOKING_NO_SHOW_GRACE_MINUTES, fn ($v) => (string)max(0, (int)$v));
		$this->setIfPresent($payload, self::KEY_BOOKING_EXTENSION_MAX_MINUTES, fn ($v) => (string)max(0, (int)$v));
		$this->setIfPresent($payload, self::KEY_CHECKIN_GRACE_MINUTES, fn ($v) => (string)max(0, (int)$v));
		$this->setIfPresent($payload, self::KEY_OVERDUE_RETURN_GRACE_MINUTES, fn ($v) => (string)max(0, (int)$v));
		$this->setIfPresent($payload, self::KEY_LICENCE_THRESHOLDS_DAYS, function ($v) {
			$list = is_array($v) ? $v : explode(',', (string)$v);
			$out = [];
			foreach ($list as $n) {
				$n = (int)$n;
				if ($n > 0) {
					$out[] = $n;
				}
			}
			rsort($out);
			return implode(',', $out !== [] ? $out : [90, 60, 30, 14, 7]);
		});
		$this->setIfPresent($payload, self::KEY_LOGBOOK_GRACE_DAYS, fn ($v) => (string)max(0, (int)$v));
		$this->setIfPresent($payload, self::KEY_EXPORT_RETENTION_DAYS, fn ($v) => (string)max(1, (int)$v));
		$this->setIfPresent($payload, self::KEY_EXPORT_ASYNC_THRESHOLD_ROWS, fn ($v) => (string)max(1, (int)$v));
		$this->setIfPresent($payload, self::KEY_MAX_UPLOAD_BYTES, fn ($v) => (string)max(1024, (int)$v));
		$this->setIfPresent($payload, self::KEY_REIMBURSEMENT_ENABLED, fn ($v) => $v ? '1' : '0');
		$this->setIfPresent($payload, self::KEY_LOGBOOK_ENABLED, fn ($v) => $v ? '1' : '0');
		$this->setIfPresent($payload, self::KEY_DEFAULT_JURISDICTION, fn ($v) => is_string($v) && preg_match('/^[A-Z]{2,3}$/', $v) ? $v : 'DE');
		$this->setIfPresent($payload, self::KEY_BOOKING_EMAIL_ATTACH_ICS, fn ($v) => $v ? '1' : '0');
		$this->setIfPresent($payload, self::KEY_INTELLIGENT_ALLOCATION_ENABLED, fn ($v) => $v ? '1' : '0');
		$this->setIfPresent($payload, self::KEY_INTELLIGENT_ALLOCATION_MODE, function ($v) {
			$s = is_string($v) ? trim($v) : '';
			return in_array($s, ['suggest_only', 'auto_commit'], true) ? $s : 'suggest_only';
		});
		$this->setIfPresent($payload, self::KEY_INTELLIGENT_ALLOCATION_ON_NO_REPLACEMENT, function ($v) {
			$s = is_string($v) ? trim($v) : '';
			return in_array($s, ['flag_for_manual', 'auto_cancel'], true) ? $s : 'flag_for_manual';
		});
		$this->setIfPresent($payload, self::KEY_VEHICLE_CHOICE_POLICY, function ($v) {
			$s = is_string($v) ? trim($v) : '';
			return in_array($s, ['driver_may_choose', 'auto_assign_no_choice', 'manager_assigns'], true)
				? $s
				: 'driver_may_choose';
		});
		$this->setIfPresent($payload, self::KEY_INTELLIGENT_ALLOCATION_WEIGHTS_JSON, function ($v) {
			if (is_string($v)) {
				json_decode($v, true, 8, JSON_THROW_ON_ERROR);
				return $v;
			}
			if (is_array($v)) {
				return json_encode($v, JSON_THROW_ON_ERROR);
			}
			return '{}';
		});
		$this->setIfPresent($payload, self::KEY_MIN_REMAINING_LEASE_DAYS_FOR_BOOKING, fn ($v) => (string)max(0, (int)$v));
		$this->setIfPresent($payload, self::KEY_MIN_REMAINING_LEASE_KM_PERCENT, fn ($v) => (string)max(0, min(100, (int)$v)));
		$this->config->setAppValue(Application::APP_ID, self::KEY_APPROVAL_WORKFLOW, $this->approvalMode() !== 'none' ? '1' : '0');
		return $this->all();
	}

	private function setIfPresent(array $payload, string $key, callable $normalise): void
	{
		$camel = preg_replace_callback('/_([a-z])/', fn ($m) => strtoupper($m[1]), $key);
		if (!array_key_exists($key, $payload) && !array_key_exists($camel, $payload)) {
			return;
		}
		$raw = array_key_exists($key, $payload) ? $payload[$key] : $payload[$camel];
		$value = (string)$normalise($raw);
		$this->config->setAppValue(Application::APP_ID, $key, $value);
	}
}
