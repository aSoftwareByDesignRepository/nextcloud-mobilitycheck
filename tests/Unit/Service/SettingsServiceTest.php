<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Service;

use OCA\MobilityCheck\AppInfo\Application;
use OCA\MobilityCheck\Service\CurrencyCatalog;
use OCA\MobilityCheck\Service\SettingsService;
use OCA\MobilityCheck\Service\TimezoneCatalog;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * SettingsService — verifies that every getter has a sane default
 * and that mis-configured values (negative grace minutes, garbage
 * threshold lists, blank currency) never propagate into the
 * application as `null` or undefined behaviour.
 */
final class SettingsServiceTest extends TestCase
{
	private function service(array $values = []): SettingsService
	{
		$cfg = $this->createMock(IConfig::class);
		$cfg->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default) => $values[$key] ?? $default
		);
		$this->assertSame('mobilitycheck', Application::APP_ID);
		return new SettingsService($cfg);
	}

	public function testDefaults(): void
	{
		$s = $this->service();
		$this->assertSame('EUR', $s->currency());
		$this->assertSame(1900, $s->defaultVatBp());
		$this->assertSame('Europe/Berlin', $s->defaultTimezone());
		$this->assertFalse($s->approvalWorkflowEnabled());
		$this->assertSame(120, $s->checkinGraceMinutes());
		$this->assertSame([90, 60, 30, 14, 7], $s->licenceThresholdsDays());
		$this->assertTrue($s->bookingEmailAttachIcs());
	}

	public function testNegativeGraceFallsBack(): void
	{
		$s = $this->service([SettingsService::KEY_CHECKIN_GRACE_MINUTES => '-30']);
		$this->assertSame(120, $s->checkinGraceMinutes());
	}

	public function testCustomThresholds(): void
	{
		$s = $this->service([SettingsService::KEY_LICENCE_THRESHOLDS_DAYS => '45, 30, 7']);
		$this->assertSame([45, 30, 7], $s->licenceThresholdsDays());
	}

	public function testGarbageThresholdsFallback(): void
	{
		$s = $this->service([SettingsService::KEY_LICENCE_THRESHOLDS_DAYS => 'foo, bar']);
		$this->assertSame([90, 60, 30, 14, 7], $s->licenceThresholdsDays());
	}

	public function testApprovalWorkflowEnabled(): void
	{
		$s = $this->service([SettingsService::KEY_APPROVAL_WORKFLOW => '1']);
		$this->assertTrue($s->approvalWorkflowEnabled());
	}

	public function testApprovalModeFromKey(): void
	{
		$s = $this->service([SettingsService::KEY_APPROVAL_MODE => 'line_manager_then_fleet']);
		$this->assertSame('line_manager_then_fleet', $s->approvalMode());
		$this->assertTrue($s->approvalWorkflowEnabled());
	}

	public function testApprovalModeLegacyFallback(): void
	{
		$s = $this->service([SettingsService::KEY_APPROVAL_WORKFLOW => '1']);
		$this->assertSame('fleet_manager', $s->approvalMode());
	}

	public function testApprovalTimeoutsDefault(): void
	{
		$s = $this->service();
		$this->assertSame(24, $s->approvalLineManagerTimeoutHours());
		$this->assertSame(48, $s->approvalFleetTimeoutHours());
	}

	public function testApprovalTimeoutsConfigurable(): void
	{
		$s = $this->service([
			SettingsService::KEY_APPROVAL_LINE_MANAGER_TIMEOUT_HOURS => '6',
			SettingsService::KEY_APPROVAL_FLEET_TIMEOUT_HOURS => '12',
		]);
		$this->assertSame(6, $s->approvalLineManagerTimeoutHours());
		$this->assertSame(12, $s->approvalFleetTimeoutHours());
	}

	public function testApprovalTimeoutsRejectNegative(): void
	{
		$s = $this->service([
			SettingsService::KEY_APPROVAL_LINE_MANAGER_TIMEOUT_HOURS => '-1',
			SettingsService::KEY_APPROVAL_FLEET_TIMEOUT_HOURS => '-100',
		]);
		// negative values fall back to the documented defaults (never propagate as -1)
		$this->assertSame(24, $s->approvalLineManagerTimeoutHours());
		$this->assertSame(48, $s->approvalFleetTimeoutHours());
	}

	public function testLineManagerSelfApprovalDefaultOff(): void
	{
		$s = $this->service();
		$this->assertFalse($s->lineManagerSelfApprovalAllowed());
	}

	public function testLineManagerSelfApprovalCanBeEnabled(): void
	{
		$s = $this->service([SettingsService::KEY_LINE_MANAGER_SELF_APPROVAL_ALLOWED => '1']);
		$this->assertTrue($s->lineManagerSelfApprovalAllowed());
	}

	public function testBookingExtensionMaxMinutes(): void
	{
		$s = $this->service([SettingsService::KEY_BOOKING_EXTENSION_MAX_MINUTES => '180']);
		$this->assertSame(180, $s->bookingExtensionMaxMinutes());
		$s2 = $this->service([SettingsService::KEY_BOOKING_EXTENSION_MAX_MINUTES => '-5']);
		$this->assertSame(0, $s2->bookingExtensionMaxMinutes());
	}

	public function testBookingNoShowGraceMinutes(): void
	{
		$s = $this->service();
		$this->assertSame(60, $s->bookingNoShowGraceMinutes());
		$s2 = $this->service([SettingsService::KEY_BOOKING_NO_SHOW_GRACE_MINUTES => '0']);
		$this->assertSame(0, $s2->bookingNoShowGraceMinutes());
	}

	public function testOverdueReturnGraceFallsBackToLegacy(): void
	{
		$s = $this->service([SettingsService::KEY_CHECKIN_GRACE_MINUTES => '90']);
		$this->assertSame(90, $s->overdueReturnGraceMinutes(),
			'When the spec-canonical key is unset, the legacy checkin grace must apply.');
	}

	public function testOverdueReturnGraceCanonicalKeyWins(): void
	{
		$s = $this->service([
			SettingsService::KEY_OVERDUE_RETURN_GRACE_MINUTES => '15',
			SettingsService::KEY_CHECKIN_GRACE_MINUTES => '90',
		]);
		$this->assertSame(15, $s->overdueReturnGraceMinutes(),
			'Spec-canonical key must take precedence over the legacy alias.');
	}

	public function testOverdueReturnGraceRejectsNegative(): void
	{
		$s = $this->service([SettingsService::KEY_OVERDUE_RETURN_GRACE_MINUTES => '-5']);
		$this->assertSame(120, $s->overdueReturnGraceMinutes());
	}

	public function testBookingEmailAttachIcsCanBeDisabled(): void
	{
		$s = $this->service([SettingsService::KEY_BOOKING_EMAIL_ATTACH_ICS => '0']);
		$this->assertFalse($s->bookingEmailAttachIcs());
	}

	public function testCurrencyDecimalsFollowsCatalog(): void
	{
		$s = $this->service([SettingsService::KEY_CURRENCY => 'JPY']);
		$this->assertSame(0, $s->currencyDecimals());
		$s2 = $this->service([SettingsService::KEY_CURRENCY => 'RUB']);
		$this->assertSame(2, $s2->currencyDecimals());
	}

	public function testSaveRejectsUnsupportedCurrency(): void
	{
		$s = $this->service();
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('CURRENCY_NOT_SUPPORTED');
		$s->save(['currency' => 'XXX']);
	}

	public function testSaveRejectsInvalidTimezone(): void
	{
		$s = $this->service();
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_TIMEZONE');
		$s->save(['defaultTimezone' => 'Not/A/Zone']);
	}

	public function testSaveNormalizesCurrencyAndTimezone(): void
	{
		$cfg = $this->createMock(IConfig::class);
		$stored = [];
		$cfg->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default) => $stored[$key] ?? $default
		);
		$cfg->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): void {
				$stored[$key] = $value;
			}
		);
		$s = new SettingsService($cfg, new TimezoneCatalog(), new CurrencyCatalog());
		$s->save(['currency' => 'rub', 'defaultTimezone' => '  Asia/Tashkent  ']);
		$this->assertSame('RUB', $stored[SettingsService::KEY_CURRENCY] ?? null);
		$this->assertSame('Asia/Tashkent', $stored[SettingsService::KEY_DEFAULT_TIMEZONE] ?? null);
	}

	public function testAllReturnsKnownKeys(): void
	{
		$s = $this->service();
		$out = $s->all();
		foreach ([
			'currency', 'currencyDecimals', 'defaultTimezone', 'defaultVatBp', 'approvalMode',
			'approvalLineManagerTimeoutHours', 'approvalFleetTimeoutHours',
			'lineManagerSelfApprovalAllowed', 'bookingNoShowGraceMinutes',
			'bookingExtensionMaxMinutes', 'overdueReturnGraceMinutes',
			'bookingEmailAttachIcs',
		] as $expected) {
			$this->assertArrayHasKey($expected, $out, 'Settings::all() must expose ' . $expected);
		}
	}
}
