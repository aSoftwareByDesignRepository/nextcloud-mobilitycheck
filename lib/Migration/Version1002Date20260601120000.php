<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Handover location notes on checkout logs (pool/group chain of custody).
 *
 * @see BookingService::checkout / ::checkin
 */
class Version1002Date20260601120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('mc_checkout_logs')) {
			$t = $schema->getTable('mc_checkout_logs');
			if (!$t->hasColumn('pickup_location_note')) {
				$t->addColumn('pickup_location_note', Types::STRING, ['length' => 500, 'notnull' => false]);
			}
			if (!$t->hasColumn('return_location_note')) {
				$t->addColumn('return_location_note', Types::STRING, ['length' => 500, 'notnull' => false]);
			}
		}

		return $schema;
	}
}
