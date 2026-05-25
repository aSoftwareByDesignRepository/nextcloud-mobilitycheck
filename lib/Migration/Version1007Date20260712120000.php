<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * §8.5 — `mc_rate_limits` table backing {@see \OCA\MobilityCheck\Service\RateLimitService}.
 *
 * Sliding-window counters keyed by `(user_id, ip, bucket)`. Each request that
 * passes the limit appends one row; the service evaluates the window on read.
 * Stale rows older than the configured window are purged by
 * {@see \OCA\MobilityCheck\BackgroundJob\RateLimitPurgeJob}.
 */
class Version1007Date20260712120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('mc_rate_limits')) {
			$t = $schema->createTable('mc_rate_limits');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => '']);
			$t->addColumn('ip', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => '']);
			$t->addColumn('bucket', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('hit_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['bucket', 'user_id', 'hit_at'], 'mc_rl_buc_usr_at_idx');
			$t->addIndex(['hit_at'], 'mc_rl_hit_at_idx');
		}

		return $schema;
	}
}
