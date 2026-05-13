<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Util;

use OCA\MobilityCheck\Service\GdprErasureService;
use OCA\MobilityCheck\Util\GdprFreeTextPseudonymizer;
use PHPUnit\Framework\TestCase;

final class GdprFreeTextPseudonymizerTest extends TestCase
{
	public function testRedactNextcloudUidWithWordBoundaries(): void
	{
		$t = GdprErasureService::TOMBSTONE;
		$this->assertSame(
			"Trip with {$t} only",
			GdprFreeTextPseudonymizer::redactNextcloudUid('Trip with admin only', 'admin', $t),
		);
		$this->assertSame(
			'xadministrator',
			GdprFreeTextPseudonymizer::redactNextcloudUid('xadministrator', 'admin', $t),
		);
	}

	public function testRedactDisplayNameRespectsLetterBoundaries(): void
	{
		$ph = '__n_test_placeholder__';
		$this->assertSame(
			"Guests: {$ph}, Annabelle",
			GdprFreeTextPseudonymizer::redactDisplayName('Guests: Anna, Annabelle', 'Anna', $ph),
		);
	}

	public function testDisplayNamePlaceholderIsDeterministic(): void
	{
		$a = GdprFreeTextPseudonymizer::displayNamePlaceholder('u1', 'Jane Doe');
		$b = GdprFreeTextPseudonymizer::displayNamePlaceholder('u1', 'Jane Doe');
		$this->assertSame($a, $b);
		$this->assertNotSame(
			GdprFreeTextPseudonymizer::displayNamePlaceholder('u2', 'Jane Doe'),
			$a,
		);
	}

	public function testShortDisplayNameSkipped(): void
	{
		$this->assertSame(
			'Bob only',
			GdprFreeTextPseudonymizer::redactDisplayName('Bob only', 'Bob', '__x__'),
		);
	}
}
