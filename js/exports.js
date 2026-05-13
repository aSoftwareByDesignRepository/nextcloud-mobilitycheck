/**
 * Exports page (Appendix A7).
 *
 * Generates a Fahrtenbuch CSV export and renders a one-hour download link.
 * The history table shows recent requests and lets the user re-download a
 * still-valid token without re-running the export.
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const D = window.MobilityCheckDates;
	const t = M.t;

	function downloadUrl(token) {
		const base = API.appBase;
		return base + '/api/exports/download/' + encodeURIComponent(token);
	}

	async function loadVehicles() {
		const sel = document.getElementById('mc-xp-vehicle');
		if (!sel) return;
		try {
			const rows = API.asArray(await API.get('/api/vehicles', { activeOnly: 1 }));
			rows.forEach((v) => {
				const opt = document.createElement('option');
				opt.value = String(v.id);
				opt.textContent = (v.internal_name || ('#' + v.id)) + (v.licence_plate ? ' · ' + v.licence_plate : '');
				sel.appendChild(opt);
			});
		} catch (e) { M.reportError(e); }
	}

	async function submit(ev) {
		ev.preventDefault();
		const form = ev.target;
		C.clearErrors(form);
		const raw = C.collectForm(form);
		const payload = {
			vehicleId: parseInt(raw.vehicleId, 10),
			from: raw.from,
			to: raw.to,
		};
		if (!payload.vehicleId) { C.applyFieldError(form, 'vehicleId', t('Please choose a vehicle.')); return; }
		if (!payload.from || !payload.to) { C.applyFieldError(form, 'from', t('Please pick a date range.')); return; }
		if (payload.from > payload.to) { C.applyFieldError(form, 'to', t('“To” must be on or after “From”.')); return; }
		C.lockForm(form, true);
		try {
			const r = await API.post('/api/exports/request', payload);
			C.lockForm(form, false);
			const host = document.getElementById('mc-xp-current');
			host.innerHTML = '';
			host.appendChild(C.h('div', { class: 'mc-callout mc-callout--success', role: 'note' }, [
				C.h('h3', { class: 'mc-callout__title' }, t('Export ready')),
				C.h('p', null, t('Your download link is valid until {when}.', { when: D.fmtDateTime(r.expiresAt) })),
				C.h('p', null, [
					C.h('a', { class: 'button button-primary', href: downloadUrl(r.token) }, t('Download CSV')),
				]),
			]));
			loadHistory();
		} catch (e) {
			C.lockForm(form, false);
			if (e.context && e.context.field) {
				C.applyFieldError(form, e.context.field, M.resolveError(e));
			} else {
				M.reportError(e);
			}
		}
	}

	async function loadHistory() {
		const host = document.getElementById('mc-xp-hist');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const rows = API.asArray(await API.get('/api/exports/history'));
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'created_at', label: t('Requested'), render: (r) => D.fmtDateTime(r.created_at) },
				{ key: 'filename', label: t('File') },
				{ key: 'mime_type', label: t('Type') },
				{ key: 'expires_at', label: t('Expires'), render: (r) => D.fmtDateTime(r.expires_at) },
				{
					key: 'download', label: t('Download'), render: (r) => {
						if (r.expired) return C.h('span', { class: 'mc-tag' }, t('Expired'));
						return C.h('a', { class: 'mc-button-link', href: downloadUrl(r.token) }, t('Download'));
					},
				},
			], rows, {
				ariaLabel: t('Export history'),
				emptyHeading: t('No exports yet.'),
				emptyHint: t('Generate your first export using the form above.'),
			});
		} catch (e) { C.setLoading(host, false); M.reportError(e); }
	}

	async function init() {
		C.bootstrap();
		await loadVehicles();
		const form = document.getElementById('mc-xp-form');
		if (form) {
			const sp = new URLSearchParams(window.location.search);
			['vehicleId', 'from', 'to'].forEach((k) => {
				if (!sp.has(k)) return;
				const el = form.elements.namedItem(k);
				if (el && 'value' in el) {
					el.value = String(sp.get(k) || '');
				}
			});
		}
		loadHistory();
		document.getElementById('mc-xp-form')?.addEventListener('submit', submit);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { void init(); });
	else void init();
})();
