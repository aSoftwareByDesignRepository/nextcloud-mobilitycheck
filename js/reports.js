/**
 * Reports page.
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const { fmtDate, fmtDateTime } = window.MobilityCheckDates;
	const Money = window.MobilityCheckMoney;
	const t = M.t;

	const state = { tab: 'costs', from: '', to: '', vehicleId: '', driverUserId: '' };

	function renderCosts(data) {
		const host = document.getElementById('mc-rep-content');
		host.innerHTML = '';
		host.appendChild(C.h('div', { class: 'mc-kpis' }, [
			C.h('article', { class: 'mc-kpi mc-kpi--info' }, [
				C.h('span', { class: 'mc-kpi__label' }, t('Total gross')),
				C.h('span', { class: 'mc-kpi__value' }, Money.format(data.total || 0)),
			]),
		]));
		const byV = document.createElement('div');
		C.renderTable(byV, [
			{ key: 'vehicleId', label: t('Vehicle ID') },
			{ key: 'internalName', label: t('Vehicle') },
			{ key: 'total', label: t('Total'), num: true, render: (r) => Money.format(r.total) },
		], data.byVehicle || [], { emptyHeading: t('No cost entries in this window.'), ariaLabel: t('Per vehicle') });
		host.appendChild(C.h('h3', { class: 'mc-section__sub' }, t('Per vehicle')));
		host.appendChild(byV);
		const byC = document.createElement('div');
		C.renderTable(byC, [
			{ key: 'categoryId', label: t('Category ID') },
			{ key: 'name', label: t('Category') },
			{ key: 'total', label: t('Total'), num: true, render: (r) => Money.format(r.total) },
		], data.byCategory || [], { emptyHeading: t('No categories.'), ariaLabel: t('Per category') });
		host.appendChild(C.h('h3', { class: 'mc-section__sub' }, t('Per category')));
		host.appendChild(byC);
	}

	function renderUtilisation(data) {
		const host = document.getElementById('mc-rep-content');
		host.innerHTML = '';
		C.renderTable(host, [
			{ key: 'vehicleId', label: t('Vehicle ID') },
			{ key: 'internalName', label: t('Vehicle') },
			{ key: 'totalDistanceKm', label: t('Distance'), num: true, render: (r) => (r.totalDistanceKm || 0).toLocaleString() + ' km' },
			{ key: 'totalDurationHours', label: t('In use'), num: true, render: (r) => (r.totalDurationHours || 0) + ' h' },
			{ key: 'bookingsCount', label: t('Bookings'), num: true },
			{ key: 'utilisationPercent', label: t('Utilisation'), num: true, render: (r) => (r.utilisationPercent ?? 0).toFixed(1) + '%' },
		], data || [], { emptyHeading: t('No utilisation data.'), ariaLabel: t('Vehicle utilisation') });
	}

	function renderBookings(rows) {
		const host = document.getElementById('mc-rep-content');
		host.innerHTML = '';
		C.renderTable(host, [
			{ key: 'id', label: t('ID') },
			{ key: 'vehicleId', label: t('Vehicle') },
			{ key: 'driverUserId', label: t('Driver') },
			{ key: 'start', label: t('Start'), render: (r) => fmtDateTime(r.startDatetime) },
			{ key: 'end', label: t('End'), render: (r) => fmtDateTime(r.endDatetime) },
			{ key: 'status', label: t('Status'), render: (r) => C.statusBadge(r.status) },
			{ key: 'purpose', label: t('Purpose') },
		], rows || [], { emptyHeading: t('No bookings in this window.'), ariaLabel: t('Bookings report') });
	}

	function renderDamage(rows) {
		const host = document.getElementById('mc-rep-content');
		host.innerHTML = '';
		C.renderTable(host, [
			{ key: 'id', label: t('ID') },
			{ key: 'vehicleId', label: t('Vehicle') },
			{ key: 'discoveryDatetime', label: t('Discovered'), render: (r) => fmtDateTime(r.discoveryDatetime) },
			{ key: 'severity', label: t('Severity'), render: (r) => C.statusBadge(r.severity, 'severity') },
			{ key: 'status', label: t('Status'), render: (r) => C.statusBadge(r.status) },
		], rows || [], { emptyHeading: t('No damage reports in this window.'), ariaLabel: t('Damage report') });
	}

	function renderCompliance(rows) {
		const host = document.getElementById('mc-rep-content');
		host.innerHTML = '';
		C.renderTable(host, [
			{ key: 'userId', label: t('Driver') },
			{ key: 'licenceStatus', label: t('Licence'), render: (r) => C.statusBadge(r.licenceStatus) },
			{ key: 'licenceExpiryDate', label: t('Expiry'), render: (r) => r.licenceExpiryDate ? fmtDate(r.licenceExpiryDate) : '—' },
			{ key: 'currentYearInstruction', label: t('Current instruction'), render: (r) => r.currentYearInstruction ? t('Yes') : t('No') },
			{ key: 'complianceStatus', label: t('Compliance'), render: (r) => C.statusBadge(r.complianceStatus) },
		], rows || [], { emptyHeading: t('No drivers on record.'), ariaLabel: t('Driver compliance') });
	}

	function renderNotifications(rows) {
		const host = document.getElementById('mc-rep-content');
		host.innerHTML = '';
		C.renderTable(host, [
			{ key: 'sentAt', label: t('Sent at'), render: (r) => fmtDateTime(r.sentAt) },
			{ key: 'notificationType', label: t('Type') },
			{ key: 'recipientUserId', label: t('Recipient') },
			{ key: 'channel', label: t('Channel') },
			{ key: 'status', label: t('Status') },
		], rows || [], { emptyHeading: t('No notifications in this window.'), ariaLabel: t('Notifications log') });
	}

	const renderers = {
		costs: { title: t('Cost report'), endpoint: '/api/reports/costs', render: renderCosts },
		utilisation: { title: t('Vehicle utilisation'), endpoint: '/api/reports/vehicle-utilisation', render: renderUtilisation },
		bookings: { title: t('Bookings report'), endpoint: '/api/reports/bookings', render: renderBookings },
		damage: { title: t('Damage report'), endpoint: '/api/reports/damage', render: renderDamage },
		compliance: { title: t('Driver compliance'), endpoint: '/api/reports/driver-compliance', render: renderCompliance },
		notifications: { title: t('Notifications log'), endpoint: '/api/reports/notifications', render: renderNotifications },
	};

	async function load() {
		const r = renderers[state.tab];
		if (!r) return;
		document.querySelector('[data-mc-rep-title]').textContent = r.title;
		document.querySelector('[data-mc-rep-sub]').textContent = (state.from || '—') + ' → ' + (state.to || '—');
		const host = document.getElementById('mc-rep-content');
		C.setLoading(host, true);
		try {
			const params = { from: state.from, to: state.to, vehicleId: state.vehicleId };
			if (state.tab === 'bookings' && state.driverUserId) {
				params.driverUserId = state.driverUserId;
			}
			const data = await API.get(r.endpoint, params);
			C.setLoading(host, false);
			r.render(data);
		} catch (e) { C.setLoading(host, false); M.reportError(e); }
	}

	async function downloadPdf() {
		if (!state.from || !state.to) {
			M.toast(t('Please pick a date range.'), 'error');
			return;
		}
		if (state.from > state.to) {
			M.toast(t('“To” must be on or after “From”.'), 'error');
			return;
		}
		const btn = document.getElementById('mc-rep-pdf');
		if (btn) {
			btn.disabled = true;
			btn.setAttribute('aria-busy', 'true');
		}
		try {
			const body = { tab: state.tab, from: state.from, to: state.to };
			if (state.vehicleId) {
				body.vehicleId = parseInt(state.vehicleId, 10);
			}
			if (state.tab === 'bookings' && state.driverUserId) {
				body.driverUserId = state.driverUserId;
			}
			const r = await API.post('/api/reports/export-pdf', body);
			const token = r && r.token;
			if (!token) {
				M.toast(t('Something went wrong.'), 'error');
				return;
			}
			window.location.href = API.url('/api/exports/download/' + encodeURIComponent(token));
		} catch (e) {
			M.reportError(e);
		} finally {
			if (btn) {
				btn.disabled = false;
				btn.removeAttribute('aria-busy');
			}
		}
	}

	async function init() {
		C.bootstrap();
		const f = document.getElementById('mc-rep-filters');
		await C.fillVehicleFilterSelect(document.getElementById('mc-rep-veh'), t);
		C.applySearchParamsToFilters(state, f);
		f?.addEventListener('change', (ev) => {
			state[ev.target.name] = ev.target.value || '';
			load();
		});
		document.getElementById('mc-rep-pdf')?.addEventListener('click', () => { void downloadPdf(); });
		load();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { void init(); });
	else void init();
})();
