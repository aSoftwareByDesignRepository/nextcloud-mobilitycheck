<?php

declare(strict_types=1);

/**
 * Ensures translated strings keep the same printf-style placeholders as their msgid.
 * Named placeholders like {project} are ignored (Nextcloud notifications / JS tPl).
 *
 * Exit 0 = OK, 1 = mismatch printed to STDERR.
 */

$base = __DIR__ . '/../l10n';
$localeFiles = ['de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb'];

/**
 * @return list<string>
 */
function mcPrintfPlaceholders(string $s): array {
	preg_match_all('/%%|%(?:\d+\$)?[sd]/', $s, $m);

	return $m[0];
}

$enPath = $base . '/en.json';
if (!is_file($enPath)) {
	fwrite(STDERR, "Missing locale file: $enPath\n");
	exit(1);
}

$en = json_decode((string)file_get_contents($enPath), true, 512, JSON_THROW_ON_ERROR);
$enT = $en['translations'] ?? [];

$failed = false;

foreach ($localeFiles as $lang) {
	$path = $base . '/' . $lang . '.json';
	if (!is_file($path)) {
		fwrite(STDERR, "Missing locale file: $path\n");
		exit(1);
	}
	$catalog = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	$langT = $catalog['translations'] ?? [];

	foreach ($enT as $key => $enVal) {
		$keyPh = mcPrintfPlaceholders($key);
		if ($keyPh === []) {
			continue;
		}
		if (!isset($langT[$key])) {
			continue;
		}
		$langPh = mcPrintfPlaceholders((string)$langT[$key]);
		if ($langPh !== $keyPh) {
			$failed = true;
			fwrite(STDERR, "{$lang}.json placeholder mismatch for key: $key\n");
			fwrite(STDERR, '  expected: ' . implode(', ', $keyPh) . "\n");
			fwrite(STDERR, '  got:      ' . implode(', ', $langPh) . "\n");
		}
	}
}

if ($failed) {
	fwrite(STDERR, "\nl10n placeholder check FAILED.\n");
	exit(1);
}

echo "l10n placeholder check OK (all locales printf placeholders match msgids).\n";
exit(0);
