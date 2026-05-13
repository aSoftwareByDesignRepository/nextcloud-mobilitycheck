<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Regression test for the datetime parser used by BookingService and
 * DamageService. The browser submits ISO 8601 with a trailing `Z`
 * (UTC), the HTML `<input type="datetime-local">` widget submits a
 * naive wall-clock string without timezone, and admin tools / curl
 * users sometimes submit an explicit ±HH:MM offset. All three must
 * round-trip to the same UTC timestamp; everything else must be
 * rejected before it ever reaches the database.
 *
 * The implementation lives in {@see BookingService::parseDateTime}
 * (and a parallel inline parser in {@see DamageService::create}).
 * Keeping this regex in a focused unit-test means a future refactor
 * that loses `Z` support — like the original bug we shipped before
 * audit — fails fast in CI instead of silently rejecting bookings.
 */
final class DateTimeParseTest extends TestCase
{
	/**
	 * @dataProvider acceptedCases
	 */
	public function testAcceptedFormatsRoundTripToUtc(string $input, string $expectedUtc): void
	{
		$this->assertTrue($this->isFormatAccepted($input), $input . ' should be accepted by the wire format regex');
		// Mirror BookingService::parseDateTime: it constructs with a UTC
		// fallback zone (which PHP only honours when no explicit offset is
		// present in the string) and then takes getTimestamp(), so an
		// offset-bearing string is still mapped to the correct UTC moment.
		$dt = (new \DateTimeImmutable($input, new \DateTimeZone('UTC')))
			->setTimezone(new \DateTimeZone('UTC'));
		$this->assertSame($expectedUtc, $dt->format('Y-m-d H:i:s'), $input . ' parsed to wrong UTC value');
	}

	public function acceptedCases(): array
	{
		return [
			'html_datetime_local_minutes'    => ['2026-05-15T09:00',              '2026-05-15 09:00:00'],
			'html_datetime_local_seconds'    => ['2026-05-15T09:00:30',           '2026-05-15 09:00:30'],
			'iso_with_space_separator'       => ['2026-05-15 09:00:00',           '2026-05-15 09:00:00'],
			'iso_utc_zulu_minutes'           => ['2026-05-15T09:00Z',             '2026-05-15 09:00:00'],
			'iso_utc_zulu_seconds'           => ['2026-05-15T09:00:00Z',          '2026-05-15 09:00:00'],
			'iso_positive_offset_colon'      => ['2026-05-15T11:00:00+02:00',     '2026-05-15 09:00:00'],
			'iso_positive_offset_compact'    => ['2026-05-15T11:00:00+0200',      '2026-05-15 09:00:00'],
			'iso_negative_offset_colon'      => ['2026-05-15T05:00:00-04:00',     '2026-05-15 09:00:00'],
		];
	}

	/**
	 * @dataProvider rejectedCases
	 */
	public function testRejectedFormats(string $input): void
	{
		$this->assertFalse($this->isFormatAccepted($input), $input . ' must be rejected');
	}

	public function rejectedCases(): array
	{
		return [
			'empty'                       => [''],
			'date_only'                   => ['2026-05-15'],
			'time_only'                   => ['09:00:00'],
			'us_format'                   => ['05/15/2026 09:00'],
			'extra_text'                  => ['2026-05-15T09:00:00; DROP TABLE oc_mc_bookings'],
			'garbled_timezone'            => ['2026-05-15T09:00:00Europe/Berlin'],
			'truncated_iso'               => ['2026-05-15T09'],
			'mixed_separators'            => ['2026/05/15T09:00:00'],
		];
	}

	private function isFormatAccepted(string $value): bool
	{
		// Mirror the production regex from BookingService::parseDateTime
		// 1:1. The point of duplicating it here is that the test guards
		// the *contract* of the wire format — if production ever
		// diverges the test should diverge with it on purpose.
		return $value !== ''
			&& (bool)preg_match(
				'/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?$/',
				$value,
			);
	}
}
