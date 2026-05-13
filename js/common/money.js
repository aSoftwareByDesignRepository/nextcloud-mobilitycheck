/**
 * MobilityCheck monetary helpers.
 *
 * Server stores integer minor units (cents). The UI parses user input
 * (comma or dot, optional thousands) and formats according to the
 * active Nextcloud locale.
 */
(function () {
	'use strict';

	function root() { return document.getElementById('app-content'); }
	function locale() { const r = root(); return r && r.getAttribute('lang') || (navigator.language || 'en'); }
	function currency() {
		const r = root();
		return (r && r.getAttribute('data-mc-currency')) || 'EUR';
	}

	function fromMinor(v) {
		const n = Number(v);
		if (!Number.isFinite(n)) return '';
		return (n / 100).toFixed(2);
	}

	function format(minor, currencyOverride) {
		const cur = currencyOverride || currency();
		const n = Number(minor || 0) / 100;
		try {
			return new Intl.NumberFormat(locale(), { style: 'currency', currency: cur, currencyDisplay: 'symbol' }).format(n);
		} catch (_) {
			return n.toFixed(2) + ' ' + cur;
		}
	}

	function parseToMinor(value) {
		if (value === null || value === undefined || value === '') return null;
		let s = String(value).trim();
		if (!s) return null;
		s = s.replace(/\s|\u00A0/g, '');
		const lastComma = s.lastIndexOf(',');
		const lastDot = s.lastIndexOf('.');
		if (lastComma !== -1 && (lastDot === -1 || lastComma > lastDot)) {
			s = s.replace(/\./g, '').replace(',', '.');
		} else {
			s = s.replace(/,/g, '');
		}
		if (!/^-?\d+(\.\d{1,4})?$/.test(s)) return NaN;
		const n = Math.round(parseFloat(s) * 100);
		return Number.isFinite(n) ? n : NaN;
	}

	window.MobilityCheckMoney = {
		fromMinor,
		format,
		parseToMinor,
		currency,
	};
})();
