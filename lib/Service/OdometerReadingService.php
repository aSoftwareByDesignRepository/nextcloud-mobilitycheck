<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Appendix A6 — manual / admin odometer snapshots for gap analysis. */
class OdometerReadingService
{
	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private VehicleAssignmentService $assignments,
		private AuditLogService $audit,
	) {
	}

	/** @param array<string,mixed> $payload */
	public function create(array $payload, string $by): array
	{
		$this->access->requireDriver($by);
		$vehicleId = (int)($payload['vehicleId'] ?? $payload['vehicle_id'] ?? 0);
		if ($vehicleId <= 0) {
			throw new ValidationException('VEHICLE_REQUIRED');
		}
		if (!$this->assignments->userMaySeeVehicle($by, $vehicleId)) {
			throw new ValidationException('VEHICLE_NOT_VISIBLE');
		}
		$km = (int)($payload['readingKm'] ?? $payload['reading_km'] ?? -1);
		if ($km < 0) {
			throw new ValidationException('ODOMETER_REQUIRED');
		}
		$date = trim((string)($payload['readingDate'] ?? $payload['reading_date'] ?? gmdate('Y-m-d')));
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			throw new ValidationException('DATE_INVALID');
		}
		$src = trim((string)($payload['source'] ?? 'manual_driver_entry'));
		if (!in_array($src, ['checkout_log', 'checkin_log', 'manual_driver_entry', 'admin_entry'], true)) {
			throw new ValidationException('SOURCE_INVALID');
		}
		if ($src === 'admin_entry') {
			$this->access->requireFleetAdminOrManager($by);
		}
		$now = gmdate('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('mc_odometer_readings')->values([
			'vehicle_id' => $ins->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT),
			'driver_user_id' => $ins->createNamedParameter($by),
			'reading_km' => $ins->createNamedParameter($km, IQueryBuilder::PARAM_INT),
			'reading_date' => $ins->createNamedParameter($date),
			'source' => $ins->createNamedParameter($src),
			'created_at' => $ins->createNamedParameter($now),
		]);
		$ins->executeStatement();
		$id = (int)$this->db->lastInsertId('mc_odometer_readings');
		$this->audit->log('odometer_reading', $id, 'create', $by, ['vehicle_id' => $vehicleId]);
		return ['id' => $id, 'vehicle_id' => $vehicleId, 'reading_km' => $km, 'reading_date' => $date];
	}
}
