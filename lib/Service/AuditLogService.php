<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCP\IDBConnection;
use OCP\IRequest;

/**
 * Append-only audit log for MobilityCheck (§6.14, §8).
 *
 * Used by every service that mutates a sensitive table. Never write
 * to `mc_audit_log` from a controller — controllers parse HTTP and
 * call one service method; the service decides what to log.
 *
 * IP address is captured from the current request when available
 * because the audit trail must be defensible months later (e.g.
 * "who created this damage report?"). For background jobs the IP
 * is null because there is no request.
 */
class AuditLogService
{
	public function __construct(
		private IDBConnection $db,
		private IRequest $request,
	) {
	}

	/**
	 * @param array<string,mixed> $changes Optional per-field diff;
	 *                                     pass [field => [old,new]].
	 */
	public function log(
		string $entityType,
		int $entityId,
		string $action,
		string $performedByUserId,
		array $changes = [],
		?string $reason = null,
	): void {
		$now = gmdate('Y-m-d H:i:s');
		$ip = $this->safeRemoteAddr();
		if ($changes === []) {
			$this->insertRow($entityType, $entityId, $action, null, null, null, $performedByUserId, $now, $ip, $reason);
			return;
		}
		foreach ($changes as $field => $pair) {
			[$old, $new] = is_array($pair) ? [$pair[0] ?? null, $pair[1] ?? null] : [null, $pair];
			$this->insertRow(
				$entityType,
				$entityId,
				$action,
				(string)$field,
				$old !== null ? self::stringifyValue($old) : null,
				$new !== null ? self::stringifyValue($new) : null,
				$performedByUserId,
				$now,
				$ip,
				$reason,
			);
		}
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function query(?string $from, ?string $to, ?string $entityType, ?string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'entity_type', 'entity_id', 'action', 'field_name', 'old_value', 'new_value', 'performed_by_user_id', 'performed_at', 'ip_address', 'reason')
			->from('mc_audit_log')
			->orderBy('performed_at', 'DESC')
			->setMaxResults(2000);
		if ($from !== null && $from !== '') {
			$qb->andWhere($qb->expr()->gte('performed_at', $qb->createNamedParameter($from)));
		}
		if ($to !== null && $to !== '') {
			$qb->andWhere($qb->expr()->lte('performed_at', $qb->createNamedParameter($to)));
		}
		if ($entityType !== null && $entityType !== '') {
			$qb->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)));
		}
		if ($userId !== null && $userId !== '') {
			$qb->andWhere($qb->expr()->eq('performed_by_user_id', $qb->createNamedParameter($userId)));
		}
		$res = $qb->executeQuery();
		$rows = [];
		while (($r = $res->fetch()) !== false) {
			$rows[] = $r;
		}
		$res->closeCursor();
		return $rows;
	}

	private function insertRow(
		string $entityType,
		int $entityId,
		string $action,
		?string $field,
		?string $old,
		?string $new,
		string $performedBy,
		string $performedAt,
		?string $ip,
		?string $reason,
	): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('mc_audit_log')
			->values([
				'entity_type' => $qb->createNamedParameter($entityType),
				'entity_id' => $qb->createNamedParameter($entityId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'action' => $qb->createNamedParameter($action),
				'field_name' => $qb->createNamedParameter($field),
				'old_value' => $qb->createNamedParameter($old),
				'new_value' => $qb->createNamedParameter($new),
				'performed_by_user_id' => $qb->createNamedParameter($performedBy),
				'performed_at' => $qb->createNamedParameter($performedAt),
				'ip_address' => $qb->createNamedParameter($ip),
				'reason' => $qb->createNamedParameter($reason),
			]);
		$qb->executeStatement();
	}

	private function safeRemoteAddr(): ?string
	{
		try {
			$ip = $this->request->getRemoteAddress();
			if (is_string($ip) && $ip !== '') {
				return substr($ip, 0, 45);
			}
		} catch (\Throwable) {
			// Background jobs and CLI do not have a real request.
		}
		return null;
	}

	private static function stringifyValue(mixed $value): string
	{
		if (is_scalar($value)) {
			return (string)$value;
		}
		if (is_array($value) || is_object($value)) {
			try {
				return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?: '';
			} catch (\Throwable) {
				return '';
			}
		}
		return '';
	}
}
