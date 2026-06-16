<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Group-based MobilityCheck role assignments (`mc_group_roles`).
 *
 * Members inherit the union of individual {@see mc_user_roles} rows and
 * every role granted to a Nextcloud group they belong to. Fleet admin is
 * intentionally excluded from group assignment (individual only).
 */
class Version1009Date20260615120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('mc_group_roles')) {
			$t = $schema->createTable('mc_group_roles');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('gid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('role', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mc_grp_role_pk');
			$t->addUniqueIndex(['gid', 'role'], 'mc_grp_role_uidx');
			$t->addIndex(['gid'], 'mc_grp_role_gid_idx');
		}

		return $schema;
	}
}
