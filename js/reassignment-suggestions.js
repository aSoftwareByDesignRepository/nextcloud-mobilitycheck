/**
 * Reassignment suggestions (§A5.4) — fleet managers accept or dismiss.
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const { fmtDateTime } = window.MobilityCheckDates;
	const t = M.t;

	function vehicleLabel(v) {
		if (!v) return '—';
		return (v.internal_name || '') + (v.licence_plate ? ' · ' + v.licence_plate : '');
	}

	async function load() {
		const host = document.getElementById('mc-rs-list');
		const err = document.getElementById('mc-rs-error');
		if (!host) return;
		if (err) { err.hidden = true; err.textContent = ''; }
		C.setLoading(host, true);
		try {
			const rows = API.asArray(await API.get('/api/reassignment-suggestions'));
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'id', label: t('Suggestion') },
				{ key: 'booking_id', label: t('Booking'), render: (r) => C.h('a', { class: 'mc-button-link', href: C.detailUrl('bookings', r.booking_id) }, '#' + r.booking_id) },
				{ key: 'window', label: t('Window'), render: (r) => fmtDateTime(r.start_datetime) + ' → ' + fmtDateTime(r.end_datetime) },
				{ key: 'driver', label: t('Driver'), render: (r) => String(r.driver_user_id || '') },
				{ key: 'from', label: t('From vehicle'), render: (r) => vehicleLabel(r.from_vehicle) },
				{ key: 'to', label: t('Suggested vehicle'), render: (r) => vehicleLabel(r.to_vehicle) },
				{
					key: 'actions', label: t('Actions'), render: (r) => {
						const wrap = C.h('div', { class: 'mc-row-actions' });
						wrap.appendChild(C.h('button', {
							type: 'button',
							class: 'button button-primary',
							onClick: () => act(r.id, 'accept'),
						}, t('Accept')));
						wrap.appendChild(C.h('button', {
							type: 'button',
							class: 'button',
							onClick: () => act(r.id, 'dismiss'),
						}, t('Dismiss')));
						return wrap;
					},
				},
			], rows, {
				ariaLabel: t('Reassignment suggestions'),
				emptyHeading: t('No open suggestions.'),
				emptyDescription: t('When intelligent allocation is enabled and a vehicle becomes unavailable, proposals appear here.'),
			});
		} catch (e) {
			C.setLoading(host, false);
			if (err) { err.hidden = false; err.textContent = M.resolveError(e); }
			else { M.reportError(e); }
		}
	}

	async function act(id, kind) {
		try {
			if (kind === 'accept') {
				await API.post('/api/reassignment-suggestions/' + id + '/accept', {});
				M.toast(t('Suggestion accepted — booking updated.'), 'success');
			} else {
				await API.post('/api/reassignment-suggestions/' + id + '/dismiss', {});
				M.toast(t('Suggestion dismissed.'), 'success');
			}
			load();
		} catch (e) {
			M.reportError(e);
		}
	}

	function init() {
		C.bootstrap();
		document.getElementById('mc-rs-refresh')?.addEventListener('click', load);
		load();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
