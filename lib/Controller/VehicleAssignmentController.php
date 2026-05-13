<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\VehicleAssignmentService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class VehicleAssignmentController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
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
			$vehicleId = (int)($this->request->getParam('vehicleId', 0) ?: 0);
			if ($vehicleId <= 0) {
				return [];
			}
			if (!$this->assignments->userMaySeeVehicle($userId, $vehicleId)) {
				throw new ForbiddenException('INSUFFICIENT_ROLE');
			}
			return $this->assignments->listHistory($vehicleId);
		});
	}

	#[NoAdminRequired]
	public function create(): DataResponse
	{
		return $this->wrap(function (): array {
			$p = $this->payload();
			$vehicleId = (int)($p['vehicleId'] ?? $p['vehicle_id'] ?? 0);
			if ($vehicleId <= 0) {
				throw new ValidationException('VEHICLE_REQUIRED', 'vehicle_id');
			}
			return $this->assignments->create($vehicleId, $p, $this->access->currentUserId());
		});
	}

	#[NoAdminRequired]
	public function close(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$p = $this->payload();
			$d = trim((string)($p['validUntil'] ?? $p['valid_until'] ?? ''));
			return $this->assignments->close($id, $d, $this->access->currentUserId());
		});
	}
}
