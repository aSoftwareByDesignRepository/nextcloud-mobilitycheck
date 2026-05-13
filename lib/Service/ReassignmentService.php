<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * §A5.4 — When a vehicle becomes unavailable, propose or apply replacement
 * vehicles for affected future bookings.
 */
class ReassignmentService
{
	public function __construct(
		private IDBConnection $db,
		private SettingsService $settings,
		private VehicleSearchService $vehicleSearch,
		private AllocationService $allocation,
		private BookingService $bookings,
		private NotificationService $notifications,
		private AuditLogService $audit,
		private VehicleAssignmentService $assignments,
		private VehicleService $vehicles,
		private AccessControlService $access,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Invoked by {@see \OCA\MobilityCheck\BackgroundJob\FleetReassignmentJob}.
	 */
	public function runScheduledSweep(): void
	{
		if (!$this->settings->intelligentAllocationEnabled()) {
			return;
		}
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.id', 'b.vehicle_id', 'b.driver_user_id', 'b.start_datetime', 'b.end_datetime', 'b.status')
			->from('mc_bookings', 'b')
			->innerJoin('b', 'mc_vehicles', 'v', $qb->expr()->eq('v.id', 'b.vehicle_id'))
			->where($qb->expr()->gte('b.start_datetime', $qb->createNamedParameter($now)))
			->andWhere($qb->expr()->in('v.status', $qb->createNamedParameter([
				VehicleService::STATUS_IN_MAINTENANCE,
				VehicleService::STATUS_DECOMMISSIONED,
			], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('b.status', $qb->createNamedParameter(BookingService::STATUS_APPROVED)),
				$qb->expr()->like('b.status', $qb->createNamedParameter('pending%', IQueryBuilder::PARAM_STR)),
			))
			->orderBy('b.start_datetime', 'ASC');
		$res = $qb->executeQuery();
		$rows = [];
		while (($r = $res->fetch()) !== false) {
			$rows[] = $r;
		}
		$res->closeCursor();
		foreach ($rows as $row) {
			try {
				$this->processAffectedBooking(
					(int)$row['id'],
					(int)$row['vehicle_id'],
					(string)$row['driver_user_id'],
					(string)$row['start_datetime'],
					(string)$row['end_datetime'],
				);
			} catch (\Throwable $e) {
				// One row must never abort the sweep, but the operator needs
				// to see why a reassignment failed. Log the error context to
				// the Nextcloud system log (§A5.4 — fleet reassignment is
				// observable from the admin perspective).
				$this->logger->warning(
					'MobilityCheck: fleet reassignment sweep failed for booking {bookingId}: {error}',
					[
						'app' => 'mobilitycheck',
						'bookingId' => (int)$row['id'],
						'vehicleId' => (int)$row['vehicle_id'],
						'error' => $e->getMessage(),
						'exception' => $e,
					],
				);
			}
		}
	}

	private function processAffectedBooking(
		int $bookingId,
		int $fromVehicleId,
		string $driverUserId,
		string $startUtc,
		string $endUtc,
	): void {
		if ($this->countOpenSuggestionsForBooking($bookingId) > 0) {
			return;
		}
		$a = $this->assignments->getActiveAssignment($fromVehicleId);
		if ($a !== null && ($a['assignment_mode'] ?? '') === VehicleAssignmentService::MODE_DEDICATED) {
			$this->flagManualReassignment($bookingId, 'dedicated_vehicle_unavailable');
			return;
		}
		$candidates = $this->vehicleSearch->eligibleVehiclesForDriverWindow($driverUserId, $startUtc, $endUtc, [], $fromVehicleId);
		if ($candidates === []) {
			$this->handleNoReplacement($bookingId, $driverUserId, $fromVehicleId);
			return;
		}
		$ranked = $this->allocation->rank($candidates, $startUtc, $endUtc);
		$top = $ranked[0] ?? null;
		if ($top === null || !isset($top['id'])) {
			$this->handleNoReplacement($bookingId, $driverUserId, $fromVehicleId);
			return;
		}
		$toId = (int)$top['id'];
		if ($this->hasOpenSuggestionForBooking($bookingId, $toId)) {
			return;
		}
		$mode = $this->settings->intelligentAllocationMode();
		if ($mode === 'auto_commit') {
			try {
				$this->bookings->applyFleetReassignment($bookingId, $toId, BookingService::AUTOMATION_ACTOR, [
					'source' => 'fleet_reassignment_job',
				]);
				$this->notifications->send(
					NotificationService::TYPE_BOOKING_REASSIGNED_DRIVER,
					$driverUserId,
					'booking',
					$bookingId,
					sprintf('booking_reassigned_driver:%d:%d', $bookingId, $toId),
					[
						'bookingId' => $bookingId,
						'vehicleId' => $toId,
						'fromVehicleId' => $fromVehicleId,
					],
				);
				foreach ($this->access->fleetManagerRecipients() as $uid) {
					$this->notifications->send(
						NotificationService::TYPE_BOOKING_REASSIGNED_MANAGER,
						$uid,
						'booking',
						$bookingId,
						sprintf('booking_reassigned_mgr:%d:%d:%s', $bookingId, $toId, $uid),
						[
							'bookingId' => $bookingId,
							'driverUserId' => $driverUserId,
							'fromVehicleId' => $fromVehicleId,
							'vehicleId' => $toId,
						],
					);
				}
			} catch (\Throwable) {
				$this->handleNoReplacement($bookingId, $driverUserId, $fromVehicleId);
			}
			return;
		}
		$now = gmdate('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('mc_booking_reassignment_suggestions')->values([
			'booking_id' => $ins->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT),
			'from_vehicle_id' => $ins->createNamedParameter($fromVehicleId, IQueryBuilder::PARAM_INT),
			'to_vehicle_id' => $ins->createNamedParameter($toId, IQueryBuilder::PARAM_INT),
			'score_breakdown_json' => $ins->createNamedParameter(json_encode([
				'score' => $top['allocation_score'] ?? 0,
				'reasons' => $top['allocation_reasons'] ?? [],
			], JSON_THROW_ON_ERROR), IQueryBuilder::PARAM_STR),
			'status' => $ins->createNamedParameter('open'),
			'created_at' => $ins->createNamedParameter($now),
		]);
		$ins->executeStatement();
		foreach ($this->access->fleetManagerRecipients() as $uid) {
			$this->notifications->send(
				NotificationService::TYPE_BOOKING_REASSIGNMENT_SUGGESTED,
				$uid,
				'booking',
				$bookingId,
				sprintf('reassignment_suggest:%d:%d', $bookingId, $toId),
				[
					'bookingId' => $bookingId,
					'fromVehicleId' => $fromVehicleId,
					'toVehicleId' => $toId,
				],
			);
		}
	}

	private function countOpenSuggestionsForBooking(int $bookingId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'c')
			->from('mc_booking_reassignment_suggestions')
			->where($qb->expr()->eq('booking_id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function hasOpenSuggestionForBooking(int $bookingId, int $toVehicleId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'c')
			->from('mc_booking_reassignment_suggestions')
			->where($qb->expr()->eq('booking_id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->eq('to_vehicle_id', $qb->createNamedParameter($toVehicleId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	private function flagManualReassignment(int $bookingId, string $reasonCode): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_bookings')
			->set('flag_requires_manual_reassignment', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('booking', $bookingId, 'reassignment_manual_required', BookingService::AUTOMATION_ACTOR, [
			'reason' => $reasonCode,
		]);
	}

	private function handleNoReplacement(int $bookingId, string $driverUserId, int $fromVehicleId): void
	{
		if ($this->settings->intelligentAllocationOnNoReplacement() === 'auto_cancel') {
			try {
				$this->bookings->cancel($bookingId, BookingService::AUTOMATION_ACTOR, 'NO_REPLACEMENT_VEHICLE_AUTOMATION');
			} catch (\Throwable) {
				$this->flagManualReassignment($bookingId, 'auto_cancel_failed');
			}
			return;
		}
		$this->flagManualReassignment($bookingId, 'no_eligible_replacement');
		foreach ($this->access->fleetManagerRecipients() as $uid) {
			$this->notifications->send(
				NotificationService::TYPE_BOOKING_REASSIGNMENT_MANUAL_REQUIRED,
				$uid,
				'booking',
				$bookingId,
				sprintf('reassignment_manual:%d', $bookingId),
				[
					'bookingId' => $bookingId,
					'driverUserId' => $driverUserId,
					'fromVehicleId' => $fromVehicleId,
				],
			);
		}
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listOpenSuggestions(string $viewerId): array
	{
		$this->requireFleetManagerClass($viewerId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.*', 'b.start_datetime', 'b.end_datetime', 'b.driver_user_id', 'b.status AS booking_status')
			->from('mc_booking_reassignment_suggestions', 's')
			->innerJoin('s', 'mc_bookings', 'b', $qb->expr()->eq('b.id', 's.booking_id'))
			->where($qb->expr()->eq('s.status', $qb->createNamedParameter('open')))
			->orderBy('s.created_at', 'DESC');
		$out = [];
		$res = $qb->executeQuery();
		$ids = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = $r;
			$ids[] = (int)$r['from_vehicle_id'];
			$ids[] = (int)$r['to_vehicle_id'];
		}
		$res->closeCursor();
		$sum = $this->vehicles->summariesByIds(array_values(array_unique(array_filter($ids))));
		foreach ($out as &$row) {
			$fv = (int)$row['from_vehicle_id'];
			$tv = (int)$row['to_vehicle_id'];
			$row['from_vehicle'] = $sum[$fv] ?? null;
			$row['to_vehicle'] = $sum[$tv] ?? null;
		}
		unset($row);
		return $out;
	}

	public function acceptSuggestion(int $suggestionId, string $actorUserId): array
	{
		$this->requireFleetManagerClass($actorUserId);
		$row = $this->getSuggestionRow($suggestionId);
		if (($row['status'] ?? '') !== 'open') {
			throw new ValidationException('SUGGESTION_NOT_OPEN');
		}
		$bookingId = (int)$row['booking_id'];
		$toId = (int)$row['to_vehicle_id'];
		$this->bookings->applyFleetReassignment($bookingId, $toId, $actorUserId, [
			'suggestion_id' => $suggestionId,
		]);
		$this->closeSuggestion($suggestionId, $actorUserId, 'accepted');
		$this->supersedeOtherOpenSuggestions($bookingId, $suggestionId, $actorUserId);
		$b = $this->bookings->get($bookingId);
		$this->notifications->send(
			NotificationService::TYPE_BOOKING_REASSIGNED_DRIVER,
			(string)$b['driver_user_id'],
			'booking',
			$bookingId,
			sprintf('booking_reassigned_driver:%d:%d', $bookingId, $toId),
			[
				'bookingId' => $bookingId,
				'vehicleId' => $toId,
			],
		);
		foreach ($this->access->fleetManagerRecipients() as $uid) {
			$this->notifications->send(
				NotificationService::TYPE_BOOKING_REASSIGNED_MANAGER,
				$uid,
				'booking',
				$bookingId,
				sprintf('booking_reassigned_mgr:%d:%d:%s', $bookingId, $toId, $uid),
				[
					'bookingId' => $bookingId,
					'driverUserId' => (string)$b['driver_user_id'],
				],
			);
		}
		return $b;
	}

	public function dismissSuggestion(int $suggestionId, string $actorUserId): void
	{
		$this->requireFleetManagerClass($actorUserId);
		$row = $this->getSuggestionRow($suggestionId);
		if (($row['status'] ?? '') !== 'open') {
			throw new ValidationException('SUGGESTION_NOT_OPEN');
		}
		$this->closeSuggestion($suggestionId, $actorUserId, 'dismissed');
	}

	private function requireFleetManagerClass(string $userId): void
	{
		if (!$this->access->isFleetAdminOrManager($userId) && !$this->access->isAppAdmin($userId)) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
	}

	/** @return array<string,mixed> */
	private function getSuggestionRow(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_booking_reassignment_suggestions')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if (!$row) {
			throw new NotFoundException('SUGGESTION_NOT_FOUND');
		}
		return $row;
	}

	private function closeSuggestion(int $id, string $actorUserId, string $status): void
	{
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_booking_reassignment_suggestions')
			->set('status', $qb->createNamedParameter($status))
			->set('resolved_at', $qb->createNamedParameter($now))
			->set('resolved_by_user_id', $qb->createNamedParameter($actorUserId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function supersedeOtherOpenSuggestions(int $bookingId, int $keepId, string $actorUserId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_booking_reassignment_suggestions')
			->set('status', $qb->createNamedParameter('superseded'))
			->set('resolved_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->set('resolved_by_user_id', $qb->createNamedParameter($actorUserId))
			->where($qb->expr()->eq('booking_id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($keepId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
