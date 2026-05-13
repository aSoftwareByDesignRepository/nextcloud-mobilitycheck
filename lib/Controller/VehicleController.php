<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\LineManagerService;
use OCA\MobilityCheck\Service\VehicleAssignmentService;
use OCA\MobilityCheck\Service\VehicleService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class VehicleController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private VehicleService $vehicles,
		private LineManagerService $lineManagers,
		private VehicleAssignmentService $assignments,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function list(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			$this->access->requireNotWorkshopOnly($userId);
			return $this->vehicles->list([
				'activeOnly' => ((string)$this->request->getParam('activeOnly', '1')) !== '0',
				'status' => (string)$this->request->getParam('status', ''),
			]);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			$this->access->requireNotWorkshopOnly($userId);
			$v = $this->vehicles->get($id);
			$v['active_assignment'] = $this->assignments->getActiveAssignment($id);
			return $v;
		});
	}

	#[NoAdminRequired]
	public function create(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireFleetAdminOrManager($userId);
			return $this->vehicles->create($this->payload(), $userId);
		});
	}

	#[NoAdminRequired]
	public function update(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireFleetAdminOrManager($userId);
			return $this->vehicles->update($id, $this->payload(), $userId);
		});
	}

	#[NoAdminRequired]
	public function decommission(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireFleetAdminOrManager($userId);
			$reason = (string)($this->request->getParam('reason', '') ?? '');
			return $this->vehicles->decommission($id, $userId, $reason);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function availability(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireNotWorkshopOnly($userId);
			$maySee = $this->access->isDriver($userId)
				|| $this->access->isFleetAdminOrManager($userId)
				|| $this->access->isAuditor($userId)
				|| $this->access->isLineManager($userId);
			if (!$maySee) {
				throw new ForbiddenException('INSUFFICIENT_ROLE');
			}
			$rows = $this->vehicles->availability(
				$id,
				(string)$this->request->getParam('from', ''),
				(string)$this->request->getParam('to', '')
			);
			// Line managers who are not also drivers need the pool calendar for booking overlap,
			// but must not see other drivers' reservations (§2.2). Drivers and fleet see full rows.
			if ($this->access->isLineManager($userId)
				&& !$this->access->isDriver($userId)
				&& !$this->access->isFleetAdminOrManager($userId)
				&& !$this->access->isAuditor($userId)) {
				$allowed = array_values(array_unique(array_merge(
					$this->lineManagers->listSupervisedDriverUserIds($userId),
					[$userId]
				)));
				$flip = array_fill_keys($allowed, true);
				$rows = array_values(array_filter(
					$rows,
					static fn (array $r): bool => isset($flip[$r['driverUserId'] ?? ''])
				));
			}
			return $rows;
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function lastReturnInfo(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			$this->access->requireNotWorkshopOnly($userId);
			$limit = (int)$this->request->getParam('limit', 5);
			return $this->vehicles->lastReturnInfo($id, $limit > 0 ? $limit : 5);
		});
	}
}
