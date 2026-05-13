<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\BookingService;
use OCA\MobilityCheck\Service\ComplianceService;
use OCA\MobilityCheck\Service\DamageService;
use OCA\MobilityCheck\Service\DriverService;
use OCA\MobilityCheck\Service\LocaleFormatService;
use OCA\MobilityCheck\Service\MaintenanceService;
use OCA\MobilityCheck\Service\RelocationService;
use OCA\MobilityCheck\Service\SettingsService;
use OCA\MobilityCheck\Service\VehicleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Bootstrap + dashboard endpoints.
 *
 *  - `GET /api/bootstrap` — current user, roles, settings, server time.
 *    The JS layer calls it on first paint to render the role strip and
 *    decide which sections to show. Pure read — never mutates.
 *  - `GET /api/dashboard` — role-scoped KPI bundle. The response shape
 *    has stable keys regardless of role; the keys actually populated
 *    depend on the role so a driver does not see fleet-wide totals
 *    (§2.2).
 */
class ApiController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private SettingsService $settings,
		private LocaleFormatService $locale,
		private VehicleService $vehicles,
		private DriverService $drivers,
		private BookingService $bookings,
		private DamageService $damage,
		private MaintenanceService $maintenance,
		private ComplianceService $compliance,
		private RelocationService $relocations,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bootstrap(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$roles = $this->access->getRoles($userId);
			$hints = $this->locale->clientHints();
			return new DataResponse([
				'ok' => true,
				'data' => [
					'user' => [
						'id' => $userId,
						'roles' => $roles,
						'isAppAdmin' => $this->access->isAppAdmin($userId),
						'isFleetAdmin' => $this->access->isFleetAdmin($userId),
						'isManager' => $this->access->isFleetAdminOrManager($userId),
						'isLineManager' => $this->access->isLineManager($userId),
						'isDriver' => $this->access->isDriver($userId),
						'isAuditor' => $this->access->isAuditor($userId),
						'isWorkshop' => in_array(AccessControlService::ROLE_WORKSHOP, $roles, true),
						'isWorkshopOnly' => $this->access->isWorkshopOnly($userId),
						'isLineManagerScopedReader' => $this->access->isLineManager($userId)
							&& !$this->access->isFleetAdminOrManager($userId)
							&& !$this->access->isAuditor($userId),
					],
					'settings' => [
						'currency' => $this->settings->currency(),
						'defaultVatBp' => $this->settings->defaultVatBp(),
						'checkinGraceMinutes' => $this->settings->checkinGraceMinutes(),
						'overdueReturnGraceMinutes' => $this->settings->overdueReturnGraceMinutes(),
						'licenceThresholdsDays' => $this->settings->licenceThresholdsDays(),
						'maxUploadBytes' => $this->settings->maxUploadBytes(),
						'approvalWorkflowEnabled' => $this->settings->approvalWorkflowEnabled(),
						'approvalMode' => $this->settings->approvalMode(),
						'bookingExtensionMaxMinutes' => $this->settings->bookingExtensionMaxMinutes(),
						'bookingNoShowGraceMinutes' => $this->settings->bookingNoShowGraceMinutes(),
						'lineManagerSelfApprovalAllowed' => $this->settings->lineManagerSelfApprovalAllowed(),
						'approvalResetsOnTimeChange' => $this->settings->approvalResetsOnTimeChange(),
						'stationStrictMode' => $this->settings->stationStrictMode(),
						'intelligentAllocationEnabled' => $this->settings->intelligentAllocationEnabled(),
						'intelligentAllocationMode' => $this->settings->intelligentAllocationMode(),
						'intelligentAllocationOnNoReplacement' => $this->settings->intelligentAllocationOnNoReplacement(),
						'vehicleChoicePolicy' => $this->settings->vehicleChoicePolicy(),
					],
					'locale' => $hints,
					'now' => gmdate('Y-m-d\TH:i:s\Z'),
				],
			]);
		} catch (\Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			$isManager = $this->access->isFleetAdminOrManager($userId);
			$isDriver = $this->access->isDriver($userId);

			$today = gmdate('Y-m-d');
			$out = ['today' => $today];

			if ($isManager) {
				$pending = $this->bookings->list(['status' => BookingService::pendingApprovalStatuses()]);
				$openDamage = $this->damage->list([
					'statusIn' => [
						DamageService::STATUS_REPORTED,
						DamageService::STATUS_UNDER_ASSESSMENT,
						DamageService::STATUS_REPAIR_SCHEDULED,
						DamageService::STATUS_IN_REPAIR,
					],
					'viewerUserId' => $userId,
				]);
				$overdueMaintenance = $this->maintenance->overdue();
				$vehicles = $this->vehicles->list(['activeOnly' => true]);
				$relocationsOpen = $this->relocations->listOpen($userId);
				$out['manager'] = [
					'pendingApprovals' => count($pending),
					'pendingApprovalsList' => array_slice($pending, 0, 10),
					'openDamage' => count($openDamage),
					'openDamageList' => array_slice($openDamage, 0, 10),
					'overdueMaintenance' => count($overdueMaintenance),
					'overdueMaintenanceList' => array_slice($overdueMaintenance, 0, 10),
					'vehicleCount' => count($vehicles),
					'availableVehicles' => count(array_filter(
						$vehicles,
						static fn ($v) => ($v['status'] ?? '') === VehicleService::STATUS_AVAILABLE
					)),
					'openRelocations' => count($relocationsOpen),
					'openRelocationsList' => array_slice($relocationsOpen, 0, 15),
				];
			}

			if ($isDriver) {
				$mine = $this->bookings->list([
					'driverUserId' => $userId,
					'status' => array_merge(BookingService::pendingApprovalStatuses(), [
						BookingService::STATUS_APPROVED,
						BookingService::STATUS_ACTIVE,
					]),
					'from' => $today,
					'limit' => 25,
				]);
				$driver = $this->drivers->getByUserId($userId);
				$out['driver'] = [
					'profileExists' => $driver !== null,
					'compliance' => $driver !== null ? $this->drivers->complianceDetail((int)$driver['id']) : null,
					'upcomingBookings' => array_slice($mine, 0, 10),
					'upcomingCount' => count($mine),
				];
			}

			if ($this->access->isLineManager($userId) && !$isManager) {
				$pendingLm = $this->bookings->listPendingApprovalsForLineManager($userId);
				$openDamageLm = $this->damage->list([
					'statusIn' => [
						DamageService::STATUS_REPORTED,
						DamageService::STATUS_UNDER_ASSESSMENT,
						DamageService::STATUS_REPAIR_SCHEDULED,
						DamageService::STATUS_IN_REPAIR,
					],
					'viewerUserId' => $userId,
					'limit' => 50,
				]);
				$out['lineManager'] = [
					'pendingApprovals' => count($pendingLm),
					'pendingApprovalsList' => array_slice($pendingLm, 0, 10),
					'openDamage' => count($openDamageLm),
					'openDamageList' => array_slice($openDamageLm, 0, 10),
				];
			}

			return new DataResponse(['ok' => true, 'data' => $out]);
		} catch (\Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}
}
