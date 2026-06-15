<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\AuditLogService;
use OCA\MobilityCheck\Service\GdprErasureService;
use OCA\MobilityCheck\Service\LineManagerService;
use OCA\MobilityCheck\Service\RetentionService;
use OCA\MobilityCheck\Service\SettingsService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;

class AdminController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private AuditLogService $auditLog,
		private SettingsService $settings,
		private LineManagerService $lineManagers,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private GdprErasureService $gdpr,
		private RetentionService $retention,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function users(): DataResponse
	{
		return $this->wrap(function (): array {
			$this->access->requireFleetAdminOrManager($this->access->currentUserId());
			$search = (string)($this->request->getParam('search', '') ?? '');
			$users = method_exists($this->userManager, 'search') ? $this->userManager->search($search, 200, 0) : [];
			$out = [];
			foreach ($users as $user) {
				$id = $user->getUID();
				$out[] = ['id' => $id, 'displayName' => $user->getDisplayName(), 'roles' => $this->access->getRoles($id)];
			}
			return $out;
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function groups(): DataResponse
	{
		return $this->wrap(function (): array {
			$this->access->requireFleetAdminOrManager($this->access->currentUserId());
			$search = (string)($this->request->getParam('search', '') ?? '');
			$groups = $this->groupManager->search($search);
			$roleByGid = [];
			foreach ($this->access->listGroupRoleAssignments() as $assignment) {
				$roleByGid[$assignment['gid']] = $assignment['roles'];
			}
			$out = [];
			foreach ($groups as $group) {
				$gid = $group->getGID();
				$out[] = [
					'id' => $gid,
					'displayName' => $group->getDisplayName(),
					'roles' => $roleByGid[$gid] ?? [],
				];
			}
			return $out;
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function policy(): DataResponse
	{
		return $this->wrap(function (): array {
			$this->access->requireFleetAdmin($this->access->currentUserId());
			return $this->access->appPolicy();
		});
	}

	#[NoAdminRequired]
	public function savePolicy(): DataResponse
	{
		return $this->wrap(function (): array {
			// §2.2 / §7.2 — directory restriction + delegated app admins are
			// **app admin** scope (system admin OR app_admin_user_ids), NOT
			// fleet admin scope. Fleet admins may still manage roles for
			// users who already pass `canUseApp`, but they must never be
			// able to change WHO can open the app or WHO is an app admin.
			$this->access->requireAppAdmin($this->access->currentUserId());
			return $this->access->saveAppPolicy($this->payload());
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function auditLog(): DataResponse
	{
		return $this->wrap(function (): array {
			$this->access->requireFleetAdmin($this->access->currentUserId());
			return $this->auditLog->query(
				$this->nullable('from'),
				$this->nullable('to'),
				$this->nullable('entityType'),
				$this->nullable('userId')
			);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listRoles(): DataResponse
	{
		return $this->wrap(function (): array {
			$this->access->requireFleetAdmin($this->access->currentUserId());
			return [
				'roles' => AccessControlService::ALL_ROLES,
				'appAdmins' => $this->access->getAppAdminIds(),
			];
		});
	}

	#[NoAdminRequired]
	public function setRoles(): DataResponse
	{
		return $this->wrap(function (): array {
			$this->access->requireFleetAdmin($this->access->currentUserId());
			$userId = (string)($this->request->getParam('userId', '') ?? '');
			$roles = $this->request->getParam('roles', []);
			$this->access->setUserRoles($userId, is_array($roles) ? $roles : []);
			return ['userId' => $userId, 'roles' => $this->access->getRoles($userId)];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listGroupRoles(): DataResponse
	{
		return $this->wrap(function (): array {
			$this->access->requireFleetAdmin($this->access->currentUserId());
			return [
				'roles' => AccessControlService::GROUP_ASSIGNABLE_ROLES,
				'assignments' => $this->access->listGroupRoleAssignments(),
			];
		});
	}

	#[NoAdminRequired]
	public function setGroupRoles(): DataResponse
	{
		return $this->wrap(function (): array {
			$this->access->requireFleetAdmin($this->access->currentUserId());
			$payload = $this->payload();
			$groupId = trim((string)($payload['groupId'] ?? $this->request->getParam('groupId', '') ?? ''));
			$roles = $payload['roles'] ?? $this->request->getParam('roles', []);
			$this->access->setGroupRoles($groupId, is_array($roles) ? $roles : []);
			return [
				'groupId' => $groupId,
				'assignments' => $this->access->listGroupRoleAssignments(),
			];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): DataResponse
	{
		return $this->wrap(function (): array {
			$this->access->requireFleetAdmin($this->access->currentUserId());
			return $this->settings->all();
		});
	}

	#[NoAdminRequired]
	public function saveSettings(): DataResponse
	{
		return $this->wrap(function (): array {
			// §2.2 — "Configure other global app settings (currency, VAT,
			// uploads, workflow defaults)" is reserved to **app admin**.
			// Fleet admins run the fleet but do not change global policy.
			$this->access->requireAppAdmin($this->access->currentUserId());
			return $this->settings->save($this->payload());
		});
	}

	// §4.5a §A — Dedicated approval-config slice. Returns only the keys
	// listed in §4.5a §A so the admin UI does not have to filter the full
	// settings payload. POST is restricted to **app admin** (spec, §13).
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function approvalConfig(): DataResponse
	{
		return $this->wrap(function (): array {
			$this->access->requireFleetAdmin($this->access->currentUserId());
			return $this->approvalConfigSlice();
		});
	}

	#[NoAdminRequired]
	public function saveApprovalConfig(): DataResponse
	{
		return $this->wrap(function (): array {
			$this->access->requireAppAdmin($this->access->currentUserId());
			$payload = $this->payload();
			// Whitelist: only let the dedicated endpoint mutate the
			// §4.5a §A keys. Anything else is silently ignored — that way
			// a misbehaving admin client cannot piggy-back unrelated
			// settings on this narrow endpoint.
			$allowed = [
				SettingsService::KEY_APPROVAL_MODE,
				SettingsService::KEY_APPROVAL_WORKFLOW,
				SettingsService::KEY_APPROVAL_FALLBACK_NO_LM,
				SettingsService::KEY_APPROVAL_LINE_MANAGER_TIMEOUT_HOURS,
				SettingsService::KEY_APPROVAL_FLEET_TIMEOUT_HOURS,
				SettingsService::KEY_LINE_MANAGER_SELF_APPROVAL_ALLOWED,
				SettingsService::KEY_BOOKING_NO_SHOW_GRACE_MINUTES,
				SettingsService::KEY_BOOKING_EXTENSION_MAX_MINUTES,
				SettingsService::KEY_OVERDUE_RETURN_GRACE_MINUTES,
			];
			$camelAliases = [
				'approvalMode'                    => SettingsService::KEY_APPROVAL_MODE,
				'approvalWorkflow'                => SettingsService::KEY_APPROVAL_WORKFLOW,
				'approvalFallbackNoLm'            => SettingsService::KEY_APPROVAL_FALLBACK_NO_LM,
				'approvalLineManagerTimeoutHours' => SettingsService::KEY_APPROVAL_LINE_MANAGER_TIMEOUT_HOURS,
				'approvalFleetTimeoutHours'       => SettingsService::KEY_APPROVAL_FLEET_TIMEOUT_HOURS,
				'lineManagerSelfApprovalAllowed'  => SettingsService::KEY_LINE_MANAGER_SELF_APPROVAL_ALLOWED,
				'bookingNoShowGraceMinutes'       => SettingsService::KEY_BOOKING_NO_SHOW_GRACE_MINUTES,
				'bookingExtensionMaxMinutes'      => SettingsService::KEY_BOOKING_EXTENSION_MAX_MINUTES,
				'overdueReturnGraceMinutes'       => SettingsService::KEY_OVERDUE_RETURN_GRACE_MINUTES,
			];
			foreach ($camelAliases as $camel => $snake) {
				if (array_key_exists($camel, $payload) && !array_key_exists($snake, $payload)) {
					$payload[$snake] = $payload[$camel];
				}
			}
			$filtered = [];
			foreach ($allowed as $key) {
				if (array_key_exists($key, $payload)) {
					$filtered[$key] = $payload[$key];
				}
			}
			$this->settings->save($filtered);
			return $this->approvalConfigSlice();
		});
	}

	private function approvalConfigSlice(): array
	{
		return [
			'approvalMode'                     => $this->settings->approvalMode(),
			'approvalWorkflowEnabled'          => $this->settings->approvalWorkflowEnabled(),
			'approvalFallbackNoLm'             => $this->settings->approvalFallbackWhenNoLineManager(),
			'approvalLineManagerTimeoutHours'  => $this->settings->approvalLineManagerTimeoutHours(),
			'approvalFleetTimeoutHours'        => $this->settings->approvalFleetTimeoutHours(),
			'lineManagerSelfApprovalAllowed'   => $this->settings->lineManagerSelfApprovalAllowed(),
			'bookingNoShowGraceMinutes'        => $this->settings->bookingNoShowGraceMinutes(),
			'bookingExtensionMaxMinutes'       => $this->settings->bookingExtensionMaxMinutes(),
			'overdueReturnGraceMinutes'        => $this->settings->overdueReturnGraceMinutes(),
		];
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function lineManagerAssignments(): DataResponse
	{
		return $this->wrap(function (): array {
			$this->access->requireFleetAdminOrManager($this->access->currentUserId());
			$driver = trim((string)($this->request->getParam('driverUserId', '') ?? ''));
			return $this->lineManagers->listAssignments($driver !== '' ? $driver : null);
		});
	}

	#[NoAdminRequired]
	public function lineManagerAssignmentsCreate(): DataResponse
	{
		return $this->wrap(function (): array {
			return $this->lineManagers->createAssignment($this->payload(), $this->access->currentUserId());
		});
	}

	#[NoAdminRequired]
	public function lineManagerAssignmentsClose(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			return $this->lineManagers->closeAssignment($id, $this->access->currentUserId());
		});
	}

	/**
	 * §8.10 / §13.40 — GDPR right-to-erasure (pseudonymisation only; the
	 * legally-binding evidence chain stays intact via the `__erased__`
	 * tombstone). Requires fleet-admin role and an explicit confirmation
	 * token equal to `GDPR_ERASURE_CONFIRMED` to avoid mis-clicks.
	 */
	#[NoAdminRequired]
	public function eraseUser(): DataResponse
	{
		return $this->wrap(function (): array {
			$performedBy = $this->access->currentUserId();
			$payload = $this->payload();
			$userId = trim((string)($payload['userId'] ?? $payload['user_id'] ?? ''));
			$confirmation = (string)($payload['confirmation'] ?? '');
			if ($confirmation !== 'GDPR_ERASURE_CONFIRMED') {
				throw new \OCA\MobilityCheck\Exception\ValidationException('CONFIRMATION_REQUIRED', 'confirmation');
			}
			$counts = $this->gdpr->eraseUser($userId, $performedBy);
			return [
				'userIdHash' => substr(hash('sha256', $userId), 0, 16),
				'pseudonymisedRowsByTable' => $counts,
			];
		});
	}

	/**
	 * §8.11 / §13.41 — Documented retention purge. GET returns the dry-run
	 * preview; POST with confirmation `RETENTION_PURGE_CONFIRMED` actually
	 * deletes rows older than the per-table retention window.
	 */
	#[NoAdminRequired]
	public function retentionPurge(): DataResponse
	{
		return $this->wrap(function (): array {
			$performedBy = $this->access->currentUserId();
			$payload = $this->payload();
			$mode = (string)($payload['mode'] ?? 'preview');
			if ($mode === 'execute') {
				$confirmation = (string)($payload['confirmation'] ?? '');
				$deleted = $this->retention->executePurge($performedBy, $confirmation);
				return ['deletedByTable' => $deleted];
			}
			return ['preview' => $this->retention->previewPurge($performedBy)];
		});
	}

	private function nullable(string $key): ?string
	{
		$value = trim((string)($this->request->getParam($key, '') ?? ''));
		return $value !== '' ? $value : null;
	}
}
