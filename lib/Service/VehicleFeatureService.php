<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Appendix A2.3 — searchable vehicle attributes. */
class VehicleFeatureService
{
	/** @return list<string> */
	public static function catalogKeys(): array
	{
		return [
			'automatic_transmission', 'tow_hitch', 'child_seat_isofix', 'navigation', 'roof_box',
			'all_wheel_drive', 'handicap_accessible', 'bicycle_rack', 'winter_tyres', 'seven_seats',
			'parking_sensor', 'dashcam', 'minimum_boot_litres', 'ev_range_km',
		];
	}

	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private AuditLogService $audit,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function listVehicle(int $vehicleId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_vehicle_features')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
		$out = [];
		$res = $qb->executeQuery();
		while (($r = $res->fetch()) !== false) {
			$out[] = [
				'id' => (int)$r['id'],
				'vehicle_id' => (int)$r['vehicle_id'],
				'feature_key' => (string)$r['feature_key'],
				'feature_value' => $r['feature_value'] !== null ? (string)$r['feature_value'] : null,
			];
		}
		$res->closeCursor();
		return $out;
	}

	/** @param array<string,mixed> $payload */
	public function add(int $vehicleId, array $payload, string $by): array
	{
		$this->access->requireFleetAdminOrManager($by);
		$key = trim((string)($payload['featureKey'] ?? $payload['feature_key'] ?? ''));
		if (!in_array($key, self::catalogKeys(), true)) {
			throw new ValidationException('FEATURE_KEY_INVALID');
		}
		$val = $payload['featureValue'] ?? $payload['feature_value'] ?? null;
		$val = $val !== null && $val !== '' ? mb_substr(trim((string)$val), 0, 120) : null;
		$now = gmdate('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('mc_vehicle_features')->values([
			'vehicle_id' => $ins->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT),
			'feature_key' => $ins->createNamedParameter($key),
			'feature_value' => $ins->createNamedParameter($val),
		]);
		$ins->executeStatement();
		$id = (int)$this->db->lastInsertId('mc_vehicle_features');
		$this->audit->log('vehicle_feature', $id, 'create', $by, ['vehicle_id' => $vehicleId, 'key' => $key]);
		return ['id' => $id, 'vehicle_id' => $vehicleId, 'feature_key' => $key, 'feature_value' => $val];
	}

	public function delete(int $featureId, string $by): void
	{
		$this->access->requireFleetAdminOrManager($by);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_vehicle_features')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($featureId, IQueryBuilder::PARAM_INT)));
		$r = $qb->executeQuery()->fetch();
		if (!$r) {
			throw new NotFoundException('VEHICLE_FEATURE_NOT_FOUND');
		}
		$del = $this->db->getQueryBuilder();
		$del->delete('mc_vehicle_features')->where($del->expr()->eq('id', $del->createNamedParameter($featureId, IQueryBuilder::PARAM_INT)));
		$del->executeStatement();
		$this->audit->log('vehicle_feature', $featureId, 'delete', $by, []);
	}
}
