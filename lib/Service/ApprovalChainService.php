<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ServiceMisconfigurationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;

/**
 * §4.5a §E — Declarative approval chains: parse definitions, evaluate
 * `applies_when` at booking creation, freeze snapshots, map step ids to
 * booking statuses, and resolve whether a user may approve a step.
 */
class ApprovalChainService
{
	public function __construct(
		private SettingsService $settings,
		private LineManagerService $lineManagers,
		private AccessControlService $access,
		private IGroupManager $groups,
		private IDBConnection $db,
	) {
	}

	/** @throws ServiceMisconfigurationException */
	public function assertGlobalChainConfigurationValid(): void
	{
		if ($this->settings->approvalMode() !== 'chain') {
			return;
		}
		if ($this->getActiveChainDefinition() === null) {
			throw new ServiceMisconfigurationException('APPROVAL_CHAIN_MISCONFIGURED');
		}
	}

	/**
	 * @return array<string,mixed>|null null if mode is not chain or config broken
	 */
	public function getActiveChainDefinition(): ?array
	{
		if ($this->settings->approvalMode() !== 'chain') {
			return null;
		}
		$id = $this->settings->approvalActiveChainId();
		if ($id === '') {
			return null;
		}
		foreach ($this->settings->approvalChainDefinitions() as $row) {
			if (!is_array($row)) {
				continue;
			}
			if ((string)($row['id'] ?? '') === $id && empty($row['archived'])) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Look up a chain definition by stable id (including archived rows) so
	 * frozen booking snapshots remain resolvable after operators archive a chain.
	 */
	public function getChainDefinitionById(string $chainId): ?array
	{
		$chainId = trim($chainId);
		if ($chainId === '') {
			return null;
		}
		foreach ($this->settings->approvalChainDefinitions() as $row) {
			if (!is_array($row)) {
				continue;
			}
			if ((string)($row['id'] ?? '') === $chainId) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $def A single chain definition object (same shape as entries in `approval_chain_definitions`)
	 * @param array<string,mixed> $bookingContext keys: driverUserId, expectedDistanceKm?, purpose?, costCentre?, vehicleId, assignmentMode?, taxTreatment?
	 * @return array{snapshot:string,initialStatus:string,skipped:list<array{step_id:string,reason:string}>}
	 */
	public function resolveFromDefinition(array $def, array $bookingContext): array
	{
		$steps = $def['steps'] ?? null;
		if (!is_array($steps) || $steps === []) {
			throw new ServiceMisconfigurationException('APPROVAL_CHAIN_MISCONFIGURED');
		}
		$active = [];
		$skipped = [];
		foreach ($steps as $step) {
			if (!is_array($step)) {
				throw new ServiceMisconfigurationException('APPROVAL_CHAIN_MISCONFIGURED');
			}
			$sid = trim((string)($step['step_id'] ?? ''));
			if ($sid === '' || !$this->isValidStepId($sid)) {
				throw new ServiceMisconfigurationException('APPROVAL_CHAIN_MISCONFIGURED');
			}
			$when = $step['applies_when'] ?? null;
			if (is_array($when) && !$this->appliesWhenMatches($when, $bookingContext)) {
				$skipped[] = ['step_id' => $sid, 'reason' => 'condition_not_met'];
				continue;
			}
			$approver = $step['approver'] ?? null;
			if (!is_array($approver) || ($approver['kind'] ?? '') === '') {
				throw new ServiceMisconfigurationException('APPROVAL_CHAIN_MISCONFIGURED');
			}
			$active[] = [
				'step_id' => $sid,
				'approver' => $approver,
				'timeout_hours' => (int)($step['timeout_hours'] ?? 24),
			];
		}
		if ($active === []) {
			throw new ServiceMisconfigurationException('APPROVAL_CHAIN_MISCONFIGURED');
		}
		$freeze = [
			'chainId' => (string)($def['id'] ?? ''),
			'steps' => $active,
			'skipped' => $skipped,
		];
		$first = $active[0];
		return [
			'snapshot' => json_encode($freeze, JSON_THROW_ON_ERROR),
			'initialStatus' => $this->pendingStatusForStepId((string)$first['step_id']),
			'skipped' => $skipped,
		];
	}

	/**
	 * @param array<string,mixed> $bookingContext keys: driverUserId, expectedDistanceKm?, purpose?, costCentre?, vehicleId, assignmentMode?, taxTreatment?
	 * @return array{snapshot:string,initialStatus:string,skipped:list<array{step_id:string,reason:string}>}
	 */
	public function resolveInitialForCreate(array $bookingContext): array
	{
		$def = $this->getActiveChainDefinition();
		if ($def === null) {
			throw new ServiceMisconfigurationException('APPROVAL_CHAIN_MISCONFIGURED');
		}
		return $this->resolveFromDefinition($def, $bookingContext);
	}

	/**
	 * §4.5b — When metadata on an approved booking changes, the ordered list of
	 * active chain steps (after `applies_when` filtering) may change. If it
	 * does, prior approval rows must be superseded and the walk restarted.
	 *
	 * @param array<string,mixed> $oldCtx
	 * @param array<string,mixed> $newCtx
	 */
	public function chainWalkDiffersForContext(string $snapshotJson, array $oldCtx, array $newCtx): bool
	{
		try {
			/** @var array<string,mixed> $snap */
			$snap = json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			return false;
		}
		$chainId = trim((string)($snap['chainId'] ?? ''));
		if ($chainId === '') {
			return false;
		}
		$def = $this->getChainDefinitionById($chainId);
		if ($def === null) {
			return true;
		}
		$before = $this->activeStepIdListForDefinition($def, $oldCtx);
		$after = $this->activeStepIdListForDefinition($def, $newCtx);
		return $before !== $after;
	}

	/**
	 * @param array<string,mixed> $def
	 * @param array<string,mixed> $ctx
	 * @return list<string>
	 */
	private function activeStepIdListForDefinition(array $def, array $ctx): array
	{
		$steps = $def['steps'] ?? null;
		if (!is_array($steps)) {
			return [];
		}
		$ids = [];
		foreach ($steps as $step) {
			if (!is_array($step)) {
				continue;
			}
			$sid = trim((string)($step['step_id'] ?? ''));
			if ($sid === '' || !$this->isValidStepId($sid)) {
				continue;
			}
			$when = $step['applies_when'] ?? null;
			if (is_array($when) && !$this->appliesWhenMatches($when, $ctx)) {
				continue;
			}
			$approver = $step['approver'] ?? null;
			if (!is_array($approver) || ($approver['kind'] ?? '') === '') {
				continue;
			}
			$ids[] = $sid;
		}
		return $ids;
	}

	public function pendingStatusForStepId(string $stepId): string
	{
		if ($stepId === 'line_manager') {
			return 'pending_line_manager';
		}
		if ($stepId === 'fleet' || $stepId === 'fleet_manager') {
			return 'pending_fleet';
		}
		return 'pending_' . $stepId;
	}

	public function stepIdFromBookingStatus(string $status): ?string
	{
		if ($status === 'pending_line_manager') {
			return 'line_manager';
		}
		if ($status === 'pending_fleet') {
			return 'fleet';
		}
		if (str_starts_with($status, 'pending_')) {
			return substr($status, strlen('pending_'));
		}
		return null;
	}

	/**
	 * @return array{step_id:string,approver:array<string,mixed>,timeout_hours:int}|null
	 */
	public function currentStepFromSnapshot(?string $snapshotJson, string $bookingStatus): ?array
	{
		if ($snapshotJson === null || $snapshotJson === '') {
			return null;
		}
		try {
			/** @var array<string,mixed> $snap */
			$snap = json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			return null;
		}
		$steps = $snap['steps'] ?? null;
		if (!is_array($steps)) {
			return null;
		}
		$sid = $this->stepIdFromBookingStatus($bookingStatus);
		if ($sid === null) {
			return null;
		}
		foreach ($steps as $st) {
			if (!is_array($st)) {
				continue;
			}
			if ((string)($st['step_id'] ?? '') === $sid) {
				$ap = $st['approver'] ?? [];
				return [
					'step_id' => $sid,
					'approver' => is_array($ap) ? $ap : [],
					'timeout_hours' => (int)($st['timeout_hours'] ?? 24),
				];
			}
		}
		return null;
	}

	public function nextStatusAfterApproval(string $snapshotJson, string $currentStatus): string
	{
		try {
			/** @var array<string,mixed> $snap */
			$snap = json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			return 'approved';
		}
		$steps = $snap['steps'] ?? [];
		if (!is_array($steps) || $steps === []) {
			return 'approved';
		}
		$currentSid = $this->stepIdFromBookingStatus($currentStatus);
		$idx = -1;
		foreach ($steps as $i => $st) {
			if (is_array($st) && (string)($st['step_id'] ?? '') === $currentSid) {
				$idx = (int)$i;
				break;
			}
		}
		if ($idx < 0 || $idx + 1 >= count($steps)) {
			return 'approved';
		}
		$next = $steps[$idx + 1];
		return $this->pendingStatusForStepId((string)($next['step_id'] ?? 'fleet'));
	}

	/**
	 * @param array<string,mixed> $approver
	 */
	public function userMayActOnChainStep(string $userId, array $approver, string $driverUserId, ?string $bookingCostCentre): bool
	{
		$kind = (string)($approver['kind'] ?? '');
		return match ($kind) {
			'line_manager' => $this->lineManagers->isActiveLineManagerForDriver($userId, $driverUserId),
			'role' => $this->userMatchesRoleApprover($userId, $approver),
			'group' => $this->userInGroupApprover($userId, $approver),
			'cost_centre_owner' => $this->userOwnsBookingCostCentre($userId, $bookingCostCentre),
			default => false,
		};
	}

	/**
	 * @param array<string,mixed> $approver
	 */
	public function isPureFleetRoleApprover(array $approver): bool
	{
		if (($approver['kind'] ?? '') !== 'role') {
			return false;
		}
		$roles = $approver['roles'] ?? [];
		if (!is_array($roles) || $roles === []) {
			return false;
		}
		$fleetish = ['fleet_manager', 'fleet_admin', 'app_admin'];
		foreach ($roles as $r) {
			if (!in_array((string)$r, $fleetish, true)) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string,mixed> $when */
	private function appliesWhenMatches(array $when, array $ctx): bool
	{
		if (isset($when['expected_distance_km_at_least'])) {
			$min = (int)$when['expected_distance_km_at_least'];
			$dist = (int)($ctx['expectedDistanceKm'] ?? 0);
			if ($dist < $min) {
				return false;
			}
		}
		if (isset($when['purpose_contains_any']) && is_array($when['purpose_contains_any'])) {
			$p = mb_strtolower((string)($ctx['purpose'] ?? ''));
			$hit = false;
			foreach ($when['purpose_contains_any'] as $needle) {
				if ($needle !== '' && str_contains($p, mb_strtolower((string)$needle))) {
					$hit = true;
					break;
				}
			}
			if (!$hit) {
				return false;
			}
		}
		if (isset($when['assignment_mode_in']) && is_array($when['assignment_mode_in'])) {
			$m = (string)($ctx['assignmentMode'] ?? '');
			if (!in_array($m, array_map('strval', $when['assignment_mode_in']), true)) {
				return false;
			}
		}
		if (isset($when['tax_treatment_in']) && is_array($when['tax_treatment_in'])) {
			$t = (string)($ctx['taxTreatment'] ?? '');
			if (!in_array($t, array_map('strval', $when['tax_treatment_in']), true)) {
				return false;
			}
		}
		return true;
	}

	private function isValidStepId(string $sid): bool
	{
		return (bool)preg_match('/^[a-z][a-z0-9_]{0,47}$/', $sid);
	}

	/** @param array<string,mixed> $approver */
	private function userMatchesRoleApprover(string $userId, array $approver): bool
	{
		$roles = $approver['roles'] ?? [];
		if (!is_array($roles)) {
			return false;
		}
		foreach ($roles as $role) {
			$r = (string)$role;
			if ($r === 'fleet_manager' && $this->access->isFleetManager($userId)) {
				return true;
			}
			if ($r === 'fleet_admin' && $this->access->isFleetAdmin($userId)) {
				return true;
			}
			if ($r === 'app_admin' && $this->access->isAppAdmin($userId)) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string,mixed> $approver */
	private function userInGroupApprover(string $userId, array $approver): bool
	{
		$gid = (string)($approver['groupId'] ?? '');
		if ($gid === '') {
			return false;
		}
		return $this->groups->isInGroup($userId, $gid);
	}

	private function userOwnsBookingCostCentre(string $userId, ?string $bookingCostCentre): bool
	{
		$cc = trim((string)$bookingCostCentre);
		if ($cc === '') {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('owner_user_id')->from('mc_cost_centres')
			->where($qb->expr()->eq('code', $qb->createNamedParameter($cc)))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		return $row && (string)$row['owner_user_id'] === $userId;
	}
}
