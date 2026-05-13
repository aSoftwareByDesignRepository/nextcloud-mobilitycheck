<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Service;

use OCA\MobilityCheck\Exception\RateLimitedException;
use OCA\MobilityCheck\Service\RateLimitService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit-coverage for {@see RateLimitService}. The real DB is mocked so we
 * exercise the sliding-window arithmetic deterministically.
 */
final class RateLimitServiceTest extends TestCase
{
	public function testUnknownBucketLogsAndPermits(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');
		$req = $this->createMock(IRequest::class);
		$time = $this->createMock(ITimeFactory::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$svc = new RateLimitService($db, $req, $time, $logger);
		$svc->consume('unknown_bucket', 'alice');
	}

	public function testKnownBucketQuotaShape(): void
	{
		$this->assertSame(['quota' => 30, 'windowSeconds' => 60], RateLimitService::bucketQuota(RateLimitService::BUCKET_QR_SCAN));
		$this->assertSame(['quota' => 60, 'windowSeconds' => 60], RateLimitService::bucketQuota(RateLimitService::BUCKET_VEHICLE_SEARCH));
		$this->assertSame(['quota' => 20, 'windowSeconds' => 60], RateLimitService::bucketQuota(RateLimitService::BUCKET_UPLOAD));
		$this->assertSame(['quota' => 0, 'windowSeconds' => 0], RateLimitService::bucketQuota('nope'));
	}

	public function testQuotaExceededThrowsWithRetryAfter(): void
	{
		$nowEpoch = 1_700_000_000;

		$db = $this->createMock(IDBConnection::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $c, $p) => $c . '=:p');
		$expr->method('gte')->willReturnCallback(static fn (string $c, $p) => $c . '>=:p');
		$expr->method('lt')->willReturnCallback(static fn (string $c, $p) => $c . '<:p');

		// First call: SELECT count + oldest → returns 30 hits, oldest 30s ago.
		$qbSelect = $this->createMock(IQueryBuilder::class);
		$qbSelect->method('expr')->willReturn($expr);
		$qbSelect->method('createNamedParameter')->willReturnArgument(0);
		$qbSelect->method('createFunction')->willReturnCallback(function ($expression) {
			$fn = $this->createMock(IQueryFunction::class);
			$fn->method('__toString')->willReturn((string)$expression);
			return $fn;
		});
		$qbSelect->method('select')->willReturnSelf();
		$qbSelect->method('from')->willReturnSelf();
		$qbSelect->method('where')->willReturnSelf();
		$qbSelect->method('andWhere')->willReturnSelf();
		$stmt = $this->createMock(\OCP\DB\IResult::class);
		$stmt->method('fetch')->willReturn([
			'cnt' => 30,
			'oldest' => gmdate('Y-m-d H:i:s', $nowEpoch - 30),
		]);
		$qbSelect->method('executeQuery')->willReturn($stmt);

		$db->method('getQueryBuilder')->willReturn($qbSelect);

		$req = $this->createMock(IRequest::class);
		$req->method('getRemoteAddress')->willReturn('203.0.113.7');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($nowEpoch);

		$logger = $this->createMock(LoggerInterface::class);

		$svc = new RateLimitService($db, $req, $time, $logger);

		try {
			$svc->consume(RateLimitService::BUCKET_QR_SCAN, 'alice');
			$this->fail('Expected RateLimitedException');
		} catch (RateLimitedException $e) {
			$this->assertSame(RateLimitService::BUCKET_QR_SCAN, $e->getBucket());
			$this->assertGreaterThanOrEqual(1, $e->getRetryAfterSeconds());
			$this->assertLessThanOrEqual(60, $e->getRetryAfterSeconds());
		}
	}

	public function testRetryAfterIsNeverZero(): void
	{
		$e = new RateLimitedException('upload', -5);
		$this->assertSame(1, $e->getRetryAfterSeconds());
	}
}
