<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Service;

use OCA\MobilityCheck\Service\CurrencyCatalog;
use OCA\MobilityCheck\Service\MobilityCheckMoney;
use PHPUnit\Framework\TestCase;

final class CurrencyCatalogTest extends TestCase
{
	public function testPinnedIncludesRubUahKztTry(): void
	{
		$cat = new CurrencyCatalog();
		foreach (['RUB', 'UAH', 'KZT', 'TRY'] as $code) {
			$this->assertContains($code, $cat->pinned());
			$this->assertTrue($cat->isSupported($code));
		}
	}

	public function testGroupedCoversAllSupportedCodes(): void
	{
		$cat = new CurrencyCatalog();
		$seen = [];
		foreach ($cat->grouped() as $row) {
			$this->assertArrayHasKey('label', $row);
			$this->assertArrayHasKey('items', $row);
			foreach ($row['items'] as $entry) {
				$this->assertArrayHasKey('code', $entry);
				$this->assertArrayHasKey('decimals', $entry);
				$this->assertSame($cat->decimalsFor($entry['code']), $entry['decimals']);
				$seen[$entry['code']] = true;
			}
		}
		foreach ($cat->codes() as $code) {
			$this->assertArrayHasKey($code, $seen, "Grouped catalog must include {$code}");
		}
	}

	public function testNormalizeOrThrowRejectsUnsupported(): void
	{
		$cat = new CurrencyCatalog();
		$this->assertSame('EUR', $cat->normalizeOrThrow('eur'));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('CURRENCY_NOT_SUPPORTED');
		$cat->normalizeOrThrow('XXX');
	}

	public function testMobilityCheckMoneyUsesCatalogDecimals(): void
	{
		$this->assertSame(0, MobilityCheckMoney::decimalToMinor('500', 'JPY'));
		$this->assertSame(12345, MobilityCheckMoney::decimalToMinor('123.45', 'RUB'));
	}
}
