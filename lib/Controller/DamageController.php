<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\DamageService;
use OCA\MobilityCheck\Service\FileService;
use OCA\MobilityCheck\Service\RateLimitService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class DamageController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private DamageService $damage,
		private FileService $files,
		private RateLimitService $rateLimits,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function list(): DataResponse
	{
		return $this->wrap(function (): array {
			$this->access->requireAnyAppRole($this->access->currentUserId());
			return $this->damage->list(array_merge($this->payload(), [
				'viewerUserId' => $this->access->currentUserId(),
			]));
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			$row = $this->damage->get($id);
			$this->damage->assertUserMayViewDamageReport($userId, $row);
			return $this->damage->fullDetail($id);
		});
	}

	#[NoAdminRequired]
	public function create(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			return $this->damage->create($this->payload(), $userId);
		});
	}

	#[NoAdminRequired]
	public function uploadPhoto(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->rateLimits->consume(RateLimitService::BUCKET_UPLOAD, $userId);
			$file = $this->request->getUploadedFile('file');
			if ($file === null || !is_array($file) || !isset($file['tmp_name'], $file['name'], $file['type'])) {
				throw new \InvalidArgumentException('UPLOAD_REQUIRED');
			}
			$node = $this->files->storeUserUpload(
				$userId,
				FileService::FOLDER_DAMAGE_PHOTOS,
				(string)$file['name'],
				(string)$file['tmp_name'],
				(string)$file['type'],
			);
			return $this->damage->attachPhoto($id, (string)$node['nodeId'], $userId);
		});
	}

	/**
	 * §4.6.4 — Upload a handover photo (pre-trip or post-trip evidence)
	 * tied to a booking. Reuses `mc_damage_photos` (the schema already
	 * carries `booking_id` and `evidence_type`).
	 */
	#[NoAdminRequired]
	public function uploadHandoverPhoto(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->rateLimits->consume(RateLimitService::BUCKET_UPLOAD, $userId);
			$evidenceType = (string)($this->request->getParam('evidenceType', '') ?? '');
			if ($evidenceType !== 'pre_trip' && $evidenceType !== 'post_trip') {
				throw new \InvalidArgumentException('EVIDENCE_TYPE_REQUIRED');
			}
			$file = $this->request->getUploadedFile('file');
			if ($file === null || !is_array($file) || !isset($file['tmp_name'], $file['name'], $file['type'])) {
				throw new \InvalidArgumentException('UPLOAD_REQUIRED');
			}
			$node = $this->files->storeUserUpload(
				$userId,
				FileService::FOLDER_DAMAGE_PHOTOS,
				(string)$file['name'],
				(string)$file['tmp_name'],
				(string)$file['type'],
			);
			return $this->damage->attachHandoverPhoto($id, (string)$node['nodeId'], $evidenceType, $userId);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function handoverPhotos(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			return $this->damage->listHandoverPhotos($id, $userId);
		});
	}

	#[NoAdminRequired]
	public function updateStatus(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireFleetAdminOrManager($userId);
			$payload = $this->payload();
			$rawAck = $payload['safetyCriticalClosureAcknowledged'] ?? $this->request->getParam('safetyCriticalClosureAcknowledged');
			$ack = $rawAck === true || $rawAck === 1 || $rawAck === '1'
				|| (is_string($rawAck) && strtolower($rawAck) === 'true');
			return $this->damage->updateStatus(
				$id,
				(string)($payload['status'] ?? $this->request->getParam('status', '') ?? ''),
				$userId,
				isset($payload['reason']) ? (string)$payload['reason'] : (string)($this->request->getParam('reason', '') ?? ''),
				$ack,
			);
		});
	}

	#[NoAdminRequired]
	public function amend(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			return $this->damage->amend(
				$id,
				$this->payload(),
				$userId,
				(string)($this->request->getParam('reason', '') ?? '')
			);
		});
	}
}
