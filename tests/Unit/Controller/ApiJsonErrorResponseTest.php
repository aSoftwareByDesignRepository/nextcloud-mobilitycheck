<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Controller;

use OCA\MobilityCheck\Controller\ApiJsonErrorResponse;
use OCA\MobilityCheck\Exception\BookingConflictException;
use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;

/**
 * The auditor will rip the API apart if we ever leak stack traces. This
 * suite locks the shape of every "known" error path so a regression is
 * caught at PR time.
 */
final class ApiJsonErrorResponseTest extends TestCase
{
	public function testValidationMapsTo422(): void
	{
		$res = ApiJsonErrorResponse::fromThrowable(new ValidationException('FOO', 'field'));
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $res->getStatus());
		$body = $res->getData();
		$this->assertFalse($body['ok']);
		$this->assertSame('FOO', $body['error']['code']);
		$this->assertSame('validation', $body['error']['type']);
		$this->assertSame('field', $body['error']['context']['field']);
	}

	public function testForbiddenMapsTo403(): void
	{
		$res = ApiJsonErrorResponse::fromThrowable(new ForbiddenException('NO'));
		$this->assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
		$this->assertSame('forbidden', $res->getData()['error']['type']);
	}

	public function testNotFoundMapsTo404(): void
	{
		$res = ApiJsonErrorResponse::fromThrowable(new NotFoundException('NO'));
		$this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
	}

	public function testBookingConflictExposesContextWithoutLeak(): void
	{
		$res = ApiJsonErrorResponse::fromThrowable(new BookingConflictException(42, '2026-05-11 09:00:00', '2026-05-11 11:00:00'));
		$this->assertSame(Http::STATUS_CONFLICT, $res->getStatus());
		$ctx = $res->getData()['error']['context'];
		$this->assertSame(42, $ctx['competingBookingId']);
		$this->assertSame('2026-05-11 09:00:00', $ctx['start']);
		$this->assertSame('2026-05-11 11:00:00', $ctx['end']);
	}

	public function testInvalidArgumentMapsTo422WithCode(): void
	{
		$res = ApiJsonErrorResponse::fromThrowable(new \InvalidArgumentException('INVALID_TIMEZONE'));
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $res->getStatus());
		$body = $res->getData();
		$this->assertSame('INVALID_TIMEZONE', $body['error']['code']);
		$this->assertSame('validation', $body['error']['type']);
	}

	public function testGenericThrowableDoesNotLeakMessage(): void
	{
		$res = ApiJsonErrorResponse::fromThrowable(new \RuntimeException('Connection refused on /etc/passwd'));
		$body = $res->getData();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $res->getStatus());
		$this->assertSame('SERVER_ERROR', $body['error']['code']);
		$this->assertArrayNotHasKey('message', $body['error'] ?? []);
		$this->assertStringNotContainsString('passwd', json_encode($body));
	}
}
