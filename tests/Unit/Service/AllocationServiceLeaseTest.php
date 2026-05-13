<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Service;

use OCA\MobilityCheck\Service\AllocationService;
use OCA\MobilityCheck\Service\SettingsService;
use OCA\MobilityCheck\Service\VehicleAssignmentService;
use OCA\MobilityCheck\Service\VehicleService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * §A5.4.5 — Lease km headroom gate for new bookings (linear curve vs odometer delta).
 */
final class AllocationServiceLeaseTest extends TestCase
{
	private function service(SettingsService $settings): AllocationService
	{
		$db = $this->createMock(IDBConnection::class);
		$vehicles = $this->createMock(VehicleService::class);
		$assignments = $this->createMock(VehicleAssignmentService::class);
		return new AllocationService($db, $vehicles, $assignments, $settings);
	}

	private function settings(array $vals): SettingsService
	{
		$cfg = $this->createMock(\OCP\IConfig::class);
		$cfg->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default) => $vals[$key] ?? $default
		);
		return new SettingsService($cfg);
	}

	public function testMinLeaseKmPercentDisabledAlwaysPasses(): void
	{
		$s = $this->settings([SettingsService::KEY_MIN_REMAINING_LEASE_KM_PERCENT => '0']);
		$a = $this->service($s);
		$vehicle = [
			'lease_start_date' => '2024-01-01',
			'lease_end_date' => '2025-12-31',
			'lease_included_km' => 10000,
			'odometer_km' => 999999,
			'created_at' => '2023-01-01 00:00:00',
		];
		$this->assertTrue($a->passesMinRemainingLeaseKmBudget($vehicle, '2024-06-01T12:00:00Z'));
	}

	public function testPartialLeaseDataPasses(): void
	{
		$s = $this->settings([SettingsService::KEY_MIN_REMAINING_LEASE_KM_PERCENT => '50']);
		$a = $this->service($s);
		$this->assertTrue($a->passesMinRemainingLeaseKmBudget(['odometer_km' => 0], '2024-06-01T12:00:00Z'));
	}

	public function testHighOdometerFailsThreshold(): void
	{
		$s = $this->settings([SettingsService::KEY_MIN_REMAINING_LEASE_KM_PERCENT => '15']);
		$a = $this->service($s);
		$vehicle = [
			'lease_start_date' => '2024-01-01',
			'lease_end_date' => '2025-12-31',
			'lease_included_km' => 73000,
			'odometer_km' => 70000,
			'created_at' => '2023-01-01 00:00:00',
		];
		$this->assertFalse($a->passesMinRemainingLeaseKmBudget($vehicle, '2024-07-01T08:00:00Z'));
	}

	public function testOnCurvePasses(): void
	{
		$s = $this->settings([SettingsService::KEY_MIN_REMAINING_LEASE_KM_PERCENT => '15']);
		$a = $this->service($s);
		$vehicle = [
			'lease_start_date' => '2024-01-01',
			'lease_end_date' => '2025-12-31',
			'lease_included_km' => 73000,
			'odometer_km' => 18200,
			'created_at' => '2023-01-01 00:00:00',
		];
		$this->assertTrue($a->passesMinRemainingLeaseKmBudget($vehicle, '2024-07-01T08:00:00Z'));
	}
}
