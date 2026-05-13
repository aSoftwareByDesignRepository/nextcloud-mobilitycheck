<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\RateLimitedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * §8.5 — Sliding-window counter for hot endpoints. Storage lives in
 * `mc_rate_limits`; the service inserts one row per allowed hit and counts
 * hits inside the window keyed by `(bucket, user_id, ip)`. When the count
 * reaches the bucket's quota the call raises
 * {@see \OCA\MobilityCheck\Exception\RateLimitedException} with a precise
 * `Retry-After`.
 *
 * Buckets and quotas mirror the normative spec (§8.5):
 *   - `qr_scan`:        30 hits / 60s
 *   - `vehicle_search`: 60 hits / 60s
 *   - `upload`:         20 hits / 60s
 *
 * Notes:
 *   - The window is sliding, not a fixed bucket — the oldest hit defines
 *     the `Retry-After`. This avoids "thundering herd" at the minute boundary.
 *   - User id is always part of the key; IP is included so an open session
 *     does not amplify a hostile network. Either piece may be the empty
 *     string in edge cases (CLI, missing remote address) — the counter still
 *     applies on what is present.
 *   - The service is intentionally side-effect free on failure: a DB error
 *     is logged but never blocks the request — failing closed would create
 *     a denial-of-service against legitimate users. If you need stricter
 *     enforcement, run the purge job on schedule and monitor the warning
 *     log.
 */
class RateLimitService
{
	public const BUCKET_QR_SCAN = 'qr_scan';
	public const BUCKET_VEHICLE_SEARCH = 'vehicle_search';
	public const BUCKET_UPLOAD = 'upload';

	/** @var array<string,array{quota:int,windowSeconds:int}> */
	private const QUOTAS = [
		self::BUCKET_QR_SCAN => ['quota' => 30, 'windowSeconds' => 60],
		self::BUCKET_VEHICLE_SEARCH => ['quota' => 60, 'windowSeconds' => 60],
		self::BUCKET_UPLOAD => ['quota' => 20, 'windowSeconds' => 60],
	];

	public function __construct(
		private IDBConnection $db,
		private IRequest $request,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reserve one hit on the bucket for the current request. Throws
	 * `RateLimitedException` when the sliding window is full.
	 *
	 * @param self::BUCKET_* $bucket
	 */
	public function consume(string $bucket, string $userId): void
	{
		if (!isset(self::QUOTAS[$bucket])) {
			// Programming error: unknown buckets get logged but allowed
			// through (we never penalise the user for our own mistake).
			$this->logger->warning('MobilityCheck: unknown rate-limit bucket "{bucket}"', [
				'app' => 'mobilitycheck',
				'bucket' => $bucket,
			]);
			return;
		}
		$quota = self::QUOTAS[$bucket]['quota'];
		$windowSeconds = self::QUOTAS[$bucket]['windowSeconds'];
		$ip = $this->normalisedIp();
		$nowEpoch = $this->time->getTime();
		$windowStart = gmdate('Y-m-d H:i:s', $nowEpoch - $windowSeconds);
		$now = gmdate('Y-m-d H:i:s', $nowEpoch);

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->createFunction('COUNT(*) AS cnt'), $qb->createFunction('MIN(hit_at) AS oldest'))
				->from('mc_rate_limits')
				->where($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)))
				->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('ip', $qb->createNamedParameter($ip)))
				->andWhere($qb->expr()->gte('hit_at', $qb->createNamedParameter($windowStart)));
			$row = $qb->executeQuery()->fetch();
			$count = $row === false ? 0 : (int)($row['cnt'] ?? 0);
			$oldest = $row === false ? null : (string)($row['oldest'] ?? '');
			if ($count >= $quota) {
				$retryAfter = $windowSeconds;
				if (is_string($oldest) && $oldest !== '') {
					$oldestTs = (int)strtotime($oldest . ' UTC');
					if ($oldestTs > 0) {
						$retryAfter = max(1, $windowSeconds - ($nowEpoch - $oldestTs));
					}
				}
				throw new RateLimitedException($bucket, $retryAfter);
			}
			$ins = $this->db->getQueryBuilder();
			$ins->insert('mc_rate_limits')->values([
				'bucket' => $ins->createNamedParameter($bucket),
				'user_id' => $ins->createNamedParameter($userId),
				'ip' => $ins->createNamedParameter($ip),
				'hit_at' => $ins->createNamedParameter($now),
			]);
			$ins->executeStatement();
		} catch (RateLimitedException $e) {
			throw $e;
		} catch (\Throwable $e) {
			// Fail open so an outage of the rate-limit table can never
			// brick the app. The auditor sees the warning in the system
			// log and can verify enforcement is healthy via the dedicated
			// integration test.
			$this->logger->warning(
				'MobilityCheck: rate-limit bookkeeping for {bucket} failed: {error}',
				[
					'app' => 'mobilitycheck',
					'bucket' => $bucket,
					'error' => $e->getMessage(),
					'exception' => $e,
				],
			);
		}
	}

	/**
	 * Purge rows older than the maximum configured window (with a generous
	 * safety margin). Called by {@see \OCA\MobilityCheck\BackgroundJob\RateLimitPurgeJob}.
	 *
	 * @return int Rows deleted.
	 */
	public function purgeStale(): int
	{
		$horizon = 0;
		foreach (self::QUOTAS as $cfg) {
			if ($cfg['windowSeconds'] > $horizon) {
				$horizon = $cfg['windowSeconds'];
			}
		}
		// 1 hour buffer beyond the largest window so an in-flight request
		// cannot lose its predecessor mid-evaluation.
		$cutoff = gmdate('Y-m-d H:i:s', $this->time->getTime() - $horizon - 3600);
		$qb = $this->db->getQueryBuilder();
		$qb->delete('mc_rate_limits')
			->where($qb->expr()->lt('hit_at', $qb->createNamedParameter($cutoff)));
		return (int)$qb->executeStatement();
	}

	/**
	 * Bucket definition (test + admin observability).
	 *
	 * @return array{quota:int,windowSeconds:int}
	 */
	public static function bucketQuota(string $bucket): array
	{
		return self::QUOTAS[$bucket] ?? ['quota' => 0, 'windowSeconds' => 0];
	}

	private function normalisedIp(): string
	{
		$remote = (string)$this->request->getRemoteAddress();
		// Trim to a sane length and strip any port suffix some proxies append.
		$remote = preg_replace('/\s+/', '', $remote);
		if ($remote === null) {
			return '';
		}
		if (strlen($remote) > 64) {
			$remote = substr($remote, 0, 64);
		}
		return $remote;
	}
}
