<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * §4.11 + §7.2 — Read-only reports. Auditor and manager-facing views.
 *
 * Reports are pre-aggregated server-side so the browser only receives
 * the final rows it needs to render — no raw cost amounts get exposed
 * to driver / workshop users via a leaky JSON endpoint.
 */
class ReportService
{
	public function __construct(
		private IDBConnection $db,
		private CostService $costs,
		private DriverService $drivers,
		private LineManagerService $lineManagers,
	) {
	}

	/**
	 * §4.11 driver-compliance — list of all drivers with their licence
	 * + instruction state. Recomputes the cached `compliance_status`
	 * column first so the report reflects today's situation.
	 *
	 * @param list<string>|null $scopeDriverUserIds When set, restrict to these driver user ids (line manager read scope).
	 * @return list<array<string,mixed>>
	 */
	public function driverCompliance(?array $scopeDriverUserIds = null): array
	{
		$year = (int)gmdate('Y');
		$out = [];
		$driverRows = $this->drivers->list($scopeDriverUserIds);
		foreach ($driverRows as $d) {
			try {
				$this->drivers->recomputeCompliance((int)$d['id']);
			} catch (\Throwable) {
				// ignore — keep listing the others
			}
			$detail = $this->drivers->complianceDetail((int)$d['id']);
			$today = gmdate('Y-m-d');
			$days = null;
			if ($d['licence_expiry_date'] !== null) {
				$days = (int)floor((strtotime($d['licence_expiry_date']) - strtotime($today)) / 86400);
			}
			$out[] = [
				'driverProfileId' => (int)$d['id'],
				'userId' => $d['user_id'],
				'licenceStatus' => $d['licence_status'],
				'licenceClasses' => $d['licence_classes'],
				'licenceExpiryDate' => $d['licence_expiry_date'],
				'daysToExpiry' => $days,
				'complianceStatus' => $d['compliance_status'],
				'currentYearInstruction' => (bool)$detail['currentYearInstructionComplete'],
				'verificationCount' => count($detail['verifications']),
				'instructionYears' => array_map(fn ($i) => $i['year'], $detail['instructions']),
				'currentYear' => $year,
			];
		}
		return $out;
	}

	/**
	 * §4.11 vehicle utilisation — for a given vehicle and date range,
	 * compute: number of bookings, total trip distance (from check-out
	 * vs check-in odometer pairs), utilisation % (booked hours over
	 * total hours).
	 *
	 * @return array<string,mixed>
	 */
	/**
	 * @return list<array<string,mixed>>|array<string,mixed>
	 *         - List of per-vehicle utilisation summaries when $vehicleId is 0 / null.
	 *         - Single vehicle drill-down (with bookings list) otherwise.
	 */
	public function vehicleUtilisation(int $vehicleId, string $from, string $to, ?array $scopeDriverUserIds = null): array
	{
		if ($vehicleId === 0) {
			return $this->vehicleUtilisationFleet($from, $to, $scopeDriverUserIds);
		}
		$fromTs = strtotime($from . ' 00:00:00 UTC');
		$toTs = strtotime($to . ' 23:59:59 UTC');
		if ($fromTs === false || $toTs === false || $toTs <= $fromTs) {
			return ['vehicleId' => $vehicleId, 'from' => $from, 'to' => $to, 'totalBookings' => 0, 'totalDistanceKm' => 0, 'utilisationPercent' => 0, 'bookings' => []];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'driver_user_id', 'start_datetime', 'end_datetime', 'status', 'purpose')
			->from('mc_bookings')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['approved', 'active', 'completed'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lte('start_datetime', $qb->createNamedParameter(gmdate('Y-m-d H:i:s', $toTs))))
			->andWhere($qb->expr()->gte('end_datetime', $qb->createNamedParameter(gmdate('Y-m-d H:i:s', $fromTs))));
		if ($scopeDriverUserIds !== null && $scopeDriverUserIds !== []) {
			$scope = array_values(array_filter($scopeDriverUserIds, static fn ($id) => is_string($id) && $id !== ''));
			if ($scope !== []) {
				$qb->andWhere($qb->expr()->in('driver_user_id', $qb->createNamedParameter($scope, IQueryBuilder::PARAM_STR_ARRAY)));
			}
		}
		$qb->orderBy('start_datetime', 'ASC');
		$res = $qb->executeQuery();
		$bookings = [];
		$totalBookedSeconds = 0;
		while (($r = $res->fetch()) !== false) {
			$bs = strtotime($r['start_datetime'] . ' UTC') ?: $fromTs;
			$be = strtotime($r['end_datetime'] . ' UTC') ?: $toTs;
			$clampStart = max($bs, $fromTs);
			$clampEnd = min($be, $toTs);
			$seconds = max(0, $clampEnd - $clampStart);
			$totalBookedSeconds += $seconds;
			$bookings[] = [
				'id' => (int)$r['id'],
				'driverUserId' => (string)$r['driver_user_id'],
				'startDatetime' => (string)$r['start_datetime'],
				'endDatetime' => (string)$r['end_datetime'],
				'status' => (string)$r['status'],
				'purpose' => (string)$r['purpose'],
			];
		}
		$res->closeCursor();

		$distance = $this->totalDistance($vehicleId, gmdate('Y-m-d H:i:s', $fromTs), gmdate('Y-m-d H:i:s', $toTs));
		$totalWindowSeconds = $toTs - $fromTs;
		$utilisation = $totalWindowSeconds > 0
			? round(($totalBookedSeconds / $totalWindowSeconds) * 100, 2)
			: 0.0;

		return [
			'vehicleId' => $vehicleId,
			'from' => $from,
			'to' => $to,
			'totalBookings' => count($bookings),
			'totalDistanceKm' => $distance,
			'utilisationPercent' => $utilisation,
			'bookings' => $bookings,
		];
	}

	/**
	 * Fleet-wide utilisation summary: one row per active vehicle.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function vehicleUtilisationFleet(string $from, string $to, ?array $scopeDriverUserIds = null): array
	{
		$fromTs = strtotime($from . ' 00:00:00 UTC');
		$toTs = strtotime($to . ' 23:59:59 UTC');
		if ($fromTs === false || $toTs === false || $toTs <= $fromTs) {
			return [];
		}
		if ($scopeDriverUserIds !== null) {
			$scope = array_values(array_filter($scopeDriverUserIds, static fn ($id) => is_string($id) && $id !== ''));
			if ($scope === []) {
				return [];
			}
			$vehicleIds = $this->lineManagers->listVehicleIdsFromBookingsForDrivers($scope);
			if ($vehicleIds === []) {
				return [];
			}
			$out = [];
			$windowSeconds = max(1, $toTs - $fromTs);
			foreach ($vehicleIds as $vid) {
				$vrow = $this->fetchVehicleName($vid);
				if ($vrow === null) {
					continue;
				}
				$one = $this->vehicleUtilisation($vid, $from, $to, $scopeDriverUserIds);
				$bookings = $one['bookings'] ?? [];
				$durationSeconds = 0;
				foreach ($bookings as $b) {
					$bs = strtotime(($b['startDatetime'] ?? '') . ' UTC') ?: $fromTs;
					$be = strtotime(($b['endDatetime'] ?? '') . ' UTC') ?: $toTs;
					$durationSeconds += max(0, min($be, $toTs) - max($bs, $fromTs));
				}
				$out[] = [
					'vehicleId' => $vrow['id'],
					'internalName' => $vrow['name'],
					'bookingsCount' => (int)($one['totalBookings'] ?? 0),
					'totalDistanceKm' => (int)($one['totalDistanceKm'] ?? 0),
					'totalDurationHours' => (int) round($durationSeconds / 3600),
					'utilisationPercent' => round(($durationSeconds / $windowSeconds) * 100, 2),
				];
			}
			usort($out, fn ($a, $b) => strcmp((string)$a['internalName'], (string)$b['internalName']));
			return $out;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'internal_name')->from('mc_vehicles')
			->where($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->orderBy('internal_name', 'ASC');
		$vehicles = [];
		$res = $qb->executeQuery();
		while (($r = $res->fetch()) !== false) {
			$vehicles[] = ['id' => (int)$r['id'], 'name' => (string)$r['internal_name']];
		}
		$res->closeCursor();
		$out = [];
		$windowSeconds = max(1, $toTs - $fromTs);
		foreach ($vehicles as $v) {
			$one = $this->vehicleUtilisation($v['id'], $from, $to);
			$bookings = $one['bookings'] ?? [];
			$durationSeconds = 0;
			foreach ($bookings as $b) {
				$bs = strtotime(($b['startDatetime'] ?? '') . ' UTC') ?: $fromTs;
				$be = strtotime(($b['endDatetime'] ?? '') . ' UTC') ?: $toTs;
				$durationSeconds += max(0, min($be, $toTs) - max($bs, $fromTs));
			}
			$out[] = [
				'vehicleId' => $v['id'],
				'internalName' => $v['name'],
				'bookingsCount' => (int)($one['totalBookings'] ?? 0),
				'totalDistanceKm' => (int)($one['totalDistanceKm'] ?? 0),
				'totalDurationHours' => (int) round($durationSeconds / 3600),
				'utilisationPercent' => round(($durationSeconds / $windowSeconds) * 100, 2),
			];
		}
		return $out;
	}

	private function totalDistance(int $vehicleId, string $fromDt, string $toDt): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('l.booking_id', 'l.event_type', 'l.odometer_km', 'l.recorded_at')
			->from('mc_checkout_logs', 'l')
			->innerJoin('l', 'mc_bookings', 'b', $qb->expr()->eq('b.id', 'l.booking_id'))
			->where($qb->expr()->eq('b.vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('l.recorded_at', $qb->createNamedParameter($fromDt)))
			->andWhere($qb->expr()->lte('l.recorded_at', $qb->createNamedParameter($toDt)))
			->orderBy('l.booking_id')
			->addOrderBy('l.recorded_at', 'ASC');
		$res = $qb->executeQuery();
		$grouped = [];
		while (($r = $res->fetch()) !== false) {
			$grouped[(int)$r['booking_id']][(string)$r['event_type']] = (int)$r['odometer_km'];
		}
		$res->closeCursor();
		$total = 0;
		foreach ($grouped as $rows) {
			if (isset($rows['checkout'], $rows['checkin'])) {
				$total += max(0, $rows['checkin'] - $rows['checkout']);
			}
		}
		return $total;
	}

	/** @return array{id:int,name:string}|null */
	private function fetchVehicleName(int $vehicleId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'internal_name')->from('mc_vehicles')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$r = $qb->executeQuery()->fetch();
		if (!$r) {
			return null;
		}
		return ['id' => (int)$r['id'], 'name' => (string)$r['internal_name']];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function costsReport(?string $from, ?string $to, ?int $vehicleId, ?array $scopeVehicleIds = null): array
	{
		return $this->costs->summary($from, $to, $vehicleId, $scopeVehicleIds);
	}

	/** @return list<array<string,mixed>> */
	public function damageReport(?string $from, ?string $to, ?int $vehicleId, ?array $scopeDriverUserIds = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('dr.*')->from('mc_damage_reports', 'dr')->orderBy('dr.discovery_datetime', 'DESC');
		if ($from !== null && $from !== '') {
			$qb->andWhere($qb->expr()->gte('dr.discovery_datetime', $qb->createNamedParameter($from . ' 00:00:00')));
		}
		if ($to !== null && $to !== '') {
			$qb->andWhere($qb->expr()->lte('dr.discovery_datetime', $qb->createNamedParameter($to . ' 23:59:59')));
		}
		if ($vehicleId !== null) {
			$qb->andWhere($qb->expr()->eq('dr.vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
		}
		if ($scopeDriverUserIds !== null) {
			$scope = array_values(array_filter($scopeDriverUserIds, static fn ($id) => is_string($id) && $id !== ''));
			if ($scope === []) {
				return [];
			}
			$qb->leftJoin('dr', 'mc_bookings', 'dmg_rpt_booking', $qb->expr()->eq('dmg_rpt_booking.id', 'dr.booking_id'));
			$param = $qb->createNamedParameter($scope, IQueryBuilder::PARAM_STR_ARRAY);
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->in('dr.reported_by_user_id', $param),
				$qb->expr()->in('dmg_rpt_booking.driver_user_id', $param),
			));
		}
		$qb->setMaxResults(2000);
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = [
				'id' => (int)$r['id'],
				'vehicleId' => (int)$r['vehicle_id'],
				'discoveryDatetime' => (string)$r['discovery_datetime'],
				'reportedByUserId' => (string)$r['reported_by_user_id'],
				'severity' => (string)$r['severity'],
				'zone' => (string)$r['zone'],
				'status' => (string)$r['status'],
				'description' => mb_substr((string)$r['description'], 0, 200),
			];
		}
		$res->closeCursor();
		return $out;
	}

	/** @return list<array<string,mixed>> */
	public function bookingsReport(?string $from, ?string $to, ?int $vehicleId, ?string $driverUserId, ?array $scopeDriverUserIds = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_bookings')->orderBy('start_datetime', 'DESC');
		if ($from !== null && $from !== '') {
			$qb->andWhere($qb->expr()->gte('end_datetime', $qb->createNamedParameter($from . ' 00:00:00')));
		}
		if ($to !== null && $to !== '') {
			$qb->andWhere($qb->expr()->lte('start_datetime', $qb->createNamedParameter($to . ' 23:59:59')));
		}
		if ($vehicleId !== null) {
			$qb->andWhere($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
		}
		if ($driverUserId !== null && $driverUserId !== '') {
			$qb->andWhere($qb->expr()->eq('driver_user_id', $qb->createNamedParameter($driverUserId)));
		}
		if ($scopeDriverUserIds !== null) {
			$scope = array_values(array_filter($scopeDriverUserIds, static fn ($id) => is_string($id) && $id !== ''));
			if ($scope === []) {
				return [];
			}
			$qb->andWhere($qb->expr()->in('driver_user_id', $qb->createNamedParameter($scope, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		$qb->setMaxResults(2000);
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = [
				'id' => (int)$r['id'],
				'vehicleId' => (int)$r['vehicle_id'],
				'driverUserId' => (string)$r['driver_user_id'],
				'startDatetime' => (string)$r['start_datetime'],
				'endDatetime' => (string)$r['end_datetime'],
				'status' => (string)$r['status'],
				'purpose' => $r['purpose'] !== null ? (string)$r['purpose'] : null,
				'expectedDistanceKm' => $r['expected_distance_km'] !== null ? (int)$r['expected_distance_km'] : null,
			];
		}
		$res->closeCursor();
		return $out;
	}

	/** @return list<array<string,mixed>> */
	public function notificationsReport(?string $from, ?string $to, ?array $scopeRecipientUserIds = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_notification_log')->orderBy('sent_at', 'DESC');
		if ($from !== null && $from !== '') {
			$qb->andWhere($qb->expr()->gte('sent_at', $qb->createNamedParameter($from . ' 00:00:00')));
		}
		if ($to !== null && $to !== '') {
			$qb->andWhere($qb->expr()->lte('sent_at', $qb->createNamedParameter($to . ' 23:59:59')));
		}
		if ($scopeRecipientUserIds !== null) {
			$scope = array_values(array_filter($scopeRecipientUserIds, static fn ($id) => is_string($id) && $id !== ''));
			if ($scope === []) {
				return [];
			}
			$qb->andWhere($qb->expr()->in('recipient_user_id', $qb->createNamedParameter($scope, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		$qb->setMaxResults(2000);
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = [
				'id' => (int)$r['id'],
				'notificationType' => (string)$r['notification_type'],
				'recipientUserId' => (string)$r['recipient_user_id'],
				'entityType' => $r['entity_type'] !== null ? (string)$r['entity_type'] : null,
				'entityId' => $r['entity_id'] !== null ? (int)$r['entity_id'] : null,
				'channel' => (string)$r['channel'],
				'sentAt' => (string)$r['sent_at'],
				'status' => (string)$r['status'],
				'errorMessage' => $r['error_message'] !== null ? (string)$r['error_message'] : null,
			];
		}
		$res->closeCursor();
		return $out;
	}
}
