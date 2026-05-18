<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

/**
 * Inline SVG icon catalogue for MobilityCheck templates.
 *
 * Lucide-style 24x24 stroke icons that inherit `currentColor` so they
 * recolour with the surrounding text in any Nextcloud theme. Returned
 * strings are safe to embed directly because they contain no user data
 * and always set `aria-hidden="true"` and `focusable="false"`.
 *
 * Conventions match BudgetCheck / DutyCheck (§2a.0):
 *  - Stroke-only paths, stroke-width 1.75.
 *  - Round joins/caps.
 *  - Consumer adds extra class hooks via the second argument.
 *
 * Static-only by design so templates can call without DI plumbing,
 * yet a tiny `icon()` instance method exists for tests that want a
 * mockable seam.
 */
final class IconCatalog
{
	/** @var array<string,string> Pre-baked inner SVG paths by name. */
	private const ICONS = [
		// Navigation / page headers
		'layout-grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
		'car' => '<path d="M5 13 6.5 8.5A2 2 0 0 1 8.4 7h7.2a2 2 0 0 1 1.9 1.5L19 13"/><path d="M3 17v-3a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v3"/><rect x="3" y="14" width="18" height="4" rx="1"/><circle cx="7.5" cy="18" r="1.5"/><circle cx="16.5" cy="18" r="1.5"/>',
		'car-front' => '<path d="M5 11 6.4 6.4A2 2 0 0 1 8.3 5h7.4a2 2 0 0 1 1.9 1.4L19 11"/><rect x="3" y="11" width="18" height="7" rx="2"/><path d="M7 16h1"/><path d="M16 16h1"/><path d="M9 11h6"/>',
		'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
		'user' => '<circle cx="12" cy="8" r="4"/><path d="M5 21a7 7 0 0 1 14 0"/>',
		'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
		'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
		'calendar-days' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"/>',
		'calendar-check' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="m9 16 2 2 4-4"/>',
		'clipboard-list' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6"/><path d="M9 16h6"/>',
		'clipboard-check' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
		'route' => '<circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/>',
		'wrench' => '<path d="M14.7 6.3a4 4 0 0 0-5.5 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-3 3-2.4-.6-.6-2.4Z"/>',
		'cash' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01M18 12h.01"/>',
		'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
		'shield-check' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
		'chart-bar' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 17V9M12 17V5M16 17v-7"/>',
		'book' => '<path d="M4 19V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2Z"/><path d="M8 7h8M8 11h8M8 15h5"/>',
		'receipt' => '<path d="M5 3h14v18l-2-1-2 1-2-1-2 1-2-1-2 1-2-1V3Z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
		'percent' => '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
		'download' => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
		'upload' => '<path d="M12 21V9"/><path d="m7 14 5-5 5 5"/><path d="M5 3h14"/>',
		'settings' => '<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 0 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 0 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 0 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3 1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 0 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8 1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
		'shield-lock' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="M12 12v3"/><circle cx="12" cy="10" r="1.5"/>',
		// Actions
		'plus' => '<path d="M12 5v14M5 12h14"/>',
		'minus' => '<path d="M5 12h14"/>',
		'check' => '<path d="m5 12 5 5L20 7"/>',
		'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
		'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
		'x-circle' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
		'edit' => '<path d="M11 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
		'rotate' => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>',
		'arrow-right' => '<path d="M5 12h14M13 5l7 7-7 7"/>',
		'arrow-left' => '<path d="M19 12H5M11 5l-7 7 7 7"/>',
		'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
		'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
		'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
		'unlock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0"/>',
		'alert-triangle' => '<path d="m12 3 10 17H2Z"/><path d="M12 9v4M12 17h.01"/>',
		'alert-octagon' => '<polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><path d="M12 8v4M12 16h.01"/>',
		'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
		'help' => '<circle cx="12" cy="12" r="10"/><path d="M9.5 9a2.5 2.5 0 1 1 4.5 1.5c-.7.6-1.5.9-1.5 1.5v.5"/><path d="M12 17h.01"/>',
		'help-circle' => '<circle cx="12" cy="12" r="10"/><path d="M9.5 9a2.5 2.5 0 1 1 4.5 1.5c-.7.6-1.5.9-1.5 1.5v.5"/><path d="M12 17h.01"/>',
		'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
		'eye-off' => '<path d="m3 3 18 18"/><path d="M10.6 10.6a3 3 0 0 0 4.2 4.2"/><path d="M9.9 4.2A11.5 11.5 0 0 1 12 4c5 0 9.3 4 10 8a13.3 13.3 0 0 1-3.9 5.4"/><path d="M6.6 6.6A12.6 12.6 0 0 0 2 12s3.5 7 10 7c1.7 0 3.3-.4 4.7-1"/>',
		'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
		'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
		'filter' => '<path d="M3 4h18l-7 9v6l-4 2v-8Z"/>',
		'send' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
		'copy' => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
		'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
		'briefcase' => '<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><path d="M3 13h18"/>',
		'building' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 7h.01M15 7h.01M9 11h.01M15 11h.01M9 15h.01M15 15h.01M10 21v-4h4v4"/>',
		'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
		'map-pin' => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
		'fuel' => '<rect x="3" y="3" width="11" height="18" rx="1"/><path d="M3 13h11"/><path d="M14 9h2.5L19 11.5V18a2 2 0 1 1-4 0v-1"/>',
		'gauge' => '<path d="M4 18a8 8 0 1 1 16 0"/><path d="M12 14v-1l5-5"/><circle cx="12" cy="18" r="1"/>',
		'snowflake' => '<path d="M12 2v20M4 6l16 12M20 6 4 18M2 12h20"/>',
		'images' => '<rect x="3" y="5" width="14" height="14" rx="2"/><circle cx="8" cy="10" r="1.5"/><path d="m3 16 4-4 5 5"/><rect x="7" y="3" width="14" height="14" rx="2" opacity="0.3"/>',
		'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>',
		'list-checks' => '<path d="m3 6 2 2 4-4"/><path d="m3 14 2 2 4-4"/><path d="M13 7h8"/><path d="M13 15h8"/><path d="M13 19h6"/>',
		// Aliases used by MobilityCheck navigation. Kept here so the catalog
		// is the single source of truth for icon resolution.
		'tool' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.7 2.7-2.6-.7-.7-2.6 2.7-2.7Z"/>',
		'coins' => '<circle cx="8" cy="8" r="6"/><path d="M18 8.5a6 6 0 1 1-7 9.5"/><path d="M7 6h2v4"/><path d="M16 16h2v4"/>',
		'file-analytics' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 17v-3"/><path d="M12 17v-5"/><path d="M15 17v-2"/>',
		'inbox' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/>',
	];

	public static function render(string $name, ?string $extraClass = null): string
	{
		$inner = self::ICONS[$name] ?? null;
		if ($inner === null) {
			return '';
		}
		$class = 'mc-icon';
		if ($extraClass !== null && $extraClass !== '') {
			$class .= ' ' . htmlspecialchars($extraClass, ENT_QUOTES, 'UTF-8');
		}
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="%s" aria-hidden="true" focusable="false">%s</svg>',
			$class,
			$inner,
		);
	}

	public function icon(string $name): string
	{
		return self::render($name);
	}

	/** @return list<string> */
	public static function names(): array
	{
		return array_keys(self::ICONS);
	}
}
