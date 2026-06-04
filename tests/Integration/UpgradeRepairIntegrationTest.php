<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Integration;

use OCA\MobilityCheck\Repair\EnsureMobilityCheckSchema;
use OCA\MobilityCheck\Repair\UninstallDropTables;
use OCP\Migration\IOutput;
use Test\TestCase;

class UpgradeRepairIntegrationTest extends TestCase
{
	public function testInstallAndPostMigrationRepairStepsResolveFromContainer(): void
	{
		foreach ([
			EnsureMobilityCheckSchema::class,
			UninstallDropTables::class,
		] as $class) {
			$step = \OC::$server->get($class);
			$this->assertInstanceOf($class, $step);
		}
	}

	public function testEnsureMobilityCheckSchemaRunsWithoutFatal(): void
	{
		/** @var EnsureMobilityCheckSchema $step */
		$step = \OC::$server->get(EnsureMobilityCheckSchema::class);
		$output = $this->createMock(IOutput::class);
		$output->method('info');

		$step->run($output);
		$this->addToAssertionCount(1);
	}
}
