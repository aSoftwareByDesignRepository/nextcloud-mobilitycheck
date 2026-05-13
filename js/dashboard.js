/**
 * Dashboard page.
 *
 * Loads /api/dashboard and renders role-scoped KPIs and lists.
 */
(function () {
	'use strict';

	const { h, renderTable, statusBadge, bookingStatusCell, detailUrl, bootstrap } = window.MobilityCheckComponents;
	const { t, polite, reportError } = window.MobilityCheckMessaging;
	const { fmtDate, fmtDateTime } = window.MobilityCheckDates;

	function vehicleColumn(row) {
		const name = row.vehicle_internal_name
			|| row.vehicleName
			|| row.internal_name
			|| (row.vehicleId || row.vehicle_id ? '#' + (row.vehicleId || row.vehicle_id) : '—');
		const plate = row.vehicle_licence_plate || row.licencePlate || row.licence_plate;
		return name + (plate ? ' · ' + plate : '');
	}

	function renderKpis(data) {
		const host = document.getElementById('mc-dash-kpis');
		if (!host) return;
		host.innerHTML = '';
		if (!data || typeof data !== 'object') {
			host.appendChild(h('p', { class: 'mc-section__sub' }, t('Nothing to report right now — you are all set.')));
			return;
		}
		const tiles = [];
		if (data.manager) {
			tiles.push({ label: t('Pending approvals'), value: data.manager.pendingApprovals, tone: 'info', hint: t('Bookings waiting for a decision') });
			tiles.push({ label: t('Open damage'), value: data.manager.openDamage, tone: 'warning', hint: t('Reports still being processed') });
			tiles.push({ label: t('Open relocations'), value: typeof data.manager.openRelocations === 'number' ? data.manager.openRelocations : 0, tone: (data.manager.openRelocations || 0) > 0 ? 'warning' : 'success', hint: t('Vehicles returned at a different station than pickup (one-way bookings).') });
			tiles.push({ label: t('Overdue maintenance'), value: data.manager.overdueMaintenance, tone: 'critical', hint: t('Vehicles needing service now') });
			tiles.push({ label: t('Available vehicles'), value: data.manager.availableVehicles + ' / ' + data.manager.vehicleCount, tone: 'success', hint: t('Ready to be booked') });
		}
		if (data.lineManager) {
			tiles.push({ label: t('Your approval inbox'), value: data.lineManager.pendingApprovals, tone: 'info', hint: t('Bookings from your drivers awaiting line manager approval') });
			if (typeof data.lineManager.openDamage === 'number') {
				tiles.push({ label: t('Open damage (your scope)'), value: data.lineManager.openDamage, tone: 'warning', hint: t('Reports for you or your supervised drivers') });
			}
		}
		if (data.driver) {
			tiles.push({ label: t('Your upcoming bookings'), value: data.driver.upcomingCount, tone: 'info', hint: t('From today onwards') });
			if (data.driver.profileExists) {
				const c = (data.driver.compliance || {});
				const drv = c.driver || {};
				const licStatus = drv.licence_status || 'pending';
				let licValue = labelForLicence(licStatus);
				if (c.daysToExpiry !== null && c.daysToExpiry !== undefined) {
					licValue += ' · ' + (c.daysToExpiry >= 0
						? t('{n} d to expiry', { n: c.daysToExpiry })
						: t('{n} d past expiry', { n: -c.daysToExpiry }));
				}
				tiles.push({ label: t('Licence status'), value: licValue, tone: complianceTone(licStatus), hint: t('Driving licence verification') });
				const instStatus = c.currentYearInstructionComplete ? 'completed' : 'due';
				tiles.push({ label: t('Yearly instruction'), value: c.currentYearInstructionComplete ? t('Completed') : t('Due'), tone: complianceTone(instStatus), hint: t('Annual safety briefing (UVV)') });
			} else {
				tiles.push({ label: t('Driver profile'), value: t('Not yet'), tone: 'warning', hint: t('Ask your fleet manager to set up your profile.') });
			}
		}
		if (tiles.length === 0) {
			host.appendChild(h('p', { class: 'mc-section__sub' }, t('Nothing to report right now — you are all set.')));
			return;
		}
		tiles.forEach((tile) => {
			host.appendChild(h('article', { class: 'mc-kpi mc-kpi--' + (tile.tone || 'info') }, [
				h('span', { class: 'mc-kpi__label' }, tile.label),
				h('span', { class: 'mc-kpi__value' }, String(tile.value ?? '—')),
				tile.hint ? h('span', { class: 'mc-kpi__hint' }, tile.hint) : null,
			]));
		});
	}

	function labelForLicence(status) {
		switch (status) {
			case 'verified': return t('Verified');
			case 'pending': return t('Pending');
			case 'expiring': return t('Expiring soon');
			case 'expired': return t('Expired');
			case 'rejected': return t('Rejected');
			case 'missing': return t('Missing');
			default: return status || '—';
		}
	}

	function complianceTone(status) {
		switch (status) {
			case 'verified':
			case 'current':
			case 'completed':
				return 'success';
			case 'pending':
			case 'pending_review':
				return 'info';
			case 'expiring':
			case 'due':
				return 'warning';
			case 'expired':
			case 'overdue':
			case 'rejected':
			case 'missing':
				return 'critical';
			default:
				return 'info';
		}
	}

	function renderMine(rows) {
		const host = document.getElementById('mc-dash-mine-list');
		if (!host) return;
		renderTable(host, [
			{ key: 'vehicle', label: t('Vehicle'), render: vehicleColumn },
			{ key: 'start', label: t('Start'), render: (r) => fmtDateTime(r.startDatetime || r.start_datetime) },
			{ key: 'end', label: t('End'), render: (r) => fmtDateTime(r.endDatetime || r.end_datetime) },
			{ key: 'status', label: t('Status'), render: (r) => bookingStatusCell(r) },
			{ key: 'actions', label: t('Actions'), render: (r) => h('a', { class: 'button', href: detailUrl('bookings', r.id) }, t('Open')) },
		], rows, { ariaLabel: t('Your upcoming bookings'), emptyHeading: t('No upcoming bookings yet'), emptyHint: t('Start a new booking from the action above.') });
	}

	function renderPending(rows) {
		const host = document.getElementById('mc-dash-pending-list');
		if (!host) return;
		renderTable(host, [
			{ key: 'driver', label: t('Driver'), render: (r) => r.driverUserId || r.driver_user_id || '—' },
			{ key: 'vehicle', label: t('Vehicle'), render: vehicleColumn },
			{ key: 'window', label: t('Window'), render: (r) => fmtDateTime(r.startDatetime || r.start_datetime) + ' → ' + fmtDateTime(r.endDatetime || r.end_datetime) },
			{ key: 'purpose', label: t('Purpose') },
			{ key: 'actions', label: t('Actions'), render: (r) => h('a', { class: 'button', href: detailUrl('bookings', r.id) }, t('Review')) },
		], rows, { ariaLabel: t('Pending approvals'), emptyHeading: t('Inbox zero — no pending approvals') });
	}

	function renderLmPending(rows) {
		const host = document.getElementById('mc-dash-lm-pending-list');
		if (!host) return;
		renderTable(host, [
			{ key: 'driver', label: t('Driver'), render: (r) => r.driverUserId || r.driver_user_id || '—' },
			{ key: 'vehicle', label: t('Vehicle'), render: vehicleColumn },
			{ key: 'window', label: t('Window'), render: (r) => fmtDateTime(r.startDatetime || r.start_datetime) + ' → ' + fmtDateTime(r.endDatetime || r.end_datetime) },
			{ key: 'purpose', label: t('Purpose') },
			{ key: 'actions', label: t('Actions'), render: (r) => h('a', { class: 'button', href: detailUrl('bookings', r.id) }, t('Review')) },
		], rows, { ariaLabel: t('Your approval inbox'), emptyHeading: t('No bookings need your approval right now.') });
	}

	function renderDamage(rows, hostId) {
		const host = document.getElementById(hostId || 'mc-dash-damage-list');
		if (!host) return;
		renderTable(host, [
			{ key: 'vehicle', label: t('Vehicle'), render: vehicleColumn },
			{ key: 'severity', label: t('Severity'), render: (r) => statusBadge(r.severity, 'severity') },
			{ key: 'discovered', label: t('Discovered'), render: (r) => fmtDateTime(r.discoveryDatetime || r.discovery_datetime) },
			{ key: 'status', label: t('Status'), render: (r) => statusBadge(r.status) },
			{ key: 'actions', label: t('Actions'), render: (r) => h('a', { class: 'button', href: detailUrl('damage', r.id) }, t('Open')) },
		], rows, { ariaLabel: (hostId === 'mc-dash-lm-damage-list' ? t('Open damage (your scope)') : t('Open damage')), emptyHeading: t('No open damage reports.') });
	}

	function renderMaint(rows) {
		const host = document.getElementById('mc-dash-maint-list');
		if (!host) return;
		renderTable(host, [
			{ key: 'vehicle', label: t('Vehicle'), render: vehicleColumn },
			{ key: 'name', label: t('Schedule'), render: (r) => r.name || r.scheduleName },
			{ key: 'due', label: t('Due'), render: (r) => r.dueDate || r.due_date ? fmtDate(r.dueDate || r.due_date) : (r.dueOdometerKm ? r.dueOdometerKm + ' km' : '—') },
			{ key: 'blocking', label: t('Blocks bookings'), render: (r) => r.isBlocking || r.is_blocking ? t('Yes') : t('No') },
		], rows, { ariaLabel: t('Overdue maintenance'), emptyHeading: t('Maintenance is fully on schedule.') });
	}

	function renderRelocations(rows) {
		const host = document.getElementById('mc-dash-relocations-list');
		if (!host) return;
		renderTable(host, [
			{
				key: 'route',
				label: t('Station relocation route'),
				render: (r) => {
					const from = [r.from_station_code, r.from_station_name].filter(Boolean).join(' · ');
					const to = [r.to_station_code, r.to_station_name].filter(Boolean).join(' · ');
					return (from || '—') + ' → ' + (to || '—');
				},
			},
			{ key: 'vehicle', label: t('Vehicle'), render: vehicleColumn },
			{
				key: 'booking',
				label: t('Booking'),
				render: (r) => h('a', { class: 'button', href: detailUrl('bookings', r.source_booking_id) }, '#' + String(r.source_booking_id)),
			},
			{
				key: 'actions',
				label: t('Actions'),
				render: (r) => {
					const btn = h('button', { type: 'button', class: 'button', 'aria-label': t('Mark relocation done') }, t('Mark relocation done'));
					btn.addEventListener('click', async () => {
						btn.disabled = true;
						try {
							await window.MobilityCheckApi.post('/api/relocations/' + encodeURIComponent(String(r.id)) + '/complete', { notes: '' });
							polite(t('Relocation marked complete.'));
							await load();
						} catch (e) {
							btn.disabled = false;
							reportError(e);
						}
					});
					return btn;
				},
			},
		], rows, {
			ariaLabel: t('Relocation queue'),
			emptyHeading: t('No open relocations.'),
			emptyHint: t('One-way bookings between stations appear here after check-in.'),
		});
	}

	async function load() {
		const errorEl = document.getElementById('mc-dash-error');
		try {
			if (errorEl) { errorEl.hidden = true; errorEl.textContent = ''; }
			const raw = await window.MobilityCheckApi.get('/api/dashboard');
			const data = raw && typeof raw === 'object' ? raw : {};
			renderKpis(data);
			if (data.driver) renderMine(data.driver.upcomingBookings || []);
			if (data.manager) {
				renderPending(data.manager.pendingApprovalsList || []);
				renderDamage(data.manager.openDamageList || []);
				renderRelocations(data.manager.openRelocationsList || []);
				renderMaint(data.manager.overdueMaintenanceList || []);
			}
			if (data.lineManager) {
				renderLmPending(data.lineManager.pendingApprovalsList || []);
				renderDamage(data.lineManager.openDamageList || [], 'mc-dash-lm-damage-list');
			}
			polite(t('Dashboard updated.'));
		} catch (e) {
			if (errorEl) {
				errorEl.hidden = false;
				errorEl.textContent = window.MobilityCheckMessaging.resolveError(e);
			}
			reportError(e);
		}
	}

	function init() {
		bootstrap();
		document.getElementById('mc-dash-refresh')?.addEventListener('click', load);
		load();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
