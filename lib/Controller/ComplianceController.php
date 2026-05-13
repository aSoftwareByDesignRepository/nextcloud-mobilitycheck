<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\ComplianceService;
use OCA\MobilityCheck\Service\LineManagerService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ComplianceController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private ComplianceService $compliance,
		private LineManagerService $lineManagers,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function instructions(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireFleetOperationsRead($userId);
			$year = (int)($this->request->getParam('year', gmdate('Y')) ?? gmdate('Y'));
			$scope = $this->complianceDriverScope($userId);
			return $this->compliance->listInstructionsByYear($year, $scope);
		});
	}

	#[NoAdminRequired]
	public function completeInstruction(int $driverProfileId): DataResponse
	{
		return $this->wrap(function () use ($driverProfileId): array {
			$userId = $this->access->currentUserId();
			$this->access->requireFleetAdminOrManager($userId);
			return $this->compliance->recordInstruction(
				$driverProfileId,
				(int)($this->request->getParam('calendarYear', gmdate('Y')) ?? gmdate('Y')),
				(string)($this->request->getParam('completedDate', gmdate('Y-m-d')) ?? gmdate('Y-m-d')),
				$userId,
				(string)($this->request->getParam('reference', '') ?? '')
			);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function licences(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireFleetOperationsRead($userId);
			$scope = $this->complianceDriverScope($userId);
			return $this->compliance->listLicences($scope);
		});
	}

	/** @return list<string>|null null = all drivers (fleet / auditor) */
	private function complianceDriverScope(string $userId): ?array
	{
		if ($this->access->isFleetAdminOrManager($userId) || $this->access->isAuditor($userId)) {
			return null;
		}
		return array_values(array_unique(array_merge(
			$this->lineManagers->listSupervisedDriverUserIds($userId),
			[$userId]
		)));
	}
}
