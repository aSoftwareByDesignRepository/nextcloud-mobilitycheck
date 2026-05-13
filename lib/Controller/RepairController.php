<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\FileService;
use OCA\MobilityCheck\Service\RateLimitService;
use OCA\MobilityCheck\Service\RepairService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class RepairController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private RepairService $repairs,
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
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			return $this->repairs->list($userId, $this->payload());
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			return $this->repairs->get($id, $userId);
		});
	}

	#[NoAdminRequired]
	public function create(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			return $this->repairs->create($this->payload(), $userId);
		});
	}

	#[NoAdminRequired]
	public function update(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			return $this->repairs->update($id, $this->payload(), $userId);
		});
	}

	#[NoAdminRequired]
	public function uploadInvoice(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->rateLimits->consume(RateLimitService::BUCKET_UPLOAD, $userId);
			$this->access->requireAnyAppRole($userId);
			$file = $this->request->getUploadedFile('file');
			if ($file === null || !is_array($file) || !isset($file['tmp_name'], $file['name'], $file['type'])) {
				throw new \InvalidArgumentException('UPLOAD_REQUIRED');
			}
			$node = $this->files->storeUserUpload(
				$userId,
				FileService::FOLDER_REPAIR_INVOICES,
				(string)$file['name'],
				(string)$file['tmp_name'],
				(string)$file['type'],
			);
			return $this->repairs->attachInvoice($id, (string)$node['nodeId'], $userId);
		});
	}
}
