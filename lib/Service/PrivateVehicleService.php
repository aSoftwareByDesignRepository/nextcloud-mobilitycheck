<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Appendix A4.2 — driver's registered private vehicles for reimbursement. */
class PrivateVehicleService
{
	public const ENGINES = ['petrol', 'diesel', 'electric', 'hybrid'];

	public function __construct(
		private IDBConnection $db,
		private DriverService $drivers,
		private AccessControlService $access,
		private AuditLogService $audit,
		private SettingsService $settings,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function listForViewer(string $viewerId, ?int $driverProfileIdFilter = null): array
	{
		$this->access->requireDriver($viewerId);
		$isManager = $this->access->isFleetAdminOrManager($viewerId);

		// A manager who is also a driver and asks for "everything" gets their
		// own private vehicles by default — the same as a plain driver would.
		// A manager-only user must still pick a driver profile to look at.
		$selfProfile = null;
		if ($driverProfileIdFilter === null && $isManager) {
			$selfProfile = $this->drivers->getByUserId($viewerId);
			if ($selfProfile === null) {
				throw new ValidationException('DRIVER_PROFILE_REQUIRED');
			}
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('pv.*')->from('mc_private_vehicles', 'pv')->orderBy('pv.id', 'DESC');
		if ($driverProfileIdFilter !== null && $isManager) {
			$qb->where($qb->expr()->eq('pv.driver_profile_id', $qb->createNamedParameter($driverProfileIdFilter, IQueryBuilder::PARAM_INT)));
		} elseif (!$isManager) {
			$p = $this->drivers->getByUserId($viewerId);
			if ($p === null) {
				return [];
			}
			$qb->where($qb->expr()->eq('pv.driver_profile_id', $qb->createNamedParameter((int)$p['id'], IQueryBuilder::PARAM_INT)));
		} elseif ($selfProfile !== null) {
			$qb->where($qb->expr()->eq('pv.driver_profile_id', $qb->createNamedParameter((int)$selfProfile['id'], IQueryBuilder::PARAM_INT)));
		}
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = $this->hydrate($r);
		}
		$res->closeCursor();
		return $out;
	}

	/** @param array<string,mixed> $payload */
	public function createForDriverProfile(int $driverProfileId, array $payload, string $by): array
	{
		$this->access->requireFleetAdminOrManager($by);
		$this->drivers->get($driverProfileId);
		return $this->insertVehicle($driverProfileId, $payload, $by);
	}

	/** @param array<string,mixed> $payload */
	public function createOwn(array $payload, string $userId): array
	{
		if (!$this->settings->reimbursementEnabled()) {
			throw new ValidationException('REIMBURSEMENT_MODULE_DISABLED');
		}
		$this->access->requireDriver($userId);
		$p = $this->drivers->ensureProfileForUser($userId, $userId);
		return $this->insertVehicle((int)$p['id'], $payload, $userId);
	}

	public function deactivate(int $id, string $by): array
	{
		$row = $this->fetch($id);
		$this->assertMayMutateVehicle($row, $by);
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_private_vehicles')
			->set('is_active', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->log('private_vehicle', $id, 'deactivate', $by, []);
		return $this->hydrate($this->fetchRowRaw($id));
	}

	/** @param array<string,mixed> $payload */
	public function update(int $id, array $payload, string $by): array
	{
		$row = $this->fetch($id);
		$this->assertMayMutateVehicle($row, $by);
		$data = $this->validatePayload($payload, partial: true, prev: $row);
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_private_vehicles')
			->set('make', $qb->createNamedParameter($data['make']))
			->set('model', $qb->createNamedParameter($data['model']))
			->set('licence_plate', $qb->createNamedParameter($data['licence_plate']))
			->set('engine_type', $qb->createNamedParameter($data['engine_type']))
			->set('updated_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		return $this->hydrate($this->fetchRowRaw($id));
	}

	public function get(int $id, string $viewerId): array
	{
		$row = $this->fetch($id);
		$this->assertMayViewVehicle($row, $viewerId);
		return $row;
	}

	/** @param array<string,mixed> $payload */
	private function insertVehicle(int $driverProfileId, array $payload, string $by): array
	{
		$d = $this->validatePayload($payload, partial: false, prev: null);
		$now = gmdate('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('mc_private_vehicles')->values([
			'driver_profile_id' => $ins->createNamedParameter($driverProfileId, IQueryBuilder::PARAM_INT),
			'make' => $ins->createNamedParameter($d['make']),
			'model' => $ins->createNamedParameter($d['model']),
			'licence_plate' => $ins->createNamedParameter($d['licence_plate']),
			'engine_type' => $ins->createNamedParameter($d['engine_type']),
			'is_active' => $ins->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'created_at' => $ins->createNamedParameter($now),
			'updated_at' => $ins->createNamedParameter($now),
		]);
		$ins->executeStatement();
		$id = (int)$this->db->lastInsertId('mc_private_vehicles');
		$this->audit->log('private_vehicle', $id, 'create', $by, ['driver_profile_id' => $driverProfileId]);
		return $this->hydrate($this->fetchRowRaw($id));
	}

	private function fetch(int $id): array
	{
		return $this->hydrate($this->fetchRowRaw($id));
	}

	/** @return array<string,mixed> */
	private function fetchRowRaw(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_private_vehicles')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$r = $qb->executeQuery()->fetch();
		if (!$r) {
			throw new NotFoundException('PRIVATE_VEHICLE_NOT_FOUND');
		}
		return $r;
	}

	/** @param array<string,mixed> $row */
	private function assertMayViewVehicle(array $row, string $viewerId): void
	{
		if ($this->access->isFleetAdminOrManager($viewerId) || $this->access->isAuditor($viewerId)) {
			return;
		}
		$p = $this->drivers->get((int)$row['driver_profile_id']);
		if (($p['user_id'] ?? '') !== $viewerId) {
			throw new ForbiddenException('CANNOT_VIEW_PRIVATE_VEHICLE');
		}
	}

	/** @param array<string,mixed> $row */
	private function assertMayMutateVehicle(array $row, string $by): void
	{
		if ($this->access->isFleetAdminOrManager($by)) {
			return;
		}
		$p = $this->drivers->get((int)$row['driver_profile_id']);
		if (($p['user_id'] ?? '') !== $by) {
			throw new ForbiddenException('CANNOT_EDIT_PRIVATE_VEHICLE');
		}
	}

	/** @param ?array<string,mixed> $prev */
	private function validatePayload(array $payload, bool $partial, ?array $prev): array
	{
		$make = trim((string)($payload['make'] ?? ($partial ? ($prev['make'] ?? '') : '')));
		$model = trim((string)($payload['model'] ?? ($partial ? ($prev['model'] ?? '') : '')));
		$plate = trim((string)($payload['licencePlate'] ?? $payload['licence_plate'] ?? ($partial ? ($prev['licence_plate'] ?? '') : '')));
		$engine = (string)($payload['engineType'] ?? $payload['engine_type'] ?? ($partial ? ($prev['engine_type'] ?? '') : ''));
		if ($make === '') {
			throw new ValidationException('PRIVATE_VEHICLE_FIELDS_REQUIRED', 'make');
		}
		if ($model === '') {
			throw new ValidationException('PRIVATE_VEHICLE_FIELDS_REQUIRED', 'model');
		}
		if ($plate === '') {
			throw new ValidationException('PRIVATE_VEHICLE_FIELDS_REQUIRED', 'licencePlate');
		}
		if (!in_array($engine, self::ENGINES, true)) {
			throw new ValidationException('ENGINE_TYPE_INVALID', 'engineType');
		}
		return [
			'make' => mb_substr($make, 0, 80),
			'model' => mb_substr($model, 0, 80),
			'licence_plate' => mb_substr($plate, 0, 20),
			'engine_type' => $engine,
		];
	}

	/** @param array<string,mixed> $row */
	private function hydrate(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'driver_profile_id' => (int)$row['driver_profile_id'],
			'make' => (string)$row['make'],
			'model' => (string)$row['model'],
			'licence_plate' => (string)$row['licence_plate'],
			'engine_type' => (string)$row['engine_type'],
			'is_active' => (int)$row['is_active'] === 1,
			'created_at' => (string)$row['created_at'],
			'updated_at' => (string)$row['updated_at'],
		];
	}
}
