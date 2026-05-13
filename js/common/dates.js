/**
 * MobilityCheck date / time helpers.
 *
 * All API timestamps are emitted in UTC (`YYYY-MM-DD HH:mm:ss`) or as
 * date-only strings (`YYYY-MM-DD`). UI consumes them via Intl with the
 * locale from `data-locale` on `#app-content`. Times are always shown
 * in 24-hour notation.
 */
(function () {
	'use strict';

	function locale() {
		const root = document.getElementById('app-content');
		return root && root.getAttribute('lang') || (navigator.language || 'en');
	}

	function timezone() {
		const root = document.getElementById('app-content');
		return (root && root.getAttribute('data-mc-timezone')) || 'UTC';
	}

	function asDate(value) {
		if (!value) return null;
		if (value instanceof Date) return value;
		if (typeof value === 'number') return new Date(value);
		// Treat naive `YYYY-MM-DD HH:mm:ss` as UTC.
		const v = String(value).trim();
		if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/.test(v)) {
			const iso = v.replace(' ', 'T') + 'Z';
			const d = new Date(iso);
			return Number.isNaN(d.getTime()) ? null : d;
		}
		if (/^\d{4}-\d{2}-\d{2}$/.test(v)) {
			return new Date(v + 'T00:00:00Z');
		}
		const d = new Date(v);
		return Number.isNaN(d.getTime()) ? null : d;
	}

	function fmtDate(value) {
		const d = asDate(value);
		if (!d) return '';
		return new Intl.DateTimeFormat(locale(), {
			timeZone: timezone(),
			year: 'numeric', month: '2-digit', day: '2-digit',
		}).format(d);
	}

	function fmtDateTime(value) {
		const d = asDate(value);
		if (!d) return '';
		return new Intl.DateTimeFormat(locale(), {
			timeZone: timezone(),
			year: 'numeric', month: '2-digit', day: '2-digit',
			hour: '2-digit', minute: '2-digit', hour12: false,
		}).format(d);
	}

	function fmtTime(value) {
		const d = asDate(value);
		if (!d) return '';
		return new Intl.DateTimeFormat(locale(), {
			timeZone: timezone(),
			hour: '2-digit', minute: '2-digit', hour12: false,
		}).format(d);
	}

	function isoForDateInput(value) {
		const d = asDate(value);
		if (!d) return '';
		const y = d.getUTCFullYear();
		const m = String(d.getUTCMonth() + 1).padStart(2, '0');
		const day = String(d.getUTCDate()).padStart(2, '0');
		return `${y}-${m}-${day}`;
	}

	function isoForDateTimeInput(value) {
		const d = asDate(value);
		if (!d) return '';
		const y = d.getFullYear();
		const m = String(d.getMonth() + 1).padStart(2, '0');
		const day = String(d.getDate()).padStart(2, '0');
		const h = String(d.getHours()).padStart(2, '0');
		const mi = String(d.getMinutes()).padStart(2, '0');
		return `${y}-${m}-${day}T${h}:${mi}`;
	}

	function fromDateTimeLocalInput(value) {
		if (!value) return '';
		const d = new Date(value);
		if (Number.isNaN(d.getTime())) return '';
		const y = d.getUTCFullYear();
		const m = String(d.getUTCMonth() + 1).padStart(2, '0');
		const day = String(d.getUTCDate()).padStart(2, '0');
		const h = String(d.getUTCHours()).padStart(2, '0');
		const mi = String(d.getUTCMinutes()).padStart(2, '0');
		const s = String(d.getUTCSeconds()).padStart(2, '0');
		return `${y}-${m}-${day}T${h}:${mi}:${s}Z`;
	}

	function relative(value) {
		const d = asDate(value);
		if (!d) return '';
		const now = Date.now();
		const diff = d.getTime() - now;
		const abs = Math.abs(diff);
		const units = [
			{ s: 60 * 1000, u: 'minute' },
			{ s: 60 * 60 * 1000, u: 'hour' },
			{ s: 24 * 60 * 60 * 1000, u: 'day' },
			{ s: 7 * 24 * 60 * 60 * 1000, u: 'week' },
			{ s: 30 * 24 * 60 * 60 * 1000, u: 'month' },
			{ s: 365 * 24 * 60 * 60 * 1000, u: 'year' },
		];
		let chosen = units[0];
		for (const u of units) {
			if (abs >= u.s) chosen = u;
		}
		const rtf = new Intl.RelativeTimeFormat(locale(), { numeric: 'auto' });
		const map = { minute: chosen.s, hour: chosen.s, day: chosen.s, week: chosen.s, month: chosen.s, year: chosen.s };
		const div = map[chosen.u];
		return rtf.format(Math.round(diff / div), chosen.u);
	}

	window.MobilityCheckDates = {
		fmtDate,
		fmtDateTime,
		fmtTime,
		isoForDateInput,
		isoForDateTimeInput,
		fromDateTimeLocalInput,
		relative,
		asDate,
	};
})();
