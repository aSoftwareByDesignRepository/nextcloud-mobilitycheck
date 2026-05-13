<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\PrivateVehicleService;
use OCA\MobilityCheck\Service\ReimbursementClaimService;
use OCA\MobilityCheck\Service\ReimbursementRateService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ReimbursementController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private ReimbursementRateService $rates,
		private PrivateVehicleService $privateVehicles,
		private ReimbursementClaimService $claims,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function rates(): DataResponse
	{
		return $this->wrap(function (): array {
			$this->access->requireAnyAppRole($this->access->currentUserId());
			return $this->rates->listActive(
				(string)($this->request->getParam('jurisdiction', '') ?? ''),
				(string)($this->request->getParam('vehicleType', '') ?? ''),
			);
		});
	}

	#[NoAdminRequired]
	public function ratesCreate(): DataResponse
	{
		return $this->wrap(fn (): array => $this->rates->adminCreateTier($this->payload(), $this->access->currentUserId()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function privateList(): DataResponse
	{
		return $this->wrap(function (): array {
			$f = (int)($this->request->getParam('driverProfileId', 0) ?: 0);
			return $this->privateVehicles->listForViewer($this->access->currentUserId(), $f > 0 ? $f : null);
		});
	}

	#[NoAdminRequired]
	public function privateCreate(): DataResponse
	{
		return $this->wrap(function (): array {
			$p = $this->payload();
			$profileId = (int)($p['driverProfileId'] ?? $p['driver_profile_id'] ?? 0);
			if ($profileId > 0) {
				return $this->privateVehicles->createForDriverProfile($profileId, $p, $this->access->currentUserId());
			}
			return $this->privateVehicles->createOwn($p, $this->access->currentUserId());
		});
	}

	#[NoAdminRequired]
	public function privateUpdate(int $id): DataResponse
	{
		return $this->wrap(fn (): array => $this->privateVehicles->update($id, $this->payload(), $this->access->currentUserId()));
	}

	#[NoAdminRequired]
	public function privateDeactivate(int $id): DataResponse
	{
		return $this->wrap(fn (): array => $this->privateVehicles->deactivate($id, $this->access->currentUserId()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function claimsList(): DataResponse
	{
		return $this->wrap(function (): array {
			return $this->claims->list([
				'status' => (string)($this->request->getParam('status', '') ?? ''),
				'driverUserId' => (string)($this->request->getParam('driverUserId', '') ?? ''),
			], $this->access->currentUserId());
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function claimsShow(int $id): DataResponse
	{
		return $this->wrap(fn (): array => $this->claims->get($id, $this->access->currentUserId()));
	}

	#[NoAdminRequired]
	public function claimsCreate(): DataResponse
	{
		return $this->wrap(fn (): array => $this->claims->createDraft($this->payload(), $this->access->currentUserId()));
	}

	#[NoAdminRequired]
	public function claimsUpdate(int $id): DataResponse
	{
		return $this->wrap(fn (): array => $this->claims->updateDraft($id, $this->payload(), $this->access->currentUserId()));
	}

	#[NoAdminRequired]
	public function claimsSubmit(int $id): DataResponse
	{
		return $this->wrap(fn (): array => $this->claims->submit($id, $this->access->currentUserId()));
	}

	#[NoAdminRequired]
	public function claimsApprove(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$p = $this->payload();
			$v = $p['distanceVerifiedKm'] ?? $p['distance_verified_km'] ?? null;
			$vk = $v !== null && $v !== '' ? (int)$v : null;
			return $this->claims->approve($id, $this->access->currentUserId(), $vk);
		});
	}

	#[NoAdminRequired]
	public function claimsReject(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$p = $this->payload();
			return $this->claims->reject($id, $this->access->currentUserId(), (string)($p['reason'] ?? ''));
		});
	}

	#[NoAdminRequired]
	public function claimsMarkPaid(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$p = $this->payload();
			return $this->claims->markPaid($id, $this->access->currentUserId(), (string)($p['paymentReference'] ?? $p['payment_reference'] ?? ''));
		});
	}
}
