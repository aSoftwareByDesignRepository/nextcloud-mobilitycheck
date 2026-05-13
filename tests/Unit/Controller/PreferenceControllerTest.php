<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Controller;

use OCA\MobilityCheck\Controller\PreferenceController;
use OCA\MobilityCheck\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * §2.5 / §8 — Per-user preferences are a key/value store, but the schema
 * must stay closed: only the keys we know about are storeable, the values
 * are typed, and a single row can never grow beyond a small ceiling. This
 * suite exercises the validation path directly via reflection so we
 * don't have to spin up Nextcloud's container.
 */
final class PreferenceControllerTest extends TestCase
{
	private function normalise(string $key, mixed $value): mixed
	{
		$controller = (new \ReflectionClass(PreferenceController::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(PreferenceController::class, 'normalise');
		$method->setAccessible(true);
		return $method->invoke($controller, $key, $value);
	}

	public function testBooleanCoercion(): void
	{
		$this->assertTrue($this->normalise('onboarding.driver.dismissed', true));
		$this->assertTrue($this->normalise('onboarding.driver.dismissed', 1));
		$this->assertTrue($this->normalise('onboarding.driver.dismissed', 'true'));
		$this->assertTrue($this->normalise('onboarding.driver.dismissed', 'YES'));
		$this->assertFalse($this->normalise('onboarding.driver.dismissed', false));
		$this->assertFalse($this->normalise('onboarding.driver.dismissed', 0));
		$this->assertFalse($this->normalise('onboarding.driver.dismissed', 'no'));
	}

	public function testBooleanRejectsGarbage(): void
	{
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('INVALID_PREFERENCE_VALUE');
		$this->normalise('onboarding.driver.dismissed', ['x' => 1]);
	}

	public function testStringEnumIsEnforced(): void
	{
		$this->assertSame('weekly', $this->normalise('notifications.digest.frequency', 'weekly'));
		$this->expectException(ValidationException::class);
		$this->normalise('notifications.digest.frequency', 'hourly');
	}

	public function testStringFreeFormIsBounded(): void
	{
		$ok = str_repeat('a', 80);
		$this->assertSame($ok, $this->normalise('ui.locale.override', $ok));
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('PREFERENCE_VALUE_TOO_LARGE');
		$this->normalise('ui.locale.override', str_repeat('a', 200));
	}

	public function testStringWrongTypeRejected(): void
	{
		$this->expectException(ValidationException::class);
		$this->normalise('ui.locale.override', 12345);
	}
}
