#!/usr/bin/env node
/**
 * Merges l10n/<bundle>.json (flat { "English key": "German value" }) into
 * l10n/en.json and l10n/de.json. English entries use the key as source text
 * when absent; German uses the bundle value.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const bundlePath = process.argv[2];
if (!bundlePath) {
	console.error('Usage: node tools/merge-l10n-bundle.js l10n/<bundle>.json');
	process.exit(1);
}
const raw = fs.readFileSync(path.join(root, bundlePath), 'utf8');
const bundle = JSON.parse(raw);

function readJson(rel) {
	return JSON.parse(fs.readFileSync(path.join(root, rel), 'utf8'));
}
function writeJson(rel, obj) {
	fs.writeFileSync(path.join(root, rel), JSON.stringify(obj, null, '\t') + '\n');
}

const en = readJson('l10n/en.json');
const de = readJson('l10n/de.json');

for (const [key, deVal] of Object.entries(bundle)) {
	if (typeof key !== 'string' || key === '') continue;
	if (!en.translations[key]) {
		en.translations[key] = key;
	}
	if (deVal != null && deVal !== '') {
		de.translations[key] = deVal;
	}
}

writeJson('l10n/en.json', en);
writeJson('l10n/de.json', de);
console.log('Merged', Object.keys(bundle).length, 'keys from', bundlePath);
