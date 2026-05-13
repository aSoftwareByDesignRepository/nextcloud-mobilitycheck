<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Service;

use OCA\MobilityCheck\Service\BookingIcalService;
use OCA\MobilityCheck\Service\NotificationService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class BookingIcalServiceTest extends TestCase
{
	public function testBuildReturnsNullForUnsupportedType(): void
	{
		$s = $this->makeService();
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$this->assertNull($s->buildForEmail(NotificationService::TYPE_BOOKING_REJECTED, [
			'bookingId' => 1,
			'start' => '2026-01-10 08:00:00',
			'end' => '2026-01-10 10:00:00',
		], $l, 1));
	}

	public function testBuildReturnsNullWhenWindowInvalid(): void
	{
		$s = $this->makeService();
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$this->assertNull($s->buildForEmail(NotificationService::TYPE_BOOKING_APPROVED, [
			'bookingId' => 1,
			'start' => '2026-01-10 12:00:00',
			'end' => '2026-01-10 10:00:00',
		], $l, 1));
	}

	public function testBuildProducesPublishCalendarWithUtc(): void
	{
		$s = $this->makeService();
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static function (string $msg, array $args = []): string {
			if ($args === []) {
				return $msg;
			}
			$i = 0;
			return (string)preg_replace_callback('/%[sd]/', static function () use ($args, &$i) {
				return (string)($args[$i++] ?? '');
			}, $msg);
		});
		$ics = $s->buildForEmail(NotificationService::TYPE_BOOKING_APPROVED, [
			'bookingId' => 42,
			'vehicleName' => 'Pool 1',
			'driverName' => 'Alex',
			'purpose' => 'Client visit',
			'start' => '2026-06-01 14:00:00',
			'end' => '2026-06-01 16:30:00',
		], $l, 42);
		$this->assertIsString($ics);
		$this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
		$this->assertStringContainsString('METHOD:PUBLISH', $ics);
		$this->assertStringContainsString('DTSTART:20260601T140000Z', $ics);
		$this->assertStringContainsString('DTEND:20260601T163000Z', $ics);
		$this->assertStringContainsString('UID:mobilitycheck-booking-42@example.test', $ics);
		$this->assertStringContainsString('STATUS:CONFIRMED', $ics);
		$this->assertStringContainsString('https://cloud.example.test/index.php/apps/mobilitycheck/bookings/42', $ics);
	}

	public function testBuildUsesNewEndForExtended(): void
	{
		$s = $this->makeService();
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturn('x');
		$ics = $s->buildForEmail(NotificationService::TYPE_BOOKING_EXTENDED, [
			'bookingId' => 7,
			'vehicleName' => 'V',
			'start' => '2026-01-01 09:00:00',
			'newEnd' => '2026-01-01 13:00:00',
		], $l, 7);
		$this->assertIsString($ics);
		$this->assertStringContainsString('DTEND:20260101T130000Z', $ics);
	}

	public function testEscapesSpecialCharactersInSummary(): void
	{
		$s = $this->makeService();
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static function (string $msg, array $args = []): string {
			if ($args === []) {
				return $msg;
			}
			$i = 0;
			return (string)preg_replace_callback('/%[sd]/', static function () use ($args, &$i) {
				return (string)($args[$i++] ?? '');
			}, $msg);
		});
		$ics = $s->buildForEmail(NotificationService::TYPE_BOOKING_APPROVED, [
			'bookingId' => 1,
			'vehicleName' => 'Semi;colon,comma\\trail',
			'start' => '2026-01-01 00:00:00',
			'end' => '2026-01-01 01:00:00',
		], $l, 1);
		$this->assertIsString($ics);
		$this->assertStringContainsString('Semi\\;colon\\,comma\\\\trail', $ics);
	}

	private function makeService(): BookingIcalService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnMap([
			['mail_domain', 'localhost', 'example.test'],
		]);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturnCallback(static function (string $route, array $args = []): string {
			$id = (int)($args['id'] ?? 0);
			return 'https://cloud.example.test/index.php/apps/mobilitycheck/bookings/' . $id;
		});
		return new BookingIcalService($config, $url);
	}
}
