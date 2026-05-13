<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\ExportService;
use OCA\MobilityCheck\Service\OdometerReadingService;
use OCA\MobilityCheck\Service\RateLimitService;
use OCA\MobilityCheck\Service\SearchProfileService;
use OCA\MobilityCheck\Service\VehicleFeatureService;
use OCA\MobilityCheck\Service\VehicleSearchService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class AppendixApiController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private VehicleSearchService $search,
		private OdometerReadingService $odometer,
		private ExportService $export,
		private SearchProfileService $searchProfiles,
		private VehicleFeatureService $features,
		private RateLimitService $rateLimits,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function vehicleSearch(): DataResponse
	{
		return $this->wrap(function (): array {
			$uid = $this->access->currentUserId();
			$this->rateLimits->consume(RateLimitService::BUCKET_VEHICLE_SEARCH, $uid);
			$p = $this->payload();
			$subject = trim((string)($p['driverUserId'] ?? $p['driver_user_id'] ?? ''));
			return $this->search->search(
				$uid,
				(string)($p['from'] ?? $p['fromUtc'] ?? ''),
				(string)($p['to'] ?? $p['toUtc'] ?? ''),
				is_array($p['requirements'] ?? null) ? $p['requirements'] : [],
				$subject !== '' ? $subject : null,
			);
		});
	}

	#[NoAdminRequired]
	public function odometerCreate(): DataResponse
	{
		return $this->wrap(fn (): array => $this->odometer->create($this->payload(), $this->access->currentUserId()));
	}

	#[NoAdminRequired]
	public function exportRequest(): DataResponse
	{
		return $this->wrap(fn (): array => $this->export->requestLogbookCsv($this->access->currentUserId(), $this->payload()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportDownload(string $token): DataDownloadResponse|DataResponse
	{
		try {
			$meta = $this->export->resolveToken($this->access->currentUserId(), $token);
			$body = file_get_contents($meta['path']);
			if ($body === false) {
				throw new \RuntimeException('EXPORT_FILE_MISSING');
			}
			return new DataDownloadResponse($body, $meta['filename'], $meta['mime']);
		} catch (\Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportHistory(): DataResponse
	{
		return $this->wrap(fn (): array => $this->export->listForUser($this->access->currentUserId()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function searchProfilesList(): DataResponse
	{
		return $this->wrap(fn (): array => $this->searchProfiles->list($this->access->currentUserId()));
	}

	#[NoAdminRequired]
	public function searchProfilesCreate(): DataResponse
	{
		return $this->wrap(fn (): array => $this->searchProfiles->create($this->access->currentUserId(), $this->payload()));
	}

	#[NoAdminRequired]
	public function searchProfilesUpdate(int $id): DataResponse
	{
		return $this->wrap(fn (): array => $this->searchProfiles->update($id, $this->access->currentUserId(), $this->payload()));
	}

	#[NoAdminRequired]
	public function searchProfilesDelete(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$this->searchProfiles->delete($id, $this->access->currentUserId());
			return ['deleted' => true];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function featureKeys(): DataResponse
	{
		return $this->wrap(static fn (): array => ['keys' => VehicleFeatureService::catalogKeys()]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function vehicleFeaturesList(): DataResponse
	{
		return $this->wrap(function (): array {
			$this->access->requireAnyAppRole($this->access->currentUserId());
			$vid = (int)($this->request->getParam('vehicleId', 0) ?: 0);
			if ($vid <= 0) {
				throw new \OCA\MobilityCheck\Exception\ValidationException('VEHICLE_REQUIRED', 'vehicle_id');
			}
			return $this->features->listVehicle($vid);
		});
	}

	#[NoAdminRequired]
	public function vehicleFeaturesCreate(): DataResponse
	{
		return $this->wrap(function (): array {
			$p = $this->payload();
			$vid = (int)($p['vehicleId'] ?? $p['vehicle_id'] ?? 0);
			if ($vid <= 0) {
				throw new \OCA\MobilityCheck\Exception\ValidationException('VEHICLE_REQUIRED', 'vehicle_id');
			}
			return $this->features->add($vid, $p, $this->access->currentUserId());
		});
	}

	#[NoAdminRequired]
	public function vehicleFeaturesDelete(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$this->features->delete($id, $this->access->currentUserId());
			return ['deleted' => true];
		});
	}
}
