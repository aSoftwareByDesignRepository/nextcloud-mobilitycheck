<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\LogbookService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class LogbookController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private LogbookService $logbook,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function list(): DataResponse
	{
		return $this->wrap(function (): array {
			$uid = $this->access->currentUserId();
			return $this->logbook->list([
				'vehicleId' => (int)($this->request->getParam('vehicleId', 0) ?: 0),
				'from' => (string)($this->request->getParam('from', '') ?? ''),
				'to' => (string)($this->request->getParam('to', '') ?? ''),
				'driverUserId' => (string)($this->request->getParam('driverUserId', '') ?? ''),
				'confirmedOnly' => ((string)($this->request->getParam('confirmedOnly', '') ?? '')) === '1',
			], $uid);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function gaps(): DataResponse
	{
		return $this->wrap(function (): array {
			$uid = $this->access->currentUserId();
			return $this->logbook->gaps(
				(int)($this->request->getParam('vehicleId', 0) ?: 0),
				(string)($this->request->getParam('from', '') ?? ''),
				(string)($this->request->getParam('to', '') ?? ''),
				$uid,
			);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): DataResponse
	{
		return $this->wrap(fn (): array => $this->logbook->get($id, $this->access->currentUserId()));
	}

	#[NoAdminRequired]
	public function create(): DataResponse
	{
		return $this->wrap(fn (): array => $this->logbook->createManual($this->payload(), $this->access->currentUserId()));
	}

	#[NoAdminRequired]
	public function update(int $id): DataResponse
	{
		return $this->wrap(fn (): array => $this->logbook->updateDraft($id, $this->payload(), $this->access->currentUserId()));
	}

	#[NoAdminRequired]
	public function confirm(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$p = $this->payload();
			$attest = filter_var($p['attestConfirmed'] ?? $p['attest_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN);
			return $this->logbook->confirm($id, $this->access->currentUserId(), $attest);
		});
	}

	#[NoAdminRequired]
	public function amend(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$p = $this->payload();
			$reason = (string)($p['reason'] ?? $p['amendment_reason'] ?? '');
			unset($p['reason'], $p['amendment_reason']);
			return $this->logbook->amend($id, $reason, $p, $this->access->currentUserId());
		});
	}
}
