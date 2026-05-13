<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Listener;

use OCA\MobilityCheck\Service\BookingService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IDBConnection;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * §11.6 / §11.22 — When a Nextcloud user is deleted:
 *   1. Their MobilityCheck roles are removed.
 *   2. Future bookings (status: pending_* / approved) where the deleted
 *      user is the driver are cancelled with reason DRIVER_OFFBOARDED;
 *      in-flight active bookings stay so the audit trail keeps the
 *      right driver attribution.
 *   3. Driver profile rows are kept (compliance evidence) — only roles
 *      are deleted. Historic IDs in `mc_audit_log`, `mc_bookings`,
 *      `mc_damage_reports`, etc. are preserved verbatim.
 *   4. Notification preferences and onboarding hints are removed.
 *   5. Open `mc_line_manager_assignments` where the deleted user is
 *      the line manager are closed (valid_until = today). Bookings
 *      still in `pending_line_manager` for the drivers they
 *      supervised are flipped to `pending_fleet` with audit reason
 *      LINE_MANAGER_LEAVER_REOPENED so the fleet pool can pick them
 *      up — no booking is silently dropped.
 *
 * Designed to be idempotent — re-running it is a no-op.
 *
 * @implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener
{
	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$userId = $event->getUser()->getUID();
		if ($userId === '') {
			return;
		}
		try {
			$now = gmdate('Y-m-d H:i:s');
			$today = gmdate('Y-m-d');

			// 1) Drop MobilityCheck roles.
			$del = $this->db->getQueryBuilder();
			$del->delete('mc_user_roles')
				->where($del->expr()->eq('user_id', $del->createNamedParameter($userId)));
			$del->executeStatement();

			// 2) Cancel future bookings owned by this user (DRIVER_OFFBOARDED).
			$find = $this->db->getQueryBuilder();
			$find->select('id', 'vehicle_id')
				->from('mc_bookings')
				->where($find->expr()->eq('driver_user_id', $find->createNamedParameter($userId)))
				->andWhere($find->expr()->in('status', $find->createNamedParameter(
					[BookingService::STATUS_PENDING_FLEET, BookingService::STATUS_PENDING_LINE_MANAGER, BookingService::STATUS_APPROVED],
					IQueryBuilder::PARAM_STR_ARRAY,
				)))
				->andWhere($find->expr()->gte('end_datetime', $find->createNamedParameter($now)));
			$res = $find->executeQuery();
			$cancelled = [];
			while (($row = $res->fetch()) !== false) {
				$cancelled[] = (int)$row['id'];
			}
			$res->closeCursor();
			foreach ($cancelled as $bid) {
				$upd = $this->db->getQueryBuilder();
				$upd->update('mc_bookings')
					->set('status', $upd->createNamedParameter(BookingService::STATUS_CANCELLED))
					->set('cancellation_reason', $upd->createNamedParameter('DRIVER_OFFBOARDED — driver account was deleted in Nextcloud.'))
					->set('updated_at', $upd->createNamedParameter($now))
					->where($upd->expr()->eq('id', $upd->createNamedParameter($bid, IQueryBuilder::PARAM_INT)));
				$upd->executeStatement();
				$this->insertAuditRow('booking', $bid, 'cancel_by_user_deletion', '__system__', $now, 'DRIVER_OFFBOARDED');
			}

			// 3) Remove user preferences.
			$delPrefs = $this->db->getQueryBuilder();
			$delPrefs->delete('mc_user_preferences')
				->where($delPrefs->expr()->eq('user_id', $delPrefs->createNamedParameter($userId)));
			$delPrefs->executeStatement();

			// 4) Close LM assignments owned by this user and re-route any
			//    `pending_line_manager` bookings their supervised drivers
			//    still hold so the fleet pool can act.
			$reroutedDrivers = [];
			$openAssignments = $this->db->getQueryBuilder();
			$openAssignments->select('id', 'driver_user_id')
				->from('mc_line_manager_assignments')
				->where($openAssignments->expr()->eq('line_manager_user_id', $openAssignments->createNamedParameter($userId)))
				->andWhere($openAssignments->expr()->lte('valid_from', $openAssignments->createNamedParameter($today)))
				->andWhere($openAssignments->expr()->orX(
					$openAssignments->expr()->isNull('valid_until'),
					$openAssignments->expr()->gte('valid_until', $openAssignments->createNamedParameter($today)),
				));
			$resAssign = $openAssignments->executeQuery();
			$lmAssignmentIds = [];
			while (($r = $resAssign->fetch()) !== false) {
				$lmAssignmentIds[] = (int)$r['id'];
				$reroutedDrivers[(string)$r['driver_user_id']] = true;
			}
			$resAssign->closeCursor();
			foreach ($lmAssignmentIds as $aid) {
				$close = $this->db->getQueryBuilder();
				$close->update('mc_line_manager_assignments')
					->set('valid_until', $close->createNamedParameter($today))
					->where($close->expr()->eq('id', $close->createNamedParameter($aid, IQueryBuilder::PARAM_INT)));
				$close->executeStatement();
				$this->insertAuditRow('line_manager_assignment', $aid, 'close_by_user_deletion', '__system__', $now, 'LINE_MANAGER_LEAVER');
			}
			if ($reroutedDrivers !== []) {
				$drivers = array_keys($reroutedDrivers);
				$findBk = $this->db->getQueryBuilder();
				$findBk->select('id')->from('mc_bookings')
					->where($findBk->expr()->eq('status', $findBk->createNamedParameter(BookingService::STATUS_PENDING_LINE_MANAGER)))
					->andWhere($findBk->expr()->in('driver_user_id', $findBk->createNamedParameter($drivers, IQueryBuilder::PARAM_STR_ARRAY)));
				$resBk = $findBk->executeQuery();
				$reopenIds = [];
				while (($r = $resBk->fetch()) !== false) {
					$reopenIds[] = (int)$r['id'];
				}
				$resBk->closeCursor();
				foreach ($reopenIds as $bid) {
					$flip = $this->db->getQueryBuilder();
					$flip->update('mc_bookings')
						->set('status', $flip->createNamedParameter(BookingService::STATUS_PENDING_FLEET))
						->set('is_escalated', $flip->createNamedParameter(1, IQueryBuilder::PARAM_INT))
						->set('updated_at', $flip->createNamedParameter($now))
						->where($flip->expr()->eq('id', $flip->createNamedParameter($bid, IQueryBuilder::PARAM_INT)))
						->andWhere($flip->expr()->eq('status', $flip->createNamedParameter(BookingService::STATUS_PENDING_LINE_MANAGER)));
					$flip->executeStatement();
					$this->insertAuditRow('booking', $bid, 'reroute_pending_line_manager', '__system__', $now, 'LINE_MANAGER_LEAVER_REOPENED');
				}
			}

			$this->logger->info('MobilityCheck cleaned up after user deletion', [
				'user' => $userId,
				'cancelledBookings' => count($cancelled),
				'closedLineManagerAssignments' => count($lmAssignmentIds),
			]);
		} catch (\Throwable $e) {
			$this->logger->error('MobilityCheck UserDeletedListener failed', [
				'user' => $userId,
				'exception' => $e->getMessage(),
			]);
		}
	}

	private function insertAuditRow(string $entityType, int $entityId, string $action, string $performedBy, string $now, ?string $reason = null): void
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('mc_audit_log')->values([
				'entity_type' => $qb->createNamedParameter($entityType),
				'entity_id' => $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT),
				'action' => $qb->createNamedParameter($action),
				'performed_by_user_id' => $qb->createNamedParameter($performedBy),
				'performed_at' => $qb->createNamedParameter($now),
				'reason' => $reason !== null
					? $qb->createNamedParameter($reason)
					: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			]);
			$qb->executeStatement();
		} catch (\Throwable $e) {
			$this->logger->warning('MobilityCheck audit insert failed during user-deleted cleanup', ['e' => $e->getMessage()]);
		}
	}
}
