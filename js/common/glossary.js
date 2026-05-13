/**
 * Inline glossary helper.
 *
 * The server hydrates `data-mc-glossary` on `#app-content` with a JSON map
 * of {key: {term, definition}} entries. UI components can call
 * `MobilityCheckGlossary.tooltip(key)` to render a help affordance, or
 * `MobilityCheckGlossary.define(key)` to read the definition text.
 */
(function () {
	'use strict';

	function loadFromDom() {
		const root = document.getElementById('app-content');
		if (!root) return {};
		const raw = root.getAttribute('data-mc-glossary');
		if (!raw) return {};
		try { return JSON.parse(raw) || {}; } catch (_) { return {}; }
	}

	const dict = loadFromDom();

	function define(key) {
		const item = dict[key];
		if (!item) return '';
		return item.definition || item.def || '';
	}

	function label(key) {
		const item = dict[key];
		if (!item) return key;
		return item.term || key;
	}

	function tooltip(key) {
		const def = define(key);
		if (!def) return '';
		return ' <button type="button" class="mc-button-link" data-mc-glossary-key="' + key + '" aria-label="' + def + '">?</button>';
	}

	function attach() {
		document.addEventListener('click', (ev) => {
			const btn = ev.target.closest('[data-mc-glossary-key]');
			if (!btn) return;
			const key = btn.getAttribute('data-mc-glossary-key');
			window.MobilityCheckMessaging.toast(label(key) + ' — ' + define(key), 'info');
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', attach);
	} else {
		attach();
	}

	window.MobilityCheckGlossary = { define, label, tooltip };
})();
