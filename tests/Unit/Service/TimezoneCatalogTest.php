<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Service;

use OCA\MobilityCheck\Service\TimezoneCatalog;
use PHPUnit\Framework\TestCase;

final class TimezoneCatalogTest extends TestCase
{
	public function testKnownTimezonesAreValid(): void
	{
		$catalog = new TimezoneCatalog();
		$this->assertTrue($catalog->isValid('Europe/Berlin'));
		$this->assertTrue($catalog->isValid('UTC'));
		$this->assertFalse($catalog->isValid('Mars/Phobos'));
	}

	public function testPinnedZonesIncludeRequestedRegionsAndAreValidIana(): void
	{
		$catalog = new TimezoneCatalog();
		$pinned = $catalog->pinned();
		$this->assertNotEmpty($pinned);
		foreach (['Europe/Moscow', 'Asia/Yekaterinburg', 'Asia/Tashkent'] as $required) {
			$this->assertContains($required, $pinned, "Pinned list must include {$required}");
			$this->assertTrue($catalog->isValid($required));
		}
	}

	public function testGroupedCoversAllIdentifiers(): void
	{
		$catalog = new TimezoneCatalog();
		$flatCount = 0;
		foreach ($catalog->grouped() as $row) {
			$this->assertArrayHasKey('label', $row);
			$this->assertArrayHasKey('items', $row);
			$this->assertNotEmpty($row['items']);
			$flatCount += count($row['items']);
		}
		$this->assertSame(count($catalog->all()), $flatCount);
	}

	public function testNormalizeOrThrowRejectsInvalid(): void
	{
		$catalog = new TimezoneCatalog();
		$this->assertSame('Europe/Berlin', $catalog->normalizeOrThrow('  Europe/Berlin  '));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_TIMEZONE');
		$catalog->normalizeOrThrow('Not/A/Zone');
	}

	public function testForApiShape(): void
	{
		$api = (new TimezoneCatalog())->forApi();
		$this->assertArrayHasKey('pinned', $api);
		$this->assertArrayHasKey('groups', $api);
	}
}
