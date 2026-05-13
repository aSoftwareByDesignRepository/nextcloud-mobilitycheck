<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Server;

/**
 * Backfill mc_booking_approvals for historical bookings that were
 * approved (or rejected) before the approval audit table existed.
 *
 * Without this, the new "GET /api/bookings/{id}/approvals" endpoint
 * would return an empty list for legacy data — making the audit
 * trail look like a fresh install lost its history.
 *
 * Strategy:
 *  - For each booking in `approved`, `active`, `completed` state that
 *    has `approved_at IS NOT NULL` and no row in mc_booking_approvals,
 *    insert one synthetic decision row (step = `fleet_manager`,
 *    decision = `approved`) using the booking's approved_by / approved_at.
 *  - For each booking in `rejected` state with `rejection_reason`, insert
 *    one row reflecting the rejection so the trail is symmetric.
 *
 * The synthetic rows are clearly distinguishable from new ones because
 * they reuse the booking's original `approved_at` timestamp instead of
 * `now()`. Reading consumers (UI + JSON API) never need to know the
 * difference — the data shape matches.
 *
 * This migration is idempotent: the WHERE-clause ensures it never
 * double-inserts; running it again is a no-op.
 */
class Version1004Date20260603120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$connection = Server::get(IDBConnection::class);

		// 1. Approved / active / completed bookings with no approval audit row.
		$find = $connection->getQueryBuilder();
		$find->select('b.id', 'b.approved_by_user_id', 'b.approved_at', 'b.approval_mode_snapshot')
			->from('mc_bookings', 'b')
			->leftJoin('b', 'mc_booking_approvals', 'a', 'a.booking_id = b.id')
			->where($find->expr()->in('b.status', $find->createNamedParameter(
				['approved', 'active', 'completed'],
				\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->andWhere($find->expr()->isNotNull('b.approved_at'))
			->andWhere($find->expr()->isNotNull('b.approved_by_user_id'))
			->andWhere($find->expr()->isNull('a.id'));
		$res = $find->executeQuery();
		$rows = [];
		while (($r = $res->fetch()) !== false) {
			$rows[] = $r;
		}
		$res->closeCursor();
		foreach ($rows as $r) {
			$ins = $connection->getQueryBuilder();
			$ins->insert('mc_booking_approvals')->values([
				'booking_id' => $ins->createNamedParameter((int)$r['id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'step' => $ins->createNamedParameter('fleet_manager'),
				'decision' => $ins->createNamedParameter('approved'),
				'approver_user_id' => $ins->createNamedParameter((string)$r['approved_by_user_id']),
				'approver_role' => $ins->createNamedParameter('fleet_manager'),
				'decided_at' => $ins->createNamedParameter((string)$r['approved_at']),
				'reason' => $ins->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL),
			]);
			try {
				$ins->executeStatement();
			} catch (\Throwable) {
				// Best-effort: never block the migration on a single row.
			}
		}

		// 2. Rejected bookings without an approval row.
		$findR = $connection->getQueryBuilder();
		$findR->select('b.id', 'b.rejection_reason', 'b.updated_at')
			->from('mc_bookings', 'b')
			->leftJoin('b', 'mc_booking_approvals', 'a', 'a.booking_id = b.id')
			->where($findR->expr()->eq('b.status', $findR->createNamedParameter('rejected')))
			->andWhere($findR->expr()->isNull('a.id'));
		$resR = $findR->executeQuery();
		$rejRows = [];
		while (($r = $resR->fetch()) !== false) {
			$rejRows[] = $r;
		}
		$resR->closeCursor();
		foreach ($rejRows as $r) {
			$ins = $connection->getQueryBuilder();
			$ins->insert('mc_booking_approvals')->values([
				'booking_id' => $ins->createNamedParameter((int)$r['id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'step' => $ins->createNamedParameter('fleet_manager'),
				'decision' => $ins->createNamedParameter('rejected'),
				'approver_user_id' => $ins->createNamedParameter('__system__'),
				'approver_role' => $ins->createNamedParameter('fleet_manager'),
				'decided_at' => $ins->createNamedParameter((string)$r['updated_at']),
				'reason' => $r['rejection_reason'] !== null
					? $ins->createNamedParameter((string)$r['rejection_reason'])
					: $ins->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL),
			]);
			try {
				$ins->executeStatement();
			} catch (\Throwable) {
				// noop on duplicate or invalid row
			}
		}
	}
}
