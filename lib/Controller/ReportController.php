<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\AppInfo\Application;
use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\ExportService;
use OCA\MobilityCheck\Service\LineManagerService;
use OCA\MobilityCheck\Service\ReportPdfBuilder;
use OCA\MobilityCheck\Service\ReportService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\L10N\IFactory;

class ReportController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private ReportService $reports,
		private LineManagerService $lineManagers,
		private ExportService $export,
		private IFactory $l10nFactory,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function driverCompliance(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAuditorOrManagerOrAdmin($userId);
			return $this->reports->driverCompliance($this->lineManagerDriverScope($userId));
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function vehicleUtilisation(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAuditorOrManagerOrAdmin($userId);
			$scope = $this->lineManagerDriverScope($userId);
			$scopeVehicles = $this->lineManagerVehicleScope($userId, $scope);
			$vehicleId = (int)($this->request->getParam('vehicleId', 0) ?? 0);
			if ($vehicleId !== 0 && $scopeVehicles !== null && !in_array($vehicleId, $scopeVehicles, true)) {
				throw new ForbiddenException('INSUFFICIENT_ROLE');
			}
			return $this->reports->vehicleUtilisation(
				$vehicleId,
				(string)($this->request->getParam('from', '') ?? ''),
				(string)($this->request->getParam('to', '') ?? ''),
				$scope,
			);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function costs(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAuditorOrManagerOrAdmin($userId);
			$scope = $this->lineManagerDriverScope($userId);
			$scopeVehicles = $this->lineManagerVehicleScope($userId, $scope);
			$vehicleId = $this->nullableInt('vehicleId');
			if ($vehicleId !== null && $scopeVehicles !== null && !in_array($vehicleId, $scopeVehicles, true)) {
				throw new ForbiddenException('INSUFFICIENT_ROLE');
			}
			return $this->reports->costsReport(
				$this->nullableString('from'),
				$this->nullableString('to'),
				$vehicleId,
				$scopeVehicles,
			);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function damage(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAuditorOrManagerOrAdmin($userId);
			$scope = $this->lineManagerDriverScope($userId);
			$scopeVehicles = $this->lineManagerVehicleScope($userId, $scope);
			$vehicleId = $this->nullableInt('vehicleId');
			if ($vehicleId !== null && $scopeVehicles !== null && !in_array($vehicleId, $scopeVehicles, true)) {
				throw new ForbiddenException('INSUFFICIENT_ROLE');
			}
			return $this->reports->damageReport(
				$this->nullableString('from'),
				$this->nullableString('to'),
				$vehicleId,
				$scope,
			);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bookings(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAuditorOrManagerOrAdmin($userId);
			$scope = $this->lineManagerDriverScope($userId);
			$scopeVehicles = $this->lineManagerVehicleScope($userId, $scope);
			$vehicleId = $this->nullableInt('vehicleId');
			$driverUserId = $this->nullableString('driverUserId');
			if ($vehicleId !== null && $scopeVehicles !== null && !in_array($vehicleId, $scopeVehicles, true)) {
				throw new ForbiddenException('INSUFFICIENT_ROLE');
			}
			if ($driverUserId !== null && $scope !== null && !in_array($driverUserId, $scope, true)) {
				throw new ForbiddenException('INSUFFICIENT_ROLE');
			}
			return $this->reports->bookingsReport(
				$this->nullableString('from'),
				$this->nullableString('to'),
				$vehicleId,
				$driverUserId,
				$scope,
			);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function notifications(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAuditorOrManagerOrAdmin($userId);
			$scope = $this->lineManagerDriverScope($userId);
			$recipients = null;
			if ($scope !== null) {
				$recipients = array_values(array_unique(array_merge($scope, [$userId])));
			}
			return $this->reports->notificationsReport(
				$this->nullableString('from'),
				$this->nullableString('to'),
				$recipients,
			);
		});
	}

	#[NoAdminRequired]
	public function exportPdf(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAuditorOrManagerOrAdmin($userId);
			$scope = $this->lineManagerDriverScope($userId);
			$scopeVehicles = $this->lineManagerVehicleScope($userId, $scope);
			$p = $this->payload();
			$tab = trim((string)($p['tab'] ?? 'costs'));
			$from = trim((string)($p['from'] ?? ''));
			$to = trim((string)($p['to'] ?? ''));
			$vehicleId = isset($p['vehicleId']) && $p['vehicleId'] !== '' && $p['vehicleId'] !== null
				? (int)$p['vehicleId']
				: null;
			$rawDriver = $p['driverUserId'] ?? null;
			$driverUserId = null;
			if (is_string($rawDriver) && trim($rawDriver) !== '') {
				$driverUserId = trim($rawDriver);
			} elseif (is_int($rawDriver) || is_float($rawDriver)) {
				$driverUserId = (string)(int)$rawDriver;
			}
			if ($from === '' || $to === '') {
				throw new ValidationException('EXPORT_FILTER_REQUIRED');
			}
			if ($vehicleId !== null && $scopeVehicles !== null && !in_array($vehicleId, $scopeVehicles, true)) {
				throw new ForbiddenException('INSUFFICIENT_ROLE');
			}
			if ($driverUserId !== null && $scope !== null && !in_array($driverUserId, $scope, true)) {
				throw new ForbiddenException('INSUFFICIENT_ROLE');
			}
			$lang = $this->l10nFactory->getUserLanguage($this->userSession->getUser());
			$l = $this->l10nFactory->get(Application::APP_ID, $lang);
			$data = match ($tab) {
				'costs' => $this->reports->costsReport($from, $to, $vehicleId, $scopeVehicles),
				'utilisation' => $this->reports->vehicleUtilisation($vehicleId ?? 0, $from, $to, $scope),
				'bookings' => $this->reports->bookingsReport($from, $to, $vehicleId, $driverUserId, $scope),
				'damage' => $this->reports->damageReport($from, $to, $vehicleId, $scope),
				'compliance' => $this->reports->driverCompliance($scope),
				'notifications' => $this->reports->notificationsReport(
					$from,
					$to,
					$scope !== null ? array_values(array_unique(array_merge($scope, [$userId]))) : null,
				),
				default => throw new ValidationException('EXPORT_FILTER_REQUIRED', 'tab'),
			};
			$html = ReportPdfBuilder::build($tab, is_array($data) ? $data : [], $l, $lang);
			return $this->export->requestReportPdfFromHtml($userId, $html, 'mc-report-' . $tab);
		});
	}

	/** @return list<string>|null null = no line-manager restriction */
	private function lineManagerDriverScope(string $userId): ?array
	{
		if ($this->access->isFleetAdminOrManager($userId) || $this->access->isAuditor($userId)) {
			return null;
		}
		if ($this->access->isLineManager($userId)) {
			return array_values(array_unique(array_merge(
				$this->lineManagers->listSupervisedDriverUserIds($userId),
				[$userId]
			)));
		}
		return null;
	}

	/**
	 * @param list<string>|null $scope
	 * @return list<int>|null
	 */
	private function lineManagerVehicleScope(string $userId, ?array $scope): ?array
	{
		if ($scope === null) {
			return null;
		}
		return $this->lineManagers->listVehicleIdsFromBookingsForDrivers($scope);
	}

	private function nullableString(string $key): ?string
	{
		$v = trim((string)($this->request->getParam($key, '') ?? ''));
		return $v !== '' ? $v : null;
	}

	private function nullableInt(string $key): ?int
	{
		$raw = $this->request->getParam($key, null);
		if ($raw === null || $raw === '') {
			return null;
		}
		return (int)$raw;
	}
}
