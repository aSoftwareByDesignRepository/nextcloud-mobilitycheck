<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Service;

use OCA\MobilityCheck\Exception\ValidationException;
use OCA\MobilityCheck\Service\MobilityCheckMoney;
use PHPUnit\Framework\TestCase;

/**
 * Money helper — covers parsing edge cases, VAT split correctness,
 * Kaufmännische Rundung, and the invariant `gross == net + vat`.
 */
final class MobilityCheckMoneyTest extends TestCase
{
	public function testParseGermanDecimal(): void
	{
		$this->assertSame(1234, MobilityCheckMoney::decimalToMinor('12,34'));
		$this->assertSame(1234, MobilityCheckMoney::decimalToMinor('12.34'));
		$this->assertSame(123400, MobilityCheckMoney::decimalToMinor('1.234,00'));
		$this->assertSame(123400, MobilityCheckMoney::decimalToMinor('1,234.00'));
		$this->assertSame(0, MobilityCheckMoney::decimalToMinor('0'));
	}

	public function testParseRejectsNegative(): void
	{
		$this->expectException(ValidationException::class);
		MobilityCheckMoney::decimalToMinor('-1,00');
	}

	public function testParseRejectsGarbage(): void
	{
		$this->expectException(ValidationException::class);
		MobilityCheckMoney::decimalToMinor('abc');
	}

	public function testVatSplitSums(): void
	{
		$split = MobilityCheckMoney::splitVat(1190, 1900);
		$this->assertSame(1000, $split['net']);
		$this->assertSame(190, $split['vat']);
		$this->assertSame(1190, $split['gross']);
		$this->assertSame(1900, $split['rate_bp']);
		$this->assertSame($split['net'] + $split['vat'], $split['gross']);
	}

	public function testVatSplitRoundingNoLeak(): void
	{
		// 1 cent gross / 19% VAT must still satisfy gross = net + vat
		for ($g = 1; $g <= 9999; $g++) {
			$split = MobilityCheckMoney::splitVat($g, 1900);
			$this->assertSame(
				$g,
				$split['net'] + $split['vat'],
				'gross=net+vat must hold for ' . $g,
			);
		}
	}

	public function testVatZeroRate(): void
	{
		$split = MobilityCheckMoney::splitVat(500, 0);
		$this->assertSame(500, $split['net']);
		$this->assertSame(0, $split['vat']);
	}

	public function testRatePerKm(): void
	{
		$this->assertSame(0, MobilityCheckMoney::ratePerKmToAmount(0, 100));
		$this->assertSame(3000, MobilityCheckMoney::ratePerKmToAmount(30, 100));
	}

	public function testMinorToDecimalString(): void
	{
		$this->assertSame('12.34', MobilityCheckMoney::minorToDecimalString(1234));
		$this->assertSame('0.05', MobilityCheckMoney::minorToDecimalString(5));
		$this->assertSame('-1.00', MobilityCheckMoney::minorToDecimalString(-100));
	}
}
