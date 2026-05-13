<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\CostService;
use OCA\MobilityCheck\Service\LineManagerService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class CostController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private CostService $costs,
		private LineManagerService $lineManagers,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function list(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireFleetOperationsRead($userId);
			$filters = $this->payload();
			if (!$this->access->isFleetAdminOrManager($userId) && !$this->access->isAuditor($userId)) {
				$scopeDrivers = array_values(array_unique(array_merge(
					$this->lineManagers->listSupervisedDriverUserIds($userId),
					[$userId]
				)));
				$vids = $this->lineManagers->listVehicleIdsFromBookingsForDrivers($scopeDrivers);
				if ($vids === []) {
					return [];
				}
				if (!empty($filters['vehicleId'])) {
					$vid = (int)$filters['vehicleId'];
					if (!in_array($vid, $vids, true)) {
						throw new ForbiddenException('INSUFFICIENT_ROLE');
					}
				} else {
					$filters['vehicleIds'] = $vids;
				}
			}
			return $this->costs->list($filters);
		});
	}

	#[NoAdminRequired]
	public function create(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireFleetAdminOrManager($userId);
			return $this->costs->create($this->payload(), $userId);
		});
	}

	#[NoAdminRequired]
	public function update(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireFleetAdminOrManager($userId);
			return $this->costs->update($id, $this->payload(), $userId);
		});
	}

	#[NoAdminRequired]
	public function delete(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): bool {
			$userId = $this->access->currentUserId();
			$this->access->requireFleetAdminOrManager($userId);
			$this->costs->softDelete($id, $userId, (string)($this->request->getParam('reason', '') ?? ''));
			return true;
		});
	}
}
