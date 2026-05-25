<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\CurrencyCatalog;
use OCA\MobilityCheck\Service\TimezoneCatalog;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Throwable;

/**
 * Read-only catalog endpoints for searchable timezone and currency pickers.
 */
class CatalogApiController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private TimezoneCatalog $timezoneCatalog,
		private CurrencyCatalog $currencyCatalog,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function timezones(): DataResponse
	{
		try {
			$this->access->requireFleetAdmin($this->access->currentUserId());
			return new DataResponse([
				'ok' => true,
				'data' => $this->timezoneCatalog->forApi(),
			]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function currencies(): DataResponse
	{
		try {
			$this->access->requireFleetAdmin($this->access->currentUserId());
			return new DataResponse([
				'ok' => true,
				'data' => $this->currencyCatalog->forApi(),
			]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}
}
