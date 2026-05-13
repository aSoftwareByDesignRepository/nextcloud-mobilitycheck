<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\AppInfo\Application;
use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\RateLimitedException;
use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\BookingService;
use OCA\MobilityCheck\Service\RateLimitService;
use OCA\MobilityCheck\Service\VehicleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * §4.6.9 / §13.37 — QR-code assisted check-out / check-in.
 *
 * The QR sticker carries `vehicle_id` + a rotating signed token. The handler
 * verifies that the token matches the stored SHA-256 hash, then **never**
 * acts as a permission grant on its own — the real authorisation still comes
 * from `AccessControlService` plus the user's existing booking on the
 * vehicle. Anonymous scans hit Nextcloud login first; nothing about the
 * vehicle is leaked to a public scan.
 */
class QrCheckinController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private VehicleService $vehicles,
		private IDBConnection $db,
		private IURLGenerator $urls,
		private RateLimitService $rateLimits,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Land an authenticated user on the right handover form given the scanned
	 * vehicle id + presented token. Anonymous users are redirected to login
	 * by the standard `NoAdminRequired` + framework auth flow.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function scan(int $vehicleId): TemplateResponse|RedirectResponse
	{
		$userId = $this->access->currentUserId();
		// Note: `canUseApp` is enforced by AppAccessMiddleware before we get here.
		try {
			$this->rateLimits->consume(RateLimitService::BUCKET_QR_SCAN, $userId);
		} catch (RateLimitedException $e) {
			$response = new TemplateResponse(
				Application::APP_ID,
				'qr-result',
				[
					'kind' => 'rate_limited',
					'vehicleId' => $vehicleId,
					'retryAfter' => $e->getRetryAfterSeconds(),
				],
				TemplateResponse::RENDER_AS_USER,
			);
			$response->setStatus(Http::STATUS_TOO_MANY_REQUESTS);
			$response->addHeader('Retry-After', (string)$e->getRetryAfterSeconds());
			return $response;
		}
		$presented = (string)($this->request->getParam('t', '') ?? '');
		try {
			$vehicle = $this->vehicles->get($vehicleId);
		} catch (NotFoundException) {
			return new TemplateResponse(
				Application::APP_ID,
				'qr-result',
				[
					'kind' => 'unknown_vehicle',
					'vehicleId' => $vehicleId,
				],
				TemplateResponse::RENDER_AS_USER,
			);
		}
		if (!$this->vehicles->verifyQrToken($vehicleId, $presented)) {
			return new TemplateResponse(
				Application::APP_ID,
				'qr-result',
				[
					'kind' => 'token_invalid',
					'vehicleId' => $vehicleId,
					'vehicleName' => $vehicle['internal_name'] ?? '',
				],
				TemplateResponse::RENDER_AS_USER,
			);
		}
		$booking = $this->findRelevantBooking($vehicleId, $userId);
		if ($booking === null) {
			return new TemplateResponse(
				Application::APP_ID,
				'qr-result',
				[
					'kind' => 'no_booking',
					'vehicleId' => $vehicleId,
					'vehicleName' => $vehicle['internal_name'] ?? '',
				],
				TemplateResponse::RENDER_AS_USER,
			);
		}
		$bookingId = (int)$booking['id'];
		$status = (string)$booking['status'];
		if ($status === BookingService::STATUS_APPROVED) {
			$url = $this->urls->linkToRoute('mobilitycheck.page.bookingDetail', ['id' => $bookingId])
				. '#checkout-form';
			return new RedirectResponse($url);
		}
		if ($status === BookingService::STATUS_ACTIVE) {
			$url = $this->urls->linkToRoute('mobilitycheck.page.bookingDetail', ['id' => $bookingId])
				. '#checkin-form';
			return new RedirectResponse($url);
		}
		return new TemplateResponse(
			Application::APP_ID,
			'qr-result',
			[
				'kind' => 'no_booking',
				'vehicleId' => $vehicleId,
				'vehicleName' => $vehicle['internal_name'] ?? '',
			],
			TemplateResponse::RENDER_AS_USER,
		);
	}

	/** Rotate the QR token. Admin / fleet admin only. */
	#[NoAdminRequired]
	public function rotate(int $vehicleId): DataResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireFleetAdminOrManagerOrAppAdmin($userId);
		$plaintext = $this->vehicles->rotateQrToken($vehicleId, $userId);
		// Build the absolute scan URL with the freshly minted token. Printed
		// on the dashboard / windscreen sticker.
		$url = $this->urls->getAbsoluteURL(
			$this->urls->linkToRoute('mobilitycheck.qrCheckin.scan', ['vehicleId' => $vehicleId])
		) . '?t=' . rawurlencode($plaintext);
		return new DataResponse([
			'vehicleId' => $vehicleId,
			'scanUrl' => $url,
			// Plaintext token is returned ONCE — the caller prints / displays
			// it immediately. After this response, only the SHA-256 hash is
			// retrievable.
			'token' => $plaintext,
		]);
	}

	/**
	 * Find the booking we should land the user on:
	 *   1. an `approved` booking starting in ≤ 60 min → checkout
	 *   2. an `active` booking right now → checkin
	 * Otherwise null.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findRelevantBooking(int $vehicleId, string $userId): ?array
	{
		$now = gmdate('Y-m-d H:i:s');
		$sixtyMinAhead = gmdate('Y-m-d H:i:s', time() + 60 * 60);
		// Active first — if the trip is in progress, we want check-in.
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_bookings')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('driver_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(BookingService::STATUS_ACTIVE)))
			->orderBy('start_datetime', 'DESC')
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		if ($row !== false) {
			return $row;
		}
		// Otherwise an approved booking starting within the next hour.
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_bookings')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('driver_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(BookingService::STATUS_APPROVED)))
			->andWhere($qb->expr()->lte('start_datetime', $qb->createNamedParameter($sixtyMinAhead)))
			->andWhere($qb->expr()->gte('end_datetime', $qb->createNamedParameter($now)))
			->orderBy('start_datetime', 'ASC')
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}
}
