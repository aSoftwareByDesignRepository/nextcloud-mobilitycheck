<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\AppInfo\Application;
use OCA\MobilityCheck\Exception\ForbiddenException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Two-layer access control for MobilityCheck (§2.3 + §2.4).
 *
 *   1. {@see canUseApp()}      — app entry gate; same model as
 *      BudgetCheck / DutyCheck. Decides whether the user may open
 *      the MobilityCheck shell at all.
 *   2. {@see hasRole()} / {@see require*()} — fine-grained role
 *      checks from the §2.2 permission matrix. Every controller
 *      must call the matching `require*()` after the middleware
 *      has run.
 *
 * Roles are stored in `mc_user_roles` (one row per user per role) and
 * `mc_group_roles` (one row per Nextcloud group per role). A user may
 * legitimately combine roles (e.g. driver + fleet manager) — effective
 * permissions are the **union** of individual and group-inherited roles.
 *
 * Directory restriction and delegated app administrators live in
 * `IConfig` app values, mirroring the sibling apps' key names so
 * operator tooling reasons about one pattern across all "Check"
 * apps.
 */
class AccessControlService
{
	public const KEY_APP_ADMINS = 'app_admin_user_ids';
	public const KEY_ACCESS_RESTRICTION = 'access_restriction_enabled';
	public const KEY_ACCESS_ALLOWED_USER_IDS = 'access_allowed_user_ids';
	public const KEY_ACCESS_ALLOWED_GROUP_IDS = 'access_allowed_group_ids';

	public const ROLE_FLEET_ADMIN = 'fleet_admin';
	public const ROLE_LINE_MANAGER = 'line_manager';
	public const ROLE_FLEET_MANAGER = 'fleet_manager';
	public const ROLE_DRIVER = 'driver';
	public const ROLE_WORKSHOP = 'workshop';
	public const ROLE_AUDITOR = 'auditor';

	public const ALL_ROLES = [
		self::ROLE_FLEET_ADMIN,
		self::ROLE_FLEET_MANAGER,
		self::ROLE_LINE_MANAGER,
		self::ROLE_DRIVER,
		self::ROLE_WORKSHOP,
		self::ROLE_AUDITOR,
	];

	/** Roles that may be granted via Nextcloud group assignment (not fleet admin). */
	public const GROUP_ASSIGNABLE_ROLES = [
		self::ROLE_FLEET_MANAGER,
		self::ROLE_LINE_MANAGER,
		self::ROLE_DRIVER,
		self::ROLE_WORKSHOP,
		self::ROLE_AUDITOR,
	];

	/** Roles that grant entry to the standard navigation shell. */
	public const ROLES_FOR_FULL_SHELL = [
		self::ROLE_FLEET_ADMIN,
		self::ROLE_FLEET_MANAGER,
		self::ROLE_LINE_MANAGER,
		self::ROLE_DRIVER,
		self::ROLE_AUDITOR,
	];

	public const DENIAL_RESTRICTION = 'restriction';
	public const DENIAL_NO_APP_ROLE = 'no_app_role';
	public const DENIAL_INSUFFICIENT_ROLE = 'insufficient_role';

	/** @var array<string, bool> */
	private array $groupMembershipCache = [];

	/** @var array<string, list<string>> roles by user id (per-request cache) */
	private array $rolesCache = [];

	/** @var array<string, list<string>> */
	private array $userGroupIdsCache = [];

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private IUserSession $userSession,
	) {
	}

	public function currentUserId(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('not_authenticated');
		}
		return $user->getUID();
	}

	public function isSystemAdmin(string $userId): bool
	{
		return $userId !== '' && $this->groupManager->isAdmin($userId);
	}

	public function isAppAdmin(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		return $this->isSystemAdmin($userId)
			|| in_array($userId, $this->getJsonIdList(self::KEY_APP_ADMINS), true);
	}

	/** @return list<string> */
	public function getAppAdminIds(): array
	{
		return $this->getJsonIdList(self::KEY_APP_ADMINS);
	}

	/** @return list<string> */
	public function getAllowedUserIds(): array
	{
		return $this->getJsonIdList(self::KEY_ACCESS_ALLOWED_USER_IDS);
	}

	/** @return list<string> */
	public function getAllowedGroupIds(): array
	{
		return $this->getJsonIdList(self::KEY_ACCESS_ALLOWED_GROUP_IDS);
	}

	public function isAccessRestrictionEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_ACCESS_RESTRICTION, '0') === '1';
	}

	/**
	 * §2.4 — App entry gate. Deterministic ordering and stable
	 * reason codes so the access-denied template / JSON error
	 * stays predictable.
	 *
	 *  1. Empty user id ⇒ false.
	 *  2. App admin (system admin OR `app_admin_user_ids`) ⇒ true.
	 *  3. Directory restriction on + user not on allow list and
	 *     not in any allow-listed group ⇒ false (`restriction`).
	 *  4. No MobilityCheck role from individual or group assignment ⇒
	 *     false (`no_app_role`).
	 *  5. Otherwise ⇒ true.
	 */
	public function canUseApp(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		if ($this->isAppAdmin($userId)) {
			return true;
		}
		if ($this->isAccessRestrictionEnabled() && !$this->userMatchesAllowList($userId)) {
			return false;
		}
		return $this->getRoles($userId) !== [];
	}

	public function denialReasonWhenCannotUseApp(string $userId): string
	{
		if ($this->isAccessRestrictionEnabled() && !$this->userMatchesAllowList($userId)) {
			return self::DENIAL_RESTRICTION;
		}
		if ($this->getRoles($userId) === []) {
			return self::DENIAL_NO_APP_ROLE;
		}
		return self::DENIAL_INSUFFICIENT_ROLE;
	}

	/**
	 * @return list<string> Role keys for the given user, never null.
	 *                      App admins receive ROLE_FLEET_ADMIN implicitly
	 *                      so per-role helpers like {@see isFleetAdmin()}
	 *                      keep matching after first-install bootstrap.
	 */
	public function getRoles(string $userId): array
	{
		if ($userId === '') {
			return [];
		}
		if (array_key_exists($userId, $this->rolesCache)) {
			return $this->rolesCache[$userId];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('role')
			->from('mc_user_roles')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$roles = [];
		while (($row = $res->fetch()) !== false) {
			$role = (string)($row['role'] ?? '');
			if (in_array($role, self::ALL_ROLES, true) && !in_array($role, $roles, true)) {
				$roles[] = $role;
			}
		}
		$res->closeCursor();
		foreach ($this->groupRolesForUser($userId) as $groupRole) {
			if (!in_array($groupRole, $roles, true)) {
				$roles[] = $groupRole;
			}
		}
		// App / system admins implicitly receive fleet_admin so they can
		// boot a fresh install. They still bypass `canUseApp` in step 2.
		if ($this->isAppAdmin($userId) && !in_array(self::ROLE_FLEET_ADMIN, $roles, true)) {
			$roles[] = self::ROLE_FLEET_ADMIN;
		}
		$this->rolesCache[$userId] = $roles;
		return $roles;
	}

	public function hasRole(string $userId, string $role): bool
	{
		return in_array($role, $this->getRoles($userId), true);
	}

	public function isFleetAdmin(string $userId): bool
	{
		return $this->hasRole($userId, self::ROLE_FLEET_ADMIN);
	}

	public function isFleetManager(string $userId): bool
	{
		return $this->hasRole($userId, self::ROLE_FLEET_MANAGER);
	}

	public function isLineManager(string $userId): bool
	{
		return $this->hasRole($userId, self::ROLE_LINE_MANAGER);
	}

	public function isDriver(string $userId): bool
	{
		return $this->hasRole($userId, self::ROLE_DRIVER);
	}

	public function isWorkshop(string $userId): bool
	{
		return $this->hasRole($userId, self::ROLE_WORKSHOP);
	}

	public function isAuditor(string $userId): bool
	{
		return $this->hasRole($userId, self::ROLE_AUDITOR);
	}

	public function isFleetAdminOrManager(string $userId): bool
	{
		return $this->isFleetAdmin($userId) || $this->isFleetManager($userId);
	}

	public function requireFleetAdmin(string $userId): void
	{
		if (!$this->isFleetAdmin($userId)) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
	}

	public function requireFleetAdminOrManager(string $userId): void
	{
		if (!$this->isFleetAdminOrManager($userId)) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
	}

	/**
	 * §2.2 — Fleet booking operations that delegated app administrators
	 * may perform when they are not fleet-role users (e.g. line-manager
	 * step override during bootstrap).
	 */
	public function isFleetAdminOrManagerOrAppAdmin(string $userId): bool
	{
		return $this->isFleetAdminOrManager($userId) || $this->isAppAdmin($userId);
	}

	public function requireFleetAdminOrManagerOrAppAdmin(string $userId): void
	{
		if (!$this->isFleetAdminOrManagerOrAppAdmin($userId)) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
	}

	public function requireAppAdmin(string $userId): void
	{
		if (!$this->isAppAdmin($userId)) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
	}

	public function requireDriver(string $userId): void
	{
		if (!$this->isDriver($userId) && !$this->isFleetAdminOrManager($userId)) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
	}

	/**
	 * §4.6 — REST entry for vehicle handover (check-out / check-in). Matches
	 * {@see \OCA\MobilityCheck\Service\BookingService::checkout} /
	 * {@see \OCA\MobilityCheck\Service\BookingService::checkin}: the booking
	 * owner, any fleet manager or fleet administrator, or a delegated MobilityCheck
	 * app administrator. Line managers and auditors use read-only booking views.
	 */
	public function requireBookingHandoverApiAccess(string $userId): void
	{
		if (!$this->isDriver($userId) && !$this->isFleetAdminOrManager($userId) && !$this->isAppAdmin($userId)) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
	}

	public function requireAuditorOrManagerOrAdmin(string $userId): void
	{
		if (!$this->isAuditor($userId) && !$this->isFleetAdminOrManager($userId) && !$this->isLineManager($userId)) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
	}

	/**
	 * §2.2 — Read-only fleet operations data (drivers, compliance listings, costs)
	 * for fleet roles, auditors, and line managers (scoped in services/controllers).
	 */
	public function requireFleetOperationsRead(string $userId): void
	{
		if (!$this->isFleetAdminOrManager($userId) && !$this->isAuditor($userId) && !$this->isLineManager($userId)) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
	}

	public function requireAnyAppRole(string $userId): void
	{
		if ($this->getRoles($userId) === []) {
			throw new ForbiddenException('NO_APP_ROLE');
		}
	}

	/**
	 * Workshop users see a deliberately reduced shell (repair list only).
	 * Use this when a controller must reject workshop-only users from
	 * non-workshop routes.
	 */
	public function requireNotWorkshopOnly(string $userId): void
	{
		$roles = $this->getRoles($userId);
		if (count($roles) === 1 && $roles[0] === self::ROLE_WORKSHOP) {
			throw new ForbiddenException('WORKSHOP_ONLY');
		}
	}

	public function isWorkshopOnly(string $userId): bool
	{
		$roles = $this->getRoles($userId);
		return count($roles) === 1 && $roles[0] === self::ROLE_WORKSHOP;
	}

	/**
	 * Return Nextcloud user ids that hold the given MobilityCheck role.
	 * Used by {@see NotificationService} to fan out to fleet managers.
	 *
	 * @return list<string>
	 */
	public function userIdsWithRole(string $role): array
	{
		if (!in_array($role, self::ALL_ROLES, true)) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')->from('mc_user_roles')
			->where($qb->expr()->eq('role', $qb->createNamedParameter($role)));
		$res = $qb->executeQuery();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$uid = (string)$r['user_id'];
			if ($uid !== '' && !in_array($uid, $out, true)) {
				$out[] = $uid;
			}
		}
		$res->closeCursor();
		return $out;
	}

	/**
	 * Recipients for "manager/admin" fan-outs (booking approval, damage).
	 * Includes app admins (system + delegated) so they always see critical
	 * notifications during bootstrap of a fresh install.
	 *
	 * @return list<string>
	 */
	public function fleetManagerRecipients(): array
	{
		$ids = array_unique(array_merge(
			$this->userIdsWithRole(self::ROLE_FLEET_ADMIN),
			$this->userIdsWithRole(self::ROLE_FLEET_MANAGER),
			$this->getAppAdminIds(),
		));
		return array_values(array_filter($ids, fn ($id) => is_string($id) && $id !== ''));
	}

	/**
	 * @return array{
	 *   appAdminUserIds:list<string>,
	 *   accessRestrictionEnabled:bool,
	 *   allowedUserIds:list<string>,
	 *   allowedGroupIds:list<string>
	 * }
	 */
	public function appPolicy(): array
	{
		return [
			'appAdminUserIds' => $this->getAppAdminIds(),
			'accessRestrictionEnabled' => $this->isAccessRestrictionEnabled(),
			'allowedUserIds' => $this->getAllowedUserIds(),
			'allowedGroupIds' => $this->getAllowedGroupIds(),
		];
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{appAdminUserIds:list<string>,accessRestrictionEnabled:bool,allowedUserIds:list<string>,allowedGroupIds:list<string>}
	 */
	public function saveAppPolicy(array $payload): array
	{
		$appAdmins = $this->normaliseUniqueIdList($payload['appAdminUserIds'] ?? []);
		$allowedUsers = $this->normaliseUniqueIdList($payload['allowedUserIds'] ?? []);
		$allowedGroups = $this->normaliseUniqueIdList($payload['allowedGroupIds'] ?? []);
		$restriction = (bool)($payload['accessRestrictionEnabled'] ?? false);

		foreach ($appAdmins as $uid) {
			$this->assertKnownUser($uid, 'INVALID_APP_ADMIN_USER');
		}
		foreach ($allowedUsers as $uid) {
			$this->assertKnownUser($uid, 'INVALID_ALLOWED_USER');
		}
		foreach ($allowedGroups as $gid) {
			if (!$this->groupManager->groupExists($gid)) {
				throw new \InvalidArgumentException('INVALID_ALLOWED_GROUP');
			}
		}
		if ($restriction && $allowedUsers === [] && $allowedGroups === []) {
			throw new \InvalidArgumentException('ACCESS_LIST_REQUIRED');
		}

		$this->config->setAppValue(Application::APP_ID, self::KEY_APP_ADMINS, json_encode($appAdmins, JSON_THROW_ON_ERROR));
		$this->config->setAppValue(Application::APP_ID, self::KEY_ACCESS_ALLOWED_USER_IDS, json_encode($allowedUsers, JSON_THROW_ON_ERROR));
		$this->config->setAppValue(Application::APP_ID, self::KEY_ACCESS_ALLOWED_GROUP_IDS, json_encode($allowedGroups, JSON_THROW_ON_ERROR));
		$this->config->setAppValue(Application::APP_ID, self::KEY_ACCESS_RESTRICTION, $restriction ? '1' : '0');

		$this->rolesCache = [];
		return $this->appPolicy();
	}

	/**
	 * Set the MobilityCheck roles for a user, replacing existing rows.
	 *
	 * @param list<string> $roles
	 */
	public function setUserRoles(string $userId, array $roles): void
	{
		if ($userId === '') {
			throw new \InvalidArgumentException('INVALID_USER_ID');
		}
		$this->assertKnownUser($userId, 'INVALID_USER');
		$valid = [];
		foreach ($roles as $r) {
			$r = (string)$r;
			if (in_array($r, self::ALL_ROLES, true) && !in_array($r, $valid, true)) {
				$valid[] = $r;
			}
		}
		$this->db->beginTransaction();
		try {
			$del = $this->db->getQueryBuilder();
			$del->delete('mc_user_roles')->where($del->expr()->eq('user_id', $del->createNamedParameter($userId)));
			$del->executeStatement();
			foreach ($valid as $role) {
				$ins = $this->db->getQueryBuilder();
				$ins->insert('mc_user_roles')
					->values([
						'user_id' => $ins->createNamedParameter($userId),
						'role' => $ins->createNamedParameter($role),
						'created_at' => $ins->createNamedParameter(gmdate('Y-m-d H:i:s')),
					]);
				$ins->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		unset($this->rolesCache[$userId]);
	}

	/**
	 * Roles inherited from Nextcloud groups via `mc_group_roles`.
	 *
	 * @return list<string>
	 */
	public function groupRolesForUser(string $userId): array
	{
		if ($userId === '') {
			return [];
		}
		$gids = $this->userGroupIds($userId);
		if ($gids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('role')
			->from('mc_group_roles')
			->where($qb->expr()->in('gid', $qb->createNamedParameter($gids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
		$res = $qb->executeQuery();
		$roles = [];
		while (($row = $res->fetch()) !== false) {
			$role = (string)($row['role'] ?? '');
			if (in_array($role, self::GROUP_ASSIGNABLE_ROLES, true) && !in_array($role, $roles, true)) {
				$roles[] = $role;
			}
		}
		$res->closeCursor();
		return $roles;
	}

	/**
	 * @return list<array{gid:string,displayName:string,roles:list<string>}>
	 */
	public function listGroupRoleAssignments(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('gid', 'role')
			->from('mc_group_roles')
			->orderBy('gid', 'ASC')
			->addOrderBy('role', 'ASC');
		$res = $qb->executeQuery();
		/** @var array<string, list<string>> */
		$byGid = [];
		while (($row = $res->fetch()) !== false) {
			$gid = (string)($row['gid'] ?? '');
			$role = (string)($row['role'] ?? '');
			if ($gid === '' || !in_array($role, self::GROUP_ASSIGNABLE_ROLES, true)) {
				continue;
			}
			if (!isset($byGid[$gid])) {
				$byGid[$gid] = [];
			}
			if (!in_array($role, $byGid[$gid], true)) {
				$byGid[$gid][] = $role;
			}
		}
		$res->closeCursor();
		$out = [];
		foreach ($byGid as $gid => $roles) {
			$group = $this->groupManager->get($gid);
			$out[] = [
				'gid' => $gid,
				'displayName' => $group !== null ? $group->getDisplayName() : $gid,
				'roles' => $roles,
			];
		}
		usort($out, static fn (array $a, array $b): int => strcmp($a['gid'], $b['gid']));
		return $out;
	}

	/**
	 * Replace every MobilityCheck group role for the given Nextcloud group.
	 *
	 * @param list<string> $roles
	 */
	public function setGroupRoles(string $gid, array $roles): void
	{
		$gid = trim($gid);
		if ($gid === '') {
			throw new \InvalidArgumentException('INVALID_GROUP_ID');
		}
		if (!$this->groupManager->groupExists($gid)) {
			throw new \InvalidArgumentException('INVALID_GROUP');
		}
		$valid = [];
		foreach ($roles as $r) {
			$r = (string)$r;
			if (in_array($r, self::GROUP_ASSIGNABLE_ROLES, true) && !in_array($r, $valid, true)) {
				$valid[] = $r;
			}
		}
		$this->db->beginTransaction();
		try {
			$del = $this->db->getQueryBuilder();
			$del->delete('mc_group_roles')->where($del->expr()->eq('gid', $del->createNamedParameter($gid)));
			$del->executeStatement();
			foreach ($valid as $role) {
				$ins = $this->db->getQueryBuilder();
				$ins->insert('mc_group_roles')
					->values([
						'gid' => $ins->createNamedParameter($gid),
						'role' => $ins->createNamedParameter($role),
						'created_at' => $ins->createNamedParameter(gmdate('Y-m-d H:i:s')),
					]);
				$ins->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->rolesCache = [];
	}

	/**
	 * Remove group role rows and any directory allow-list entry for a deleted
	 * or de-provisioned Nextcloud group.
	 */
	public function purgeGroup(string $gid): void
	{
		if ($gid === '') {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('mc_group_roles')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
		$qb->executeStatement();

		$allowedGroups = $this->getAllowedGroupIds();
		$filtered = array_values(array_filter(
			$allowedGroups,
			static fn (string $id): bool => $id !== $gid,
		));
		if ($filtered !== $allowedGroups) {
			$this->config->setAppValue(
				Application::APP_ID,
				self::KEY_ACCESS_ALLOWED_GROUP_IDS,
				json_encode($filtered, JSON_THROW_ON_ERROR),
			);
		}
		$this->rolesCache = [];
	}

	private function userGroupIds(string $userId): array
	{
		if (!array_key_exists($userId, $this->userGroupIdsCache)) {
			$user = $userId !== '' ? $this->userManager->get($userId) : null;
			$this->userGroupIdsCache[$userId] = $user === null
				? []
				: array_values($this->groupManager->getUserGroupIds($user));
		}
		return $this->userGroupIdsCache[$userId];
	}

	private function userMatchesAllowList(string $userId): bool
	{
		if (in_array($userId, $this->getAllowedUserIds(), true)) {
			return true;
		}
		foreach ($this->getAllowedGroupIds() as $gid) {
			if ($this->isUserInGroupCached($userId, $gid)) {
				return true;
			}
		}
		return false;
	}

	/** @return list<string> */
	private function getJsonIdList(string $key): array
	{
		$raw = trim((string)$this->config->getAppValue(Application::APP_ID, $key, '[]'));
		if ($raw === '') {
			return [];
		}
		try {
			$decoded = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		if (!is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $entry) {
			if (is_string($entry) && $entry !== '') {
				$out[] = $entry;
			}
		}
		return array_values(array_unique($out));
	}

	private function isUserInGroupCached(string $userId, string $groupId): bool
	{
		$key = $userId . "\0" . $groupId;
		if (!array_key_exists($key, $this->groupMembershipCache)) {
			$this->groupMembershipCache[$key] = $this->groupManager->isInGroup($userId, $groupId);
		}
		return $this->groupMembershipCache[$key];
	}

	private function assertKnownUser(string $uid, string $code): void
	{
		$user = $this->userManager->get($uid);
		if ($user === null) {
			throw new \InvalidArgumentException($code);
		}
		if (method_exists($user, 'isEnabled') && !$user->isEnabled()) {
			throw new \InvalidArgumentException($code);
		}
	}

	/**
	 * @param mixed $value
	 * @return list<string>
	 */
	private function normaliseUniqueIdList(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}
		$ids = [];
		foreach ($value as $entry) {
			$id = trim((string)$entry);
			if ($id !== '') {
				$ids[] = $id;
			}
		}
		return array_values(array_unique($ids));
	}
}
