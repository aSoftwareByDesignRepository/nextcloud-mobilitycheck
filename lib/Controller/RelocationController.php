<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\RelocationService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** §4.2a.4 — Fleet relocation queue after one-way bookings. */
class RelocationController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private RelocationService $relocations,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function list(): DataResponse
	{
		return $this->wrap(fn (): array => $this->relocations->listOpen($this->access->currentUserId()));
	}

	#[NoAdminRequired]
	public function complete(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$p = $this->payload();
			$notes = isset($p['notes']) ? (string)$p['notes'] : null;
			return $this->relocations->complete($id, $this->access->currentUserId(), $notes);
		});
	}
}
