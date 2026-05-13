<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\ChargebackService;
use OCA\MobilityCheck\Service\DamageService;
use OCA\MobilityCheck\Service\NotificationService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * §4.6.8 + §4.7.5 — Driver chargeback endpoints (acknowledge / dispute /
 * resolve) plus a separate endpoint for fleet managers to create a
 * chargeback row against a damage report.
 */
class ChargebackController extends BaseApiController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private ChargebackService $chargebacks,
		private DamageService $damage,
		private NotificationService $notifications,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function list(): DataResponse
	{
		return $this->wrap(function (): array {
			$userId = $this->access->currentUserId();
			$driver = (string)($this->request->getParam('driverUserId', '') ?? '');
			return $this->chargebacks->listForViewer($userId, $driver !== '' ? $driver : null);
		});
	}

	#[NoAdminRequired]
	public function acknowledge(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$row = $this->chargebacks->acknowledge($id, $userId);
			return $row;
		});
	}

	#[NoAdminRequired]
	public function dispute(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$payload = $this->payload();
			$reason = (string)($payload['reason'] ?? '');
			$row = $this->chargebacks->dispute($id, $userId, $reason);
			$this->notifyFleetOfDispute($row, $reason, $userId);
			return $row;
		});
	}

	#[NoAdminRequired]
	public function resolve(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$payload = $this->payload();
			$resolution = (string)($payload['resolution'] ?? '');
			$reason = (string)($payload['reason'] ?? '');
			$newAmount = isset($payload['newAmountMinor']) ? (int)$payload['newAmountMinor'] : null;
			$row = $this->chargebacks->resolveDispute($id, $userId, $resolution, $reason, $newAmount);
			$this->notifyDriverOfResolution($row, $resolution, $reason);
			return $row;
		});
	}

	#[NoAdminRequired]
	public function createDamageChargeback(int $id): DataResponse
	{
		return $this->wrap(function () use ($id): array {
			$userId = $this->access->currentUserId();
			$payload = $this->payload();
			$damageReport = $this->damage->get($id);
			$chargeableUserId = (string)($payload['chargeableUserId'] ?? $payload['chargeable_user_id'] ?? '');
			$amountMinor = (int)($payload['amountMinor'] ?? $payload['amount_minor'] ?? 0);
			$notes = (string)($payload['notes'] ?? '');
			$bookingId = $damageReport['booking_id'] ?? null;
			$costId = $this->chargebacks->createDamageChargeback([
				'damageReportId' => $id,
				'bookingId' => $bookingId !== null ? (int)$bookingId : null,
				'vehicleId' => (int)$damageReport['vehicle_id'],
				'chargeableUserId' => $chargeableUserId,
				'amountMinor' => $amountMinor,
				'notes' => $notes !== '' ? $notes : null,
			], $userId);
			$this->damage->setChargeableUser($id, $chargeableUserId, $userId);
			$this->notifyDriverOfDamageChargeback($chargeableUserId, $id, $amountMinor);
			return ['cost_entry_id' => $costId];
		});
	}

	private function notifyFleetOfDispute(array $row, string $reason, string $driverId): void
	{
		$recipients = $this->access->fleetManagerRecipients();
		if ($recipients === []) {
			return;
		}
		$ctx = [
			'costEntryId' => (int)$row['id'],
			'driverUserId' => $driverId,
			'amountMinor' => (int)$row['amount_gross_minor'],
			'reason' => $reason,
		];
		$this->notifications->sendMany(
			NotificationService::TYPE_CHARGEBACK_DISPUTED,
			$recipients,
			'cost_entry',
			(int)$row['id'],
			'chargeback.disputed:{userId}:' . (int)$row['id'],
			$ctx,
		);
	}

	private function notifyDriverOfResolution(array $row, string $resolution, string $reason): void
	{
		$driverId = (string)($row['charge_driver_user_id'] ?? '');
		if ($driverId === '') {
			return;
		}
		$this->notifications->send(
			NotificationService::TYPE_CHARGEBACK_RESOLVED,
			$driverId,
			'cost_entry',
			(int)$row['id'],
			'chargeback.resolved:' . (int)$row['id'] . ':' . $resolution,
			[
				'costEntryId' => (int)$row['id'],
				'resolution' => $resolution,
				'reason' => $reason,
				'amountMinor' => (int)$row['amount_gross_minor'],
			],
		);
	}

	private function notifyDriverOfDamageChargeback(string $driverId, int $damageReportId, int $amountMinor): void
	{
		if ($driverId === '') {
			return;
		}
		$this->notifications->send(
			NotificationService::TYPE_CHARGEBACK_CREATED,
			$driverId,
			'damage_report',
			$damageReportId,
			'chargeback.created:damage:' . $damageReportId,
			[
				'damageReportId' => $damageReportId,
				'amountMinor' => $amountMinor,
			],
		);
	}
}
