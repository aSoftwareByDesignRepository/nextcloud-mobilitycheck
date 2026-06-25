<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Repair;

use OCA\MobilityCheck\Repair\BackupBeforeUpdate;
use OCA\MobilityCheck\Service\UpgradeBackupService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class BackupBeforeUpdateTest extends TestCase
{
	public function testSkipsWhenNoTablesExist(): void
	{
		$backup = $this->createMock(UpgradeBackupService::class);
		$backup->expects(self::once())->method('hasDataToBackup')->willReturn(false);
		$backup->expects(self::never())->method('createSnapshot');

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info');

		$step = new BackupBeforeUpdate($backup);
		$step->run($output);
	}

	public function testCreatesSnapshotWhenTablesExist(): void
	{
		$backup = $this->createMock(UpgradeBackupService::class);
		$backup->expects(self::once())->method('hasDataToBackup')->willReturn(true);
		$backup->expects(self::once())->method('createSnapshot')
			->with('pre-migration')
			->willReturn([
				'id' => '20260624T120000Z-deadbeef',
				'manifest' => ['tables' => ['pc_projects' => []]],
			]);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info');

		$step = new BackupBeforeUpdate($backup);
		$step->run($output);
	}

	public function testPropagatesBackupFailure(): void
	{
		$backup = $this->createMock(UpgradeBackupService::class);
		$backup->method('hasDataToBackup')->willReturn(true);
		$backup->method('createSnapshot')->willThrowException(
			new \OCA\MobilityCheck\Exception\UpgradeBackupException('disk full'),
		);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');

		$this->expectException(\OCA\MobilityCheck\Exception\UpgradeBackupException::class);

		(new BackupBeforeUpdate($backup))->run($output);
	}
}
