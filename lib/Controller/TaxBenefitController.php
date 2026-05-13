<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Exception\ValidationException;
use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\TaxBenefitService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * §A9 — Tax benefit preview (1 %-Regelung) for payroll sanity checks.
 */
class TaxBenefitController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private TaxBenefitService $taxBenefit,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function monthly(): DataResponse
	{
		return $this->wrap(function (): array {
			$uid = $this->access->currentUserId();
			$this->access->requireNotWorkshopOnly($uid);
			$this->access->requireAuditorOrManagerOrAdmin($uid);
			$vehicleId = (int)$this->request->getParam('vehicleId', 0);
			$yearMonth = trim((string)$this->request->getParam('yearMonth', ''));
			if ($vehicleId <= 0) {
				throw new ValidationException('VEHICLE_ID_REQUIRED', 'vehicleId');
			}
			return $this->taxBenefit->monthlyPreview($vehicleId, $yearMonth, $uid);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function payrollExport(): DataDownloadResponse|DataResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->access->requireNotWorkshopOnly($uid);
			$this->access->requireAuditorOrManagerOrAdmin($uid);
			$vehicleId = (int)$this->request->getParam('vehicleId', 0);
			$yearMonth = trim((string)$this->request->getParam('yearMonth', ''));
			if ($vehicleId <= 0) {
				throw new ValidationException('VEHICLE_ID_REQUIRED', 'vehicleId');
			}
			$out = $this->taxBenefit->payrollExportCsv($vehicleId, $yearMonth, $uid);
			return new DataDownloadResponse($out['body'], $out['filename'], 'text/csv; charset=UTF-8');
		} catch (\Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}
}
