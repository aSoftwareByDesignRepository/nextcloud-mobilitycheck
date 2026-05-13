<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** §4.2a — Station / depot CRUD (fleet admin + fleet manager write; auditor read). */
class StationService
{
	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private AuditLogService $audit,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function list(string $viewerId, bool $activeOnly = false): array
	{
		$this->access->requireAnyAppRole($viewerId);
		$this->access->requireNotWorkshopOnly($viewerId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_stations')->orderBy('code', 'ASC');
		if ($activeOnly) {
			$qb->where($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		}
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$out[] = $this->hydrate($r);
		}
		$res->closeCursor();
		return $out;
	}

	public function get(int $id, string $viewerId): array
	{
		$this->access->requireAnyAppRole($viewerId);
		$this->access->requireNotWorkshopOnly($viewerId);
		$row = $this->fetchRow($id);
		return $this->hydrate($row);
	}

	/** @param array<string,mixed> $payload */
	public function create(array $payload, string $performedBy): array
	{
		$this->access->requireFleetAdminOrManager($performedBy);
		$code = strtoupper(trim((string)($payload['code'] ?? '')));
		$name = trim((string)($payload['name'] ?? ''));
		if ($code === '' || strlen($code) > 40) {
			throw new ValidationException('STATION_CODE_INVALID', 'code');
		}
		if ($name === '' || mb_strlen($name) > 120) {
			throw new ValidationException('STATION_NAME_REQUIRED', 'name');
		}
		$tz = trim((string)($payload['timezone'] ?? 'Europe/Berlin'));
		if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
			throw new ValidationException('TIMEZONE_INVALID', 'timezone');
		}
		$now = gmdate('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('mc_stations')->values([
			'code' => $ins->createNamedParameter($code),
			'name' => $ins->createNamedParameter($name),
			'address_line_1' => $ins->createNamedParameter($this->str($payload['addressLine1'] ?? $payload['address_line_1'] ?? null, 120)),
			'address_line_2' => $ins->createNamedParameter($this->str($payload['addressLine2'] ?? $payload['address_line_2'] ?? null, 120)),
			'postal_code' => $ins->createNamedParameter($this->str($payload['postalCode'] ?? $payload['postal_code'] ?? null, 20)),
			'city' => $ins->createNamedParameter($this->str($payload['city'] ?? null, 80)),
			'country_code' => $ins->createNamedParameter(strtoupper(substr((string)($payload['countryCode'] ?? $payload['country_code'] ?? 'DE'), 0, 2))),
			'timezone' => $ins->createNamedParameter($tz),
			'latitude' => $this->nullableDecimalParam($ins, $payload['latitude'] ?? null),
			'longitude' => $this->nullableDecimalParam($ins, $payload['longitude'] ?? null),
			'default_language' => $ins->createNamedParameter(substr((string)($payload['defaultLanguage'] ?? $payload['default_language'] ?? 'de'), 0, 2)),
			'is_active' => $ins->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'notes' => $ins->createNamedParameter($this->str($payload['notes'] ?? null, 5000)),
			'notification_recipient_user_ids' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			'created_by_user_id' => $ins->createNamedParameter($performedBy),
			'created_at' => $ins->createNamedParameter($now),
			'updated_at' => $ins->createNamedParameter($now),
		]);
		try {
			$ins->executeStatement();
		} catch (\Throwable) {
			throw new ValidationException('STATION_CODE_DUPLICATE', 'code');
		}
		$id = (int)$this->db->lastInsertId('mc_stations');
		$this->audit->log('station', $id, 'create', $performedBy, ['code' => $code]);
		return $this->get($id, $performedBy);
	}

	/** @param array<string,mixed> $payload */
	public function update(int $id, array $payload, string $performedBy): array
	{
		$this->access->requireFleetAdminOrManager($performedBy);
		$this->fetchRow($id);
		$now = gmdate('Y-m-d H:i:s');
		$upd = $this->db->getQueryBuilder();
		$upd->update('mc_stations')->set('updated_at', $upd->createNamedParameter($now));
		if (isset($payload['name']) || isset($payload['Name'])) {
			$n = trim((string)($payload['name'] ?? $payload['Name'] ?? ''));
			if ($n === '') {
				throw new ValidationException('STATION_NAME_REQUIRED', 'name');
			}
			$upd->set('name', $upd->createNamedParameter($n));
		}
		foreach ([
			['address_line_1', $payload['addressLine1'] ?? $payload['address_line_1'] ?? null, 120],
			['timezone', $payload['timezone'] ?? null, 64],
		] as [$col, $val, $len]) {
			if ($val !== null) {
				$s = $this->str($val, $len);
				$upd->set($col, $upd->createNamedParameter($s));
			}
		}
		$upd->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$upd->executeStatement();
		$this->audit->log('station', $id, 'update', $performedBy, []);
		return $this->get($id, $performedBy);
	}

	public function deactivate(int $id, string $performedBy): array
	{
		$this->access->requireFleetAdmin($performedBy);
		$this->fetchRow($id);
		$now = gmdate('Y-m-d H:i:s');
		$upd = $this->db->getQueryBuilder();
		$upd->update('mc_stations')
			->set('is_active', $upd->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('updated_at', $upd->createNamedParameter($now))
			->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$upd->executeStatement();
		$this->audit->log('station', $id, 'deactivate', $performedBy, []);
		return $this->get($id, $performedBy);
	}

	/** @return array<string,mixed> */
	private function fetchRow(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_stations')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if (!$row) {
			throw new NotFoundException('STATION_NOT_FOUND');
		}
		return $row;
	}

	private function nullableDecimalParam(IQueryBuilder $ins, mixed $v)
	{
		if ($v === null || $v === '') {
			return $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL);
		}
		return $ins->createNamedParameter((string)$v);
	}

	private function str(mixed $v, int $max): ?string
	{
		if ($v === null) {
			return null;
		}
		$s = trim((string)$v);
		if ($s === '') {
			return null;
		}
		return mb_substr($s, 0, $max);
	}

	/** @param array<string,mixed> $r */
	private function hydrate(array $r): array
	{
		return [
			'id' => (int)$r['id'],
			'code' => (string)$r['code'],
			'name' => (string)$r['name'],
			'addressLine1' => $r['address_line_1'] !== null ? (string)$r['address_line_1'] : null,
			'addressLine2' => $r['address_line_2'] !== null ? (string)$r['address_line_2'] : null,
			'postalCode' => $r['postal_code'] !== null ? (string)$r['postal_code'] : null,
			'city' => $r['city'] !== null ? (string)$r['city'] : null,
			'countryCode' => (string)$r['country_code'],
			'timezone' => (string)$r['timezone'],
			'latitude' => $r['latitude'] !== null ? (string)$r['latitude'] : null,
			'longitude' => $r['longitude'] !== null ? (string)$r['longitude'] : null,
			'defaultLanguage' => (string)$r['default_language'],
			'isActive' => (int)$r['is_active'] === 1,
			'notes' => $r['notes'] !== null ? (string)$r['notes'] : null,
			'createdAt' => (string)$r['created_at'],
			'updatedAt' => (string)$r['updated_at'],
		];
	}
}
