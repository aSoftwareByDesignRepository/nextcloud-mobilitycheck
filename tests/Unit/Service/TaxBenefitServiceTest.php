<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Service;

use OCA\MobilityCheck\Service\TaxBenefitService;
use PHPUnit\Framework\TestCase;

final class TaxBenefitServiceTest extends TestCase
{
	public function testOnePercentWithoutCommute(): void
	{
		// 45 000 EUR list → 450 EUR / month = 45 000 cents
		$r = TaxBenefitService::computeOnePercentComponents(4_500_000, 0);
		$this->assertSame(45_000, $r['onePercentMinor']);
		$this->assertSame(0, $r['commuteSurchargeMinor']);
		$this->assertSame(45_000, $r['totalMinor']);
	}

	public function testOnePercentWithCommute(): void
	{
		// 45 000 EUR list, 10 km one-way: surcharge = 45000 × 0.0003 × 10 = 135 EUR = 13 500 cents
		$r = TaxBenefitService::computeOnePercentComponents(4_500_000, 10);
		$this->assertSame(45_000, $r['onePercentMinor']);
		$this->assertSame(13_500, $r['commuteSurchargeMinor']);
		$this->assertSame(58_500, $r['totalMinor']);
	}

	public function testNonPositiveListReturnsZero(): void
	{
		$r = TaxBenefitService::computeOnePercentComponents(0, 10);
		$this->assertSame(0, $r['totalMinor']);
	}
}
