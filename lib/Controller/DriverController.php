<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\DriverService;
use OCA\MobilityCheck\Service\LineManagerService;
use OCA\MobilityCheck\Service\RateLimitService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;

class DriverController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private DriverService $drivers,
		private FileService $files,
		private LineManagerService $lineManagers,
		private IUserManager $userManager,
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
			$this->access->requireFleetOperationsRead($userId);
			if ($this->access->isFleetAdminOrManager($userId) || $this->access->isAuditor($userId)) {
				$rows = $this->drivers->list();
			} else {
				$scope = array_values(array_unique(array_merge(
					$this->lineManagers->listSupervisedDriverUserIds($userId),
					[$userId]
				)));
				$rows = $this->drivers->list($scope);
			}
			foreach ($rows as &$row) {
				$uid = (string)($row['user_id'] ?? '');
				$u = $uid !== '' ? $this->userManager->get($uid) : null;
				$dn = $u !== null ? trim((string)$u->getDisplayName()) : '';
				$row['displayName'] = $dn !== '' ? $dn : $uid;
			}
			unset($row);
			return $rows;
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$this->access->requireAnyAppRole($userId);
			$driver = $this->drivers->get($id);
			$this->assertMayViewDriverProfile($driver, $userId);
			return $driver;
		});
	}

	#[NoAdminRequired]
	public function create(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = (string)($this->request->getParam('userId', '') ?? '');
			$performedBy = $this->access->currentUserId();
			$this->access->requireFleetAdminOrManager($performedBy);
			return $this->drivers->ensureProfileForUser($userId, $performedBy);
		});
	}

	#[NoAdminRequired]
	public function update(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$performedBy = $this->access->currentUserId();
			$driver = $this->drivers->get($id);
			$isOwn = ($driver['user_id'] ?? '') === $performedBy;
			if (!$isOwn) {
				$this->access->requireFleetAdminOrManager($performedBy);
			}
			return $this->drivers->update($id, $this->payload(), $performedBy, $isOwn);
		});
	}

	#[NoAdminRequired]
	public function uploadLicence(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$performedBy = $this->access->currentUserId();
			$this->rateLimits->consume(RateLimitService::BUCKET_UPLOAD, $performedBy);
			$driver = $this->drivers->get($id);
			$isOwn = ($driver['user_id'] ?? '') === $performedBy;
			if (!$isOwn) {
				$this->access->requireFleetAdminOrManager($performedBy);
			}
			$file = $this->request->getUploadedFile('file');
			if ($file === null || !is_array($file) || !isset($file['tmp_name'], $file['name'], $file['type'])) {
				throw new \InvalidArgumentException('UPLOAD_REQUIRED');
			}
			$node = $this->files->storeUserUpload(
				$performedBy,
				FileService::FOLDER_LICENCE_SCANS,
				(string)$file['name'],
				(string)$file['tmp_name'],
				(string)$file['type'],
			);
			return $this->drivers->attachLicenceScan($id, (string)$node['nodeId'], $performedBy);
		});
	}

	#[NoAdminRequired]
	public function verifyLicence(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$performedBy = $this->access->currentUserId();
			$this->access->requireFleetAdminOrManager($performedBy);
			return $this->drivers->verifyLicence($id, $performedBy, (string)($this->request->getParam('note', '') ?? ''));
		});
	}

	#[NoAdminRequired]
	public function rejectLicence(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$performedBy = $this->access->currentUserId();
			$this->access->requireFleetAdminOrManager($performedBy);
			return $this->drivers->rejectLicence($id, $performedBy, (string)($this->request->getParam('reason', '') ?? ''));
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function compliance(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$driver = $this->drivers->get($id);
			$this->assertMayViewDriverProfile($driver, $userId);
			return $this->drivers->complianceDetail($id);
		});
	}

	/**
	 * @param array<string,mixed> $driver
	 */
	private function assertMayViewDriverProfile(array $driver, string $viewerId): void
	{
		$target = (string)($driver['user_id'] ?? '');
		if ($target === $viewerId) {
			return;
		}
		if ($this->access->isFleetAdminOrManager($viewerId) || $this->access->isAuditor($viewerId)) {
			return;
		}
		if ($this->access->isLineManager($viewerId) && $this->lineManagers->isActiveLineManagerForDriver($viewerId, $target)) {
			return;
		}
		throw new ForbiddenException('INSUFFICIENT_ROLE');
	}
}
