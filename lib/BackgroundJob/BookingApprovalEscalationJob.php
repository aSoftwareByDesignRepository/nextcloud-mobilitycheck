<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\BackgroundJob;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\BookingService;
use OCA\MobilityCheck\Service\LineManagerService;
use OCA\MobilityCheck\Service\NotificationService;
use OCA\MobilityCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * §4.5a — Approval escalation timeouts.
 *
 * Two distinct escalation windows are enforced by this job:
 *
 *  1. `pending_line_manager` rows older than
 *     `approval_line_manager_timeout_hours` flip `is_escalated = 1`
 *     and surface in fleet managers' "my approvals" queue. The
 *     booking does **not** auto-promote to `pending_fleet` so the
 *     audit trail keeps the LM step visible; fleet managers use the
 *     dedicated override endpoint to bypass it.
 *  2. `pending_fleet` rows older than `approval_fleet_timeout_hours`
 *     fire a notification to every fleet manager / app admin so the
 *     pool is reminded; `is_escalated = 1` is also set to drive the
 *     dashboard "needs attention" badge.
 *
 * Idempotency: a notification is deduplicated per booking+stage via
 * the standard {@see NotificationService::wasSent()} guard. The
 * `is_escalated` flag is written with a status-aware WHERE so the
 * UPDATE is a no-op once the booking moves on. Fleet escalation rows
 * are selected only while `is_escalated = 0` so each booking is
 * processed once per stage.
 */
final class BookingApprovalEscalationJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private IDBConnection $db,
		private NotificationService $notifications,
		private AccessControlService $access,
		private SettingsService $settings,
		private LineManagerService $lineManagers,
		private IUserManager $userManager,
	) {
		parent::__construct($time);
		$this->setInterval(15 * 60);
	}

	protected function run($argument): void
	{
		$lmHours = $this->settings->approvalLineManagerTimeoutHours();
		$fleetHours = $this->settings->approvalFleetTimeoutHours();
		if ($lmHours > 0) {
			$this->escalateLineManagerOverdue($lmHours);
		}
		if ($fleetHours > 0) {
			$this->escalateFleetOverdue($fleetHours);
		}
	}

	private function escalateLineManagerOverdue(int $hours): void
	{
		$cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
			->modify('-' . $hours . ' hours')
			->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.id', 'b.driver_user_id', 'b.vehicle_id', 'b.start_datetime', 'b.end_datetime', 'v.internal_name')
			->from('mc_bookings', 'b')
			->leftJoin('b', 'mc_vehicles', 'v', 'v.id = b.vehicle_id')
			->where($qb->expr()->eq('b.status', $qb->createNamedParameter(BookingService::STATUS_PENDING_LINE_MANAGER)))
			->andWhere($qb->expr()->eq('b.is_escalated', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('b.created_at', $qb->createNamedParameter($cutoff)));
		$res = $qb->executeQuery();
		$rows = [];
		while (($r = $res->fetch()) !== false) {
			$rows[] = $r;
		}
		$res->closeCursor();
		if ($rows === []) {
			return;
		}
		$managers = $this->access->fleetManagerRecipients();
		foreach ($rows as $row) {
			$bookingId = (int)$row['id'];
			$driverId = (string)($row['driver_user_id'] ?? '');
			$driverLabel = $this->driverDisplayLabel($driverId);
			$context = [
				'bookingId' => $bookingId,
				'vehicleId' => (int)$row['vehicle_id'],
				'vehicleName' => (string)($row['internal_name'] ?? ''),
				'driverName' => $driverLabel,
				'start' => (string)$row['start_datetime'],
				'end' => (string)$row['end_datetime'],
				'timeoutHours' => $hours,
			];
			$this->markEscalated($bookingId, BookingService::STATUS_PENDING_LINE_MANAGER);
			$lmId = $this->lineManagers->getActiveLineManagerUserIdForDriver($driverId);
			if ($lmId !== null && $lmId !== '') {
				$this->notifications->send(
					NotificationService::TYPE_APPROVAL_LINE_MANAGER_TIMEOUT_REMINDER,
					$lmId,
					'booking',
					$bookingId,
					sprintf('approval.lm_timeout_reminder:%d', $bookingId),
					$context,
				);
			}
			if ($managers !== []) {
				$this->notifications->sendMany(
					NotificationService::TYPE_APPROVAL_ESCALATED_LM,
					$managers,
					'booking',
					$bookingId,
					sprintf('approval.escalated_line_manager:%d:{userId}', $bookingId),
					$context,
				);
			}
		}
	}

	private function escalateFleetOverdue(int $hours): void
	{
		$cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
			->modify('-' . $hours . ' hours')
			->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.id', 'b.driver_user_id', 'b.vehicle_id', 'b.start_datetime', 'b.end_datetime', 'v.internal_name')
			->from('mc_bookings', 'b')
			->leftJoin('b', 'mc_vehicles', 'v', 'v.id = b.vehicle_id')
			->where($qb->expr()->eq('b.status', $qb->createNamedParameter(BookingService::STATUS_PENDING_FLEET)))
			->andWhere($qb->expr()->eq('b.is_escalated', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('b.created_at', $qb->createNamedParameter($cutoff)));
		$res = $qb->executeQuery();
		$rows = [];
		while (($r = $res->fetch()) !== false) {
			$rows[] = $r;
		}
		$res->closeCursor();
		if ($rows === []) {
			return;
		}
		$managers = $this->access->fleetManagerRecipients();
		foreach ($rows as $row) {
			$bookingId = (int)$row['id'];
			$driverId = (string)($row['driver_user_id'] ?? '');
			$driverLabel = $this->driverDisplayLabel($driverId);
			$context = [
				'bookingId' => $bookingId,
				'vehicleId' => (int)$row['vehicle_id'],
				'vehicleName' => (string)($row['internal_name'] ?? ''),
				'driverName' => $driverLabel,
				'start' => (string)$row['start_datetime'],
				'end' => (string)$row['end_datetime'],
				'timeoutHours' => $hours,
			];
			$this->markEscalated($bookingId, BookingService::STATUS_PENDING_FLEET);
			if ($managers !== []) {
				$this->notifications->sendMany(
					NotificationService::TYPE_APPROVAL_ESCALATED_FLEET,
					$managers,
					'booking',
					$bookingId,
					sprintf('approval.escalated_fleet:%d:{userId}', $bookingId),
					$context,
				);
			}
		}
	}

	private function markEscalated(int $bookingId, string $expectedStatus): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_bookings')
			->set('is_escalated', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($expectedStatus)));
		$qb->executeStatement();
	}

	private function driverDisplayLabel(string $driverUserId): string
	{
		if ($driverUserId === '') {
			return '-';
		}
		$user = $this->userManager->get($driverUserId);
		if ($user === null) {
			return $driverUserId;
		}
		$dn = trim((string)$user->getDisplayName());
		return $dn !== '' ? $dn : $driverUserId;
	}
}
