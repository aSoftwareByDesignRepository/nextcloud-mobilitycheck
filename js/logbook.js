/**
 * Fahrtenbuch list page (Appendix A3).
 *
 * Drivers see their own entries automatically (server-enforced).
 * Managers/fleet admins/auditors can scope by vehicle, date range and
 * driver. A separate "Gap check" tool is exposed to managers only.
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const D = window.MobilityCheckDates;
	const t = M.t;

	const tripLabels = {
		business: t('Business'),
		commute: t('Commute'),
		private: t('Private'),
	};

	function tripBadge(type) {
		const label = tripLabels[type] || type || '—';
		return C.h('span', { class: 'mc-status-badge', 'data-status': type || 'business', 'aria-label': t('Trip type') + ': ' + label }, label);
	}

	function stateBadge(row) {
		if (row.is_superseded) {
			return C.h('span', { class: 'mc-status-badge', 'data-status': 'cancelled', 'aria-label': t('Superseded by amendment') }, t('Superseded'));
		}
		if (row.amendment_of_entry_id) {
			return C.h('span', { class: 'mc-status-badge', 'data-status': 'approved', 'aria-label': t('Amended entry') }, t('Amended'));
		}
		if (row.confirmed_at) {
			return C.h('span', { class: 'mc-status-badge', 'data-status': 'completed', 'aria-label': t('Confirmed') }, t('Confirmed'));
		}
		return C.h('span', { class: 'mc-status-badge', 'data-status': 'pending_approval', 'aria-label': t('Draft') }, t('Draft'));
	}

	const filters = { vehicleId: '', from: '', to: '', driverUserId: '', confirmedOnly: '' };

	async function loadDriversForFilter() {
		const el = document.getElementById('mc-lb-driver');
		if (!el) return;
		try {
			const rows = API.asArray(await API.get('/api/drivers'));
			const keep = el.querySelector('option[value=""]');
			el.innerHTML = '';
			if (keep) el.appendChild(keep);
			else {
				const o0 = document.createElement('option');
				o0.value = '';
				o0.textContent = t('All drivers');
				el.appendChild(o0);
			}
			rows.forEach((r) => {
				const opt = document.createElement('option');
				opt.value = String(r.user_id || '');
				const dn = r.displayName || r.user_id;
				opt.textContent = dn + (r.user_id && dn !== r.user_id ? ' (' + r.user_id + ')' : '');
				el.appendChild(opt);
			});
		} catch (e) { M.reportError(e); }
	}

	async function loadVehicles() {
		try {
			const rows = API.asArray(await API.get('/api/vehicles', { activeOnly: 1 }));
			const sels = ['#mc-lb-vehicle', '#mc-lb-gap-vehicle'];
			sels.forEach((sel) => {
				const el = document.querySelector(sel);
				if (!el) return;
				const isGap = sel === '#mc-lb-gap-vehicle';
				const keep = el.firstElementChild;
				el.innerHTML = '';
				if (keep) el.appendChild(keep);
				rows.forEach((v) => {
					const opt = document.createElement('option');
					opt.value = String(v.id);
					opt.textContent = (v.internal_name || ('#' + v.id)) + (v.licence_plate ? ' · ' + v.licence_plate : '');
					el.appendChild(opt);
				});
				if (isGap && rows.length === 0) {
					const empty = el.firstElementChild;
					if (empty) empty.textContent = t('No vehicles available.');
				}
			});
		} catch (e) { M.reportError(e); }
	}

	async function loadList() {
		const host = document.getElementById('mc-lb-list');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const rows = API.asArray(await API.get('/api/logbook', filters));
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'trip_date', label: t('Date'), render: (r) => C.h('a', { class: 'mc-button-link', href: C.detailUrl('logbook', r.id) }, D.fmtDate(r.trip_date)) },
				{ key: 'driver_user_id', label: t('Driver'), render: (r) => r.driver_user_id || '—' },
				{ key: 'vehicle_id', label: t('Vehicle'), render: (r) => '#' + r.vehicle_id },
				{ key: 'trip_type', label: t('Type'), render: (r) => tripBadge(r.trip_type) },
				{ key: 'distance_km', label: t('km'), num: true, render: (r) => String(r.distance_km) },
				{ key: 'purpose', label: t('Purpose'), render: (r) => r.purpose || '—' },
				{ key: 'state', label: t('State'), render: (r) => stateBadge(r) },
				{
					key: 'flags', label: t('Flags'), render: (r) => {
						const tags = C.h('div', { class: 'mc-taglist' });
						if (r.late_entry) tags.appendChild(C.h('span', { class: 'mc-tag', 'aria-label': t('Saved later than the {n}-day grace period.', { n: 7 }) }, t('Late')));
						return tags.firstChild ? tags : '—';
					},
				},
			], rows, {
				ariaLabel: t('Logbook entries'),
				emptyHeading: t('No trips match your filters.'),
				emptyHint: t('Adjust the filters or, if you are a driver, add a new entry from this page.'),
			});
		} catch (e) {
			C.setLoading(host, false);
			M.reportError(e);
		}
	}

	async function runGap(ev) {
		ev.preventDefault();
		const form = ev.target;
		const host = document.getElementById('mc-lb-gap-result');
		if (!host) return;
		host.innerHTML = '';
		const vehicleId = parseInt(form.elements.vehicleId.value, 10);
		const from = form.elements.from.value;
		const to = form.elements.to.value;
		if (!vehicleId || !from || !to) {
			host.appendChild(C.h('div', { class: 'mc-callout mc-callout--warning', role: 'alert' }, t('Please select a vehicle and a date range.')));
			return;
		}
		C.setLoading(host, true);
		try {
			const r = await API.get('/api/logbook/gaps', { vehicleId, from, to });
			C.setLoading(host, false);
			const status = r.gapKm > 0 ? 'critical' : 'success';
			const heading = r.gapKm > 0
				? t('Gap detected: {km} km unlogged', { km: r.gapKm })
				: t('No gap — the Fahrtenbuch matches the odometer.');
			const dl = C.h('dl', { class: 'mc-dl' }, [
				C.h('dt', null, t('Logged (confirmed) km')), C.h('dd', null, String(r.loggedKmConfirmed)),
				C.h('dt', null, t('Odometer delta in window')), C.h('dd', null, String(r.odometerDeltaKm)),
				C.h('dt', null, t('Gap')), C.h('dd', null, String(r.gapKm)),
			]);
			host.appendChild(C.h('div', { class: 'mc-callout mc-callout--' + status, role: 'note' }, [
				C.h('h3', { class: 'mc-callout__title' }, heading),
				dl,
			]));
		} catch (e) {
			C.setLoading(host, false);
			M.reportError(e);
		}
	}

	async function init() {
		C.bootstrap();
		const f = document.getElementById('mc-lb-filters');
		f?.addEventListener('change', (ev) => {
			if (!ev.target || !ev.target.name) return;
			if (ev.target.name === 'confirmedOnly') {
				filters.confirmedOnly = ev.target.checked ? '1' : '';
			} else {
				filters[ev.target.name] = ev.target.value || '';
			}
			loadList();
		});
		document.getElementById('mc-lb-gap-form')?.addEventListener('submit', runGap);
		await Promise.all([loadVehicles(), loadDriversForFilter()]);
		C.applySearchParamsToFilters(filters, f);
		loadList();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { void init(); });
	else void init();
})();
