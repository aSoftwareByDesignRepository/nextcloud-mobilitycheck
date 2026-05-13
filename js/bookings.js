/**
 * Bookings list page.
 *
 * Two view modes for approvers (fleet managers / line managers):
 *   - "all": every booking the user is allowed to see (default)
 *   - "approvals": only bookings currently awaiting THIS user's decision
 *
 * Drivers and other roles only see the "all" view; the view-switch tablist
 * is only rendered server-side for users with an approver role.
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const { fmtDateTime } = window.MobilityCheckDates;
	const t = M.t;

	const filters = { status: '', from: '', to: '', vehicleId: '', driverUserId: '' };
	let view = 'all';

	function isApproverView() {
		return view === 'approvals';
	}

	function effectiveEndpoint() {
		return isApproverView() ? '/api/my-approvals' : '/api/bookings';
	}

	function effectiveFilters() {
		// The my-approvals endpoint returns the personal pending queue and
		// does not accept filter parameters — the pool is intentionally bounded.
		if (!isApproverView()) return filters;
		return {};
	}

	function updateApprovalsCount(n) {
		const badge = document.getElementById('mc-bks-approvals-count');
		if (!badge) return;
		if (typeof n !== 'number' || n <= 0) {
			badge.hidden = true;
			badge.textContent = '';
		} else {
			badge.hidden = false;
			badge.textContent = String(n);
		}
	}

	async function refreshApprovalsBadge() {
		const tab = document.getElementById('mc-bks-tab-approvals');
		if (!tab) return;
		try {
			const rows = await API.get('/api/my-approvals', {});
			updateApprovalsCount(Array.isArray(rows) ? rows.length : 0);
		} catch (_) {
			updateApprovalsCount(0);
		}
	}

	async function load() {
		const host = document.getElementById('mc-bks-list');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const rows = await API.get(effectiveEndpoint(), effectiveFilters());
			C.setLoading(host, false);
			const emptyHeading = isApproverView()
				? t('Nothing is waiting for your approval right now.')
				: t('No bookings match your filters yet.');
			const ariaLabel = isApproverView() ? t('Awaiting my approval') : t('All bookings');
			C.renderTable(host, [
				{ key: 'id', label: t('Reference'), render: (r) => C.h('a', { class: 'mc-button-link', href: C.detailUrl('bookings', r.id) }, '#' + r.id) },
				{ key: 'vehicle', label: t('Vehicle'), render: (r) => {
					const name = r.vehicle_internal_name || ('#' + r.vehicle_id);
					return name + (r.vehicle_licence_plate ? ' · ' + r.vehicle_licence_plate : '');
				} },
				{ key: 'driver_user_id', label: t('Driver') },
				{ key: 'start', label: t('Start'), render: (r) => fmtDateTime(r.start_datetime) },
				{ key: 'end', label: t('End'), render: (r) => fmtDateTime(r.end_datetime) },
				{ key: 'status', label: t('Status'), render: (r) => C.bookingStatusCell(r) },
				{ key: 'purpose', label: t('Purpose') },
			], rows, { ariaLabel, emptyHeading });
			if (isApproverView()) {
				updateApprovalsCount(Array.isArray(rows) ? rows.length : 0);
			}
		} catch (e) {
			C.setLoading(host, false);
			M.reportError(e);
		}
	}

	function setView(next, opts) {
		if (next !== 'all' && next !== 'approvals') return;
		if (view === next) return;
		view = next;
		const filtersForm = document.getElementById('mc-bks-filters');
		if (filtersForm) {
			// The my-approvals endpoint accepts no filters; hide the toolbar
			// entirely in that view to avoid a misleading UI.
			filtersForm.hidden = isApproverView();
		}
		const list = document.getElementById('mc-bks-list');
		if (list) {
			list.setAttribute('aria-labelledby', isApproverView() ? 'mc-bks-tab-approvals' : 'mc-bks-tab-all');
		}
		// Roving tabindex: the active tab is the only tab in the tab order.
		document.querySelectorAll('#mc-bks-view-tabs .mc-view-tab').forEach((btn) => {
			const active = btn.getAttribute('data-mc-view') === view;
			btn.classList.toggle('is-active', active);
			btn.setAttribute('aria-selected', active ? 'true' : 'false');
			btn.tabIndex = active ? 0 : -1;
			if (active && opts && opts.focus) {
				btn.focus();
			}
		});
		load();
	}

	async function populateBookingFilterSelects() {
		const vSel = document.getElementById('mc-bks-vehicle');
		const dSel = document.getElementById('mc-bks-driver');
		if (!vSel && !dSel) return;
		try {
			const [vehicles, drivers] = await Promise.all([
				vSel ? API.get('/api/vehicles', { activeOnly: 1 }) : Promise.resolve([]),
				dSel ? API.get('/api/drivers') : Promise.resolve([]),
			]);
			if (vSel) {
				vSel.innerHTML = '';
				const o0 = document.createElement('option');
				o0.value = '';
				o0.textContent = t('All vehicles');
				vSel.appendChild(o0);
				API.asArray(vehicles).forEach((v) => {
					const opt = document.createElement('option');
					opt.value = String(v.id);
					opt.textContent = (v.internal_name || ('#' + v.id)) + (v.licence_plate ? ' · ' + v.licence_plate : '');
					vSel.appendChild(opt);
				});
			}
			if (dSel) {
				dSel.innerHTML = '';
				const o0 = document.createElement('option');
				o0.value = '';
				o0.textContent = t('All drivers');
				dSel.appendChild(o0);
				API.asArray(drivers).forEach((r) => {
					const opt = document.createElement('option');
					opt.value = String(r.user_id || '');
					const dn = r.displayName || r.user_id;
					opt.textContent = dn + (r.user_id && dn !== r.user_id ? ' (' + r.user_id + ')' : '');
					dSel.appendChild(opt);
				});
			}
		} catch (e) { M.reportError(e); }
	}

	function bindTabKeyboard() {
		const tabs = Array.from(document.querySelectorAll('#mc-bks-view-tabs .mc-view-tab'));
		if (tabs.length < 2) return;
		tabs.forEach((tab) => {
			tab.addEventListener('keydown', (ev) => {
				const idx = tabs.indexOf(ev.currentTarget);
				if (idx < 0) return;
				let next = -1;
				switch (ev.key) {
					case 'ArrowRight':
					case 'ArrowDown':
						next = (idx + 1) % tabs.length;
						break;
					case 'ArrowLeft':
					case 'ArrowUp':
						next = (idx - 1 + tabs.length) % tabs.length;
						break;
					case 'Home':
						next = 0;
						break;
					case 'End':
						next = tabs.length - 1;
						break;
					default:
						return;
				}
				ev.preventDefault();
				const targetView = tabs[next].getAttribute('data-mc-view') || 'all';
				setView(targetView, { focus: true });
			});
		});
	}

	async function init() {
		C.bootstrap();
		const f = document.getElementById('mc-bks-filters');
		f?.addEventListener('change', (ev) => {
			if (!ev.target || !ev.target.name) return;
			filters[ev.target.name] = ev.target.value || '';
			load();
		});
		document.querySelectorAll('#mc-bks-view-tabs .mc-view-tab').forEach((btn) => {
			btn.addEventListener('click', () => {
				setView(btn.getAttribute('data-mc-view') || 'all');
			});
		});
		bindTabKeyboard();
		await populateBookingFilterSelects();
		C.applySearchParamsToFilters(filters, f);
		load();
		// Refresh the badge in the background so the count is correct even before
		// the user clicks the "Awaiting my approval" tab.
		refreshApprovalsBadge();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { void init(); });
	else void init();
})();
