<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\Exception\AppAccessDeniedException;
use OCA\MobilityCheck\Exception\BookingConflictException;
use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\RateLimitedException;
use OCA\MobilityCheck\Exception\ServiceMisconfigurationException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Map application exceptions to a deterministic JSON error envelope.
 *
 * The envelope shape is stable across every JSON endpoint so the shared
 * `js/common/api.js` error handling can pattern-match on `error.code`
 * without inspecting prose messages. Stack traces, SQL fragments and
 * filesystem paths are never echoed (§8, §13.14).
 */
final class ApiJsonErrorResponse
{
	public static function fromThrowable(\Throwable $e): DataResponse
	{
		if ($e instanceof ValidationException) {
			$context = $e->getContext();
			$field = $e->getField();
			if ($field !== null) {
				$context['field'] = $field;
			}
			$body = [
				'ok' => false,
				'error' => [
					'code' => $e->getMessage(),
					'type' => 'validation',
					'context' => $context,
				],
			];
			return new DataResponse($body, Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		if ($e instanceof RateLimitedException) {
			$response = new DataResponse([
				'ok' => false,
				'error' => [
					'code' => 'RATE_LIMITED',
					'type' => 'rate_limited',
					'context' => [
						'bucket' => $e->getBucket(),
						'retryAfter' => $e->getRetryAfterSeconds(),
					],
				],
			], Http::STATUS_TOO_MANY_REQUESTS);
			$response->addHeader('Retry-After', (string)$e->getRetryAfterSeconds());
			return $response;
		}
		if ($e instanceof BookingConflictException) {
			return new DataResponse([
				'ok' => false,
				'error' => [
					'code' => 'BOOKING_CONFLICT',
					'type' => 'conflict',
					'context' => [
						'competingBookingId' => $e->getCompetingBookingId(),
						'start' => $e->getStartDatetime(),
						'end' => $e->getEndDatetime(),
					],
				],
			], Http::STATUS_CONFLICT);
		}
		if ($e instanceof NotFoundException) {
			return new DataResponse([
				'ok' => false,
				'error' => ['code' => $e->getMessage(), 'type' => 'not_found'],
			], Http::STATUS_NOT_FOUND);
		}
		if ($e instanceof ServiceMisconfigurationException) {
			$code = $e->getMessage() !== '' ? $e->getMessage() : 'APPROVAL_CHAIN_MISCONFIGURED';
			return new DataResponse([
				'ok' => false,
				'error' => [
					'code' => $code,
					'type' => 'misconfiguration',
				],
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		if ($e instanceof ForbiddenException) {
			return new DataResponse([
				'ok' => false,
				'error' => ['code' => $e->getMessage(), 'type' => 'forbidden'],
			], Http::STATUS_FORBIDDEN);
		}
		if ($e instanceof AppAccessDeniedException) {
			return new DataResponse([
				'ok' => false,
				'error' => ['code' => 'access_denied', 'type' => 'access_denied'],
			], Http::STATUS_FORBIDDEN);
		}
		if ($e instanceof \InvalidArgumentException) {
			return new DataResponse([
				'ok' => false,
				'error' => ['code' => $e->getMessage() !== '' ? $e->getMessage() : 'INVALID_ARGUMENT', 'type' => 'validation'],
			], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		// Fall-through: never leak internal details (§8). Log full
		// detail server-side via Nextcloud's logger if available;
		// the client only ever sees an opaque code.
		try {
			Server::get(LoggerInterface::class)->error('MobilityCheck unhandled exception', [
				'exception' => $e,
				'app' => 'mobilitycheck',
			]);
		} catch (\Throwable) {
			// Logging itself must never fail the request.
		}
		return new DataResponse([
			'ok' => false,
			'error' => ['code' => 'SERVER_ERROR', 'type' => 'server'],
		], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
