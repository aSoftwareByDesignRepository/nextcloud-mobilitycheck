<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\MaintenanceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class MaintenanceController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private MaintenanceService $maintenance,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function list(): DataResponse
	{
		return $this->wrap(function (): array {
			$this->access->requireAnyAppRole($this->access->currentUserId());
			return $this->maintenance->list($this->payload());
		});
	}

	#[NoAdminRequired]
	public function create(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireFleetAdminOrManager($userId);
			return $this->maintenance->create($this->payload(), $userId);
		});
	}

	#[NoAdminRequired]
	public function update(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireFleetAdminOrManager($userId);
			return $this->maintenance->update($id, $this->payload(), $userId);
		});
	}

	#[NoAdminRequired]
	public function complete(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireFleetAdminOrManager($userId);
			return $this->maintenance->complete($id, $this->payload(), $userId);
		});
	}
}
