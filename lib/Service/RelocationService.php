<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * §4.2a.4 — One-way bookings spawn relocation tasks until fleet marks them complete.
 */
class RelocationService
{
	public const STATUS_OPEN = 'open';
	public const STATUS_COMPLETED = 'completed';

	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private AuditLogService $audit,
		private VehicleService $vehicles,
	) {
	}

	/**
	 * Idempotent open-task creation after check-in closed a one-way booking.
	 */
	public function ensureOpenTaskAfterCheckin(
		int $bookingId,
		int $vehicleId,
		?int $pickupStationId,
		?int $returnStationId,
		string $actorUserId,
	): void {
		if ($pickupStationId === null || $returnStationId === null || $pickupStationId === $returnStationId) {
			return;
		}
		if ($this->findOpenForBooking($bookingId) !== null) {
			return;
		}
		$now = gmdate('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('mc_relocation_tasks')->values([
			'vehicle_id' => $ins->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT),
			'source_booking_id' => $ins->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT),
			'from_station_id' => $ins->createNamedParameter($pickupStationId, IQueryBuilder::PARAM_INT),
			'to_station_id' => $ins->createNamedParameter($returnStationId, IQueryBuilder::PARAM_INT),
			'opened_at' => $ins->createNamedParameter($now),
			'status' => $ins->createNamedParameter(self::STATUS_OPEN),
			'assigned_to_user_id' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'completed_at' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'notes' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
		]);
		$ins->executeStatement();
		$this->audit->log('relocation_task', $bookingId, 'opened_from_booking', $actorUserId, [
			'vehicle_id' => $vehicleId,
			'from_station_id' => $pickupStationId,
			'to_station_id' => $returnStationId,
		]);
	}

	private function findOpenForBooking(int $bookingId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_relocation_tasks')
			->where($qb->expr()->eq('source_booking_id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_OPEN)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		return $row !== false ? $row : null;
	}

	/** @return list<array<string,mixed>> */
	public function listOpen(string $viewerId): array
	{
		if (!$this->access->isFleetAdminOrManager($viewerId) && !$this->access->isAppAdmin($viewerId)) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
		$this->access->requireAnyAppRole($viewerId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_relocation_tasks')
			->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_OPEN)))
			->orderBy('opened_at', 'DESC')
			->setMaxResults(200);
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = $this->decorateRow($r);
		}
		$res->closeCursor();
		return $out;
	}

	public function complete(int $id, string $viewerId, ?string $notes): array
	{
		if (!$this->access->isFleetAdminOrManager($viewerId) && !$this->access->isAppAdmin($viewerId)) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
		$row = $this->fetchRow($id);
		if (($row['status'] ?? '') !== self::STATUS_OPEN) {
			throw new ValidationException('RELOCATION_NOT_OPEN');
		}
		$now = gmdate('Y-m-d H:i:s');
		$n = $notes !== null ? trim($notes) : null;
		if ($n !== null && mb_strlen($n) > 8000) {
			throw new ValidationException('NOTES_TOO_LONG', 'notes');
		}
		$upd = $this->db->getQueryBuilder();
		$upd->update('mc_relocation_tasks')
			->set('status', $upd->createNamedParameter(self::STATUS_COMPLETED))
			->set('completed_at', $upd->createNamedParameter($now))
			->set('notes', $n !== null && $n !== ''
				? $upd->createNamedParameter($n)
				: $upd->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$upd->executeStatement();
		$this->audit->log('relocation_task', $id, 'complete', $viewerId, []);
		return $this->decorateRow($this->fetchRow($id));
	}

	/** @return array<string,mixed> */
	private function fetchRow(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_relocation_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if (!$row) {
			throw new NotFoundException('RELOCATION_NOT_FOUND');
		}
		return $row;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function decorateRow(array $row): array
	{
		$vid = (int)$row['vehicle_id'];
		$fromId = (int)$row['from_station_id'];
		$toId = (int)$row['to_station_id'];
		$vehicle = $this->vehicles->summariesByIds([$vid])[$vid] ?? null;
		$fromSt = $this->stationLabel($fromId);
		$toSt = $this->stationLabel($toId);
		return [
			'id' => (int)$row['id'],
			'vehicle_id' => $vid,
			'source_booking_id' => (int)$row['source_booking_id'],
			'from_station_id' => $fromId,
			'to_station_id' => $toId,
			'opened_at' => (string)$row['opened_at'],
			'status' => (string)$row['status'],
			'assigned_to_user_id' => $row['assigned_to_user_id'] !== null ? (string)$row['assigned_to_user_id'] : null,
			'completed_at' => $row['completed_at'] !== null ? (string)$row['completed_at'] : null,
			'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
			'vehicle_internal_name' => $vehicle['internal_name'] ?? null,
			'vehicle_licence_plate' => $vehicle['licence_plate'] ?? null,
			'from_station_code' => $fromSt['code'] ?? null,
			'from_station_name' => $fromSt['name'] ?? null,
			'to_station_code' => $toSt['code'] ?? null,
			'to_station_name' => $toSt['name'] ?? null,
		];
	}

	/** @return array{code:?string,name:?string} */
	private function stationLabel(int $id): array
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('code', 'name')->from('mc_stations')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->setMaxResults(1);
			$r = $qb->executeQuery()->fetch();
			if (!$r) {
				return ['code' => null, 'name' => null];
			}
			return ['code' => (string)$r['code'], 'name' => (string)$r['name']];
		} catch (\Throwable) {
			return ['code' => null, 'name' => null];
		}
	}
}
