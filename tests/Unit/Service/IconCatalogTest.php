<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Service;

use OCA\MobilityCheck\Service\IconCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Icon catalogue smoke-test. Every icon referenced by navigation /
 * templates must render an SVG, and the result must NEVER inject the
 * raw `extraClass` argument without escaping (XSS regression guard).
 */
final class IconCatalogTest extends TestCase
{
	public function testKnownIconRenders(): void
	{
		$svg = IconCatalog::render('car');
		$this->assertStringContainsString('<svg', $svg);
		$this->assertStringContainsString('aria-hidden="true"', $svg);
		$this->assertStringContainsString('focusable="false"', $svg);
	}

	public function testUnknownIconReturnsEmpty(): void
	{
		$this->assertSame('', IconCatalog::render('definitely-not-an-icon'));
	}

	public function testExtraClassIsEscaped(): void
	{
		$svg = IconCatalog::render('car', '"><script>alert(1)</script>');
		$this->assertStringNotContainsString('<script', $svg);
		$this->assertStringContainsString('class="mc-icon &quot;&gt;&lt;script&gt;', $svg);
	}

	public function testNavigationIconsAllExist(): void
	{
		$navIcons = ['layout-grid', 'car', 'users', 'calendar', 'plus', 'alert-triangle', 'tool', 'coins', 'shield-check', 'file-analytics', 'settings'];
		foreach ($navIcons as $name) {
			$this->assertNotEmpty(IconCatalog::render($name), 'icon "' . $name . '" must exist');
		}
	}
}
