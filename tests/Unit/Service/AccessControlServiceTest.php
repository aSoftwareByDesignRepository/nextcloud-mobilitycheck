<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Service;

use OCA\MobilityCheck\Service\AccessControlService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * {@see AccessControlService::getRoles()} merges individual `mc_user_roles`
 * rows with roles inherited from `mc_group_roles` via the user's groups.
 */
final class AccessControlServiceTest extends TestCase
{
	private function service(
		IDBConnection $db,
		?IGroupManager $groupManager = null,
		?IUserManager $userManager = null,
	): AccessControlService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(function (string $appId, string $key, string $default): string {
			if ($key === AccessControlService::KEY_ACCESS_RESTRICTION) {
				return '0';
			}
			if (str_ends_with($key, '_user_ids') || str_ends_with($key, '_group_ids')) {
				return '[]';
			}
			return $default;
		});

		return new AccessControlService(
			$db,
			$config,
			$groupManager ?? $this->createMock(IGroupManager::class),
			$userManager ?? $this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class),
		);
	}

	private function queryBuilderWithResults(IResult ...$results): IQueryBuilder
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('expr');
		$expr->method('in')->willReturn('expr');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('executeQuery')->willReturnOnConsecutiveCalls(...$results);
		return $qb;
	}

	private function resultWithRows(array $rows): IResult
	{
		$r = $this->createMock(IResult::class);
		$copy = $rows;
		$r->method('fetch')->willReturnCallback(static function () use (&$copy) {
			if ($copy === []) {
				return false;
			}
			return array_shift($copy);
		});
		$r->method('closeCursor')->willReturn(true);
		return $r;
	}

	public function testGetRolesMergesIndividualAndGroupInheritedRoles(): void
	{
		$user = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('alice')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('getUserGroupIds')->with($user)->willReturn(['drivers', 'auditors']);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->queryBuilderWithResults($this->resultWithRows([['role' => 'driver']])),
			$this->queryBuilderWithResults($this->resultWithRows([
				['role' => 'fleet_manager'],
				['role' => 'auditor'],
			])),
		);

		$access = $this->service($db, $groupManager, $userManager);
		$roles = $access->getRoles('alice');

		self::assertSame(['driver', 'fleet_manager', 'auditor'], $roles);
	}

	public function testGetRolesDedupesOverlappingIndividualAndGroupRoles(): void
	{
		$user = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('bob')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('getUserGroupIds')->with($user)->willReturn(['fleet']);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->queryBuilderWithResults($this->resultWithRows([['role' => 'auditor']])),
			$this->queryBuilderWithResults($this->resultWithRows([['role' => 'auditor']])),
		);

		$access = $this->service($db, $groupManager, $userManager);
		self::assertSame(['auditor'], $access->getRoles('bob'));
	}
}
