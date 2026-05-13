<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\StationService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class StationController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private StationService $stations,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function list(): DataResponse
	{
		return $this->wrap(fn (): array => $this->stations->list($this->access->currentUserId(), false));
	}

	#[NoAdminRequired]
	public function create(): DataResponse
	{
		return $this->wrap(fn (): array => $this->stations->create($this->payload(), $this->access->currentUserId()));
	}

	#[NoAdminRequired]
	public function update(int $id): DataResponse
	{
		return $this->wrap(fn (): array => $this->stations->update($id, $this->payload(), $this->access->currentUserId()));
	}

	#[NoAdminRequired]
	public function deactivate(int $id): DataResponse
	{
		return $this->wrap(fn (): array => $this->stations->deactivate($id, $this->access->currentUserId()));
	}
}
