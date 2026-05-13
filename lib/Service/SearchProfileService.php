<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Appendix A5.3 — saved smart-search filter presets (session substitute). */
class SearchProfileService
{
	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function list(string $userId): array
	{
		$this->access->requireDriver($userId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_search_profiles')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('name', 'ASC');
		$out = [];
		$res = $qb->executeQuery();
		while (($r = $res->fetch()) !== false) {
			$out[] = $this->hydrate($r);
		}
		$res->closeCursor();
		return $out;
	}

	/** @param array<string,mixed> $payload */
	public function create(string $userId, array $payload): array
	{
		$this->access->requireDriver($userId);
		$name = trim((string)($payload['name'] ?? ''));
		if ($name === '') {
			throw new ValidationException('NAME_REQUIRED');
		}
		$req = $payload['requirements'] ?? $payload['requirements_json'] ?? [];
		if (!is_array($req)) {
			throw new ValidationException('REQUIREMENTS_INVALID');
		}
		$json = json_encode($req, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
		$now = gmdate('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('mc_search_profiles')->values([
			'user_id' => $ins->createNamedParameter($userId),
			'name' => $ins->createNamedParameter(mb_substr($name, 0, 120)),
			'requirements_json' => $ins->createNamedParameter($json),
			'created_at' => $ins->createNamedParameter($now),
			'updated_at' => $ins->createNamedParameter($now),
		]);
		$ins->executeStatement();
		$id = (int)$this->db->lastInsertId('mc_search_profiles');
		return $this->get($id, $userId);
	}

	/** @param array<string,mixed> $payload */
	public function update(int $id, string $userId, array $payload): array
	{
		$row = $this->fetchRow($id);
		if (($row['user_id'] ?? '') !== $userId) {
			throw new ForbiddenException('CANNOT_EDIT_PROFILE');
		}
		$name = trim((string)($payload['name'] ?? $row['name']));
		$req = $payload['requirements'] ?? null;
		$json = $row['requirements_json'];
		if (is_array($req)) {
			$json = json_encode($req, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('mc_search_profiles')
			->set('name', $qb->createNamedParameter(mb_substr($name, 0, 120)))
			->set('requirements_json', $qb->createNamedParameter($json))
			->set('updated_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		return $this->get($id, $userId);
	}

	public function delete(int $id, string $userId): void
	{
		$row = $this->fetchRow($id);
		if (($row['user_id'] ?? '') !== $userId) {
			throw new ForbiddenException('CANNOT_DELETE_PROFILE');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('mc_search_profiles')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function get(int $id, string $userId): array
	{
		$row = $this->fetchRow($id);
		if (($row['user_id'] ?? '') !== $userId) {
			throw new ForbiddenException('CANNOT_VIEW_PROFILE');
		}
		return $this->hydrate($row);
	}

	/** @return array<string,mixed> */
	private function fetchRow(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_search_profiles')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$r = $qb->executeQuery()->fetch();
		if (!$r) {
			throw new NotFoundException('SEARCH_PROFILE_NOT_FOUND');
		}
		return $r;
	}

	/** @param array<string,mixed> $row */
	private function hydrate(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'user_id' => (string)$row['user_id'],
			'name' => (string)$row['name'],
			'requirements' => json_decode((string)$row['requirements_json'], true, 512, JSON_THROW_ON_ERROR),
			'created_at' => (string)$row['created_at'],
			'updated_at' => (string)$row['updated_at'],
		];
	}
}
