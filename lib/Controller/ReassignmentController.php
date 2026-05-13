<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\ReassignmentService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ReassignmentController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private ReassignmentService $reassignment,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listOpen(): DataResponse
	{
		return $this->wrap(fn (): array => $this->reassignment->listOpenSuggestions($this->access->currentUserId()));
	}

	#[NoAdminRequired]
	public function accept(int $id): DataResponse
	{
		return $this->wrap(fn (): array => $this->reassignment->acceptSuggestion($id, $this->access->currentUserId()));
	}

	#[NoAdminRequired]
	public function dismiss(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$this->reassignment->dismissSuggestion($id, $this->access->currentUserId());
			return ['dismissed' => true];
		});
	}
}
