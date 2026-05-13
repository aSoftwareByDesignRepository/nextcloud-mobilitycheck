/**
 * Booking detail page.
 *
 *  - Loads /api/bookings/{id} and /api/bootstrap (approval workflow flag)
 *  - Renders status, vehicle, driver, window, purpose, assignment, base location
 *  - Accessible booking-path stepper (reservation → approval → pickup → trip → return)
 *  - Check-out / check-in with odometer, fuel, zones, condition notes,
 *    optional pickup location (checkout), return location (required for pool/group check-in),
 *    business-only declaration when required by tax treatment
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const { fmtDateTime, fromDateTimeLocalInput, isoForDateTimeInput } = window.MobilityCheckDates;
	const t = M.t;

	const ZONES = ['front', 'rear', 'left', 'right', 'roof', 'interior', 'underbody'];
	const FUEL = [['empty', 'Empty'], ['quarter', 'Quarter'], ['half', 'Half'], ['three_quarter', 'Three quarters'], ['full', 'Full']];

	let approvalWorkflowEnabled = true;
	let approvalMode = 'none';
	let bookingExtensionMaxMinutes = 120;
	let overdueReturnGraceMinutes = 120;
	let approvalResetsOnTimeChange = false;
	let stationStrictMode = false;
	/** @type {Array<{id:number,code?:string,name?:string,isActive?:boolean}>} */
	let stationsCatalog = [];

	function id() {
		const el = document.getElementById('mc-booking-id');
		return el ? parseInt(el.value, 10) : 0;
	}

	function row(dl, dt, dd) {
		dl.appendChild(C.h('dt', null, dt));
		dl.appendChild(C.h('dd', null, dd == null || dd === '' ? '—' : dd));
	}
	async function ensureStationsCatalog() {
		if (stationsCatalog.length > 0) return;
		try {
			stationsCatalog = API.asArray(await API.get('/api/stations'));
		} catch (_) {
			stationsCatalog = [];
		}
	}

	function stationLabel(rawId) {
		if (rawId === undefined || rawId === null || rawId === '') return '';
		const sid = typeof rawId === 'number' ? rawId : parseInt(String(rawId), 10);
		if (!sid || Number.isNaN(sid)) return '';
		const r = stationsCatalog.find((s) => Number(s.id) === sid);
		if (!r) return '#' + sid;
		const bits = [r.code, r.name].filter(Boolean).join(' · ');
		return bits || '#' + sid;
	}
	function zoneLabel(z) {
		const keys = {
			front: 'Front', rear: 'Rear', left: 'Left', right: 'Right', roof: 'Roof', interior: 'Interior', underbody: 'Underbody',
		};
		return t(keys[z] || z);
	}

	function describeStatus(s) {
		const map = {
			pending_fleet: t('Waiting for fleet manager approval'),
			pending_line_manager: t('Waiting for line manager approval'),
			pending_approval: t('Waiting for fleet manager approval'),
			approved: t('Approved — collect the vehicle at the start time'),
			active: t('Vehicle is checked out and in use'),
			completed: t('Booking has been checked in and closed'),
			cancelled: t('Booking has been cancelled'),
			rejected: t('Booking was rejected'),
		};
		return map[s] || s;
	}

	function formatCancellationReason(raw) {
		const s = (raw || '').trim();
		if (s === 'NO_SHOW') {
			return t('NO_SHOW — automatically cancelled: the vehicle was not picked up within the grace period.');
		}
		return s;
	}

	function assignmentLabel(mode) {
		if (mode === 'pool') return t('Pool (shared fleet vehicle)');
		if (mode === 'group') return t('Group vehicle (restricted access)');
		if (mode === 'dedicated') return t('Dedicated assignment');
		return mode || '—';
	}

	/** Human label for `approval_mode_snapshot` stored on the booking (not raw setting keys). */
	function approvalSnapshotLabel(snap) {
		const s = (snap && String(snap)) || '';
		if (s === '' || s === 'none') return t('Automatic confirmation');
		if (s === 'fleet_manager') return t('Fleet manager approval');
		if (s === 'line_manager') return t('Line manager approval');
		if (s === 'line_manager_then_fleet') return t('Line manager, then fleet manager');
		return s;
	}

	/**
	 * Plain-language pickup state: only a successful check-out means the car left.
	 * Scheduled start time alone does not flip status (see no-show job for grace).
	 */
	function handoverSummary(b) {
		const st = String(b.status || '');
		const logs = b.logs || {};
		const co = logs.checkout;
		if (st === 'completed') {
			return t('Trip closed: check-in has been recorded.');
		}
		if (st === 'cancelled') {
			return t('Cancelled — no completed vehicle handover on this reservation.');
		}
		if (st === 'rejected') {
			return t('Rejected — the vehicle was never checked out for this booking.');
		}
		if (st === 'active') {
			if (co && co.recordedAt) {
				return t('Picked up: check-out was recorded at {when}.', { when: fmtDateTime(co.recordedAt) });
			}
			return t('Status is active but no check-out record was found. Ask a fleet administrator to review the data.');
		}
		if (st === 'approved') {
			return t('Not picked up yet. When you physically collect the vehicle, use check-out on this page — the scheduled start time alone does not record pickup.');
		}
		if (st === 'pending_fleet' || st === 'pending_line_manager' || st === 'pending_approval') {
			return t('Not picked up: this booking is still awaiting approval.');
		}
		return '—';
	}

	function requiresReturnLocationNote(mode) {
		return mode === 'pool' || mode === 'group';
	}

	/**
	 * @param {object} b booking from API (includes `return_schedule` from server)
	 */
	function renderReturnInsights(b) {
		const wrap = document.getElementById('mc-bk-return-insights');
		const body = wrap && wrap.querySelector('[data-mc-bind="return-insights-body"]');
		if (!wrap || !body) return;
		body.innerHTML = '';
		const rs = b.return_schedule;
		if (!rs || typeof rs !== 'object') {
			wrap.setAttribute('hidden', 'hidden');
			return;
		}
		const grace = typeof rs.overdue_return_grace_minutes === 'number'
			? rs.overdue_return_grace_minutes
			: overdueReturnGraceMinutes;
		const parts = [];
		if (b.status === 'completed' && rs.completed_missing_checkin_log) {
			parts.push(C.h('div', { class: 'mc-callout mc-callout--critical', role: 'alert' }, [
				C.h('p', { class: 'mc-callout__title' }, t('Data consistency warning')),
				C.h('p', null, t('This booking is marked complete but no check-in record was found. Ask a fleet administrator to review the database.')),
			]));
		} else if (b.status === 'completed' && typeof rs.checkin_vs_end_minutes === 'number') {
			const m = rs.checkin_vs_end_minutes;
			if (m > 0) {
				parts.push(C.h('div', { class: 'mc-callout mc-callout--warning', role: 'status' }, [
					C.h('p', { class: 'mc-callout__title' }, t('Late return')),
					C.h('p', null, t('The vehicle was checked in {count} minutes after the scheduled booking end (times in UTC).', { count: String(m) })),
					rs.actual_return_utc ? C.h('p', { class: 'mc-form-row__hint' }, t('Check-in recorded: {when}', { when: fmtDateTime(rs.actual_return_utc) })) : null,
				].filter(Boolean)));
			} else if (m < 0) {
				const early = Math.abs(m);
				parts.push(C.h('div', { class: 'mc-callout mc-callout--success', role: 'status' }, [
					C.h('p', { class: 'mc-callout__title' }, t('Early return')),
					C.h('p', null, t('The vehicle was checked in {count} minutes before the scheduled booking end (times in UTC).', { count: String(early) })),
				]));
			} else {
				parts.push(C.h('div', { class: 'mc-callout mc-callout--success', role: 'status' }, [
					C.h('p', { class: 'mc-callout__title' }, t('On-time return')),
					C.h('p', null, t('Check-in was recorded in the same minute as the scheduled end (UTC), or immediately after.')),
				]));
			}
		} else if (b.status === 'active' && rs.active_past_scheduled_end) {
			const past = typeof rs.active_minutes_past_end === 'number' ? rs.active_minutes_past_end : 0;
			const alertSent = !!rs.fleet_alert_eligible;
			const lines = [
				C.h('p', { class: 'mc-callout__title' }, t('Past scheduled return time')),
				C.h('p', null, t('The booking end time has passed. Please check in as soon as the vehicle is parked — this records the real return time and frees the car for colleagues.')),
				C.h('p', null, t('You are about {count} minutes past the scheduled end (UTC).', { count: String(past) })),
			];
			if (alertSent) {
				lines.push(C.h('p', null, t('After {grace} minutes past the scheduled end without check-in, drivers and fleet supervisors receive an automatic reminder (organisation setting).', { grace: String(grace) })));
			} else {
				lines.push(C.h('p', null, t('Automatic reminders to you and fleet supervisors are sent only after a grace period ({grace} minutes past the scheduled end).', { grace: String(grace) })));
			}
			parts.push(C.h('div', { class: 'mc-callout mc-callout--warning', role: 'status' }, lines));
		}
		if (parts.length === 0) {
			wrap.setAttribute('hidden', 'hidden');
			return;
		}
		parts.forEach((n) => body.appendChild(n));
		wrap.removeAttribute('hidden');
	}

	/**
	 * @param {object} b booking from API
	 */
	function renderWorkflow(b) {
		const host = document.getElementById('mc-bk-workflow');
		if (!host) return;
		host.innerHTML = '';
		const st = b.status;
		if (st === 'cancelled') {
			host.appendChild(C.h('div', { class: 'mc-callout mc-callout--neutral', role: 'status' }, t('This booking was cancelled. The step-by-step path no longer applies.')));
			return;
		}
		if (st === 'rejected') {
			host.appendChild(C.h('div', { class: 'mc-callout mc-callout--warning', role: 'status' }, t('This booking was rejected. No vehicle was assigned.')));
			return;
		}

		const approvalOn = !!approvalWorkflowEnabled;
		const steps = [];
		steps.push({ key: 'reserve', title: t('Reservation'), desc: t('You requested this vehicle for the selected time window.') });
		if (!approvalOn) {
			steps.push({ key: 'auto', title: t('Automatic confirmation'), desc: t('Your organisation does not require a separate approval step for this booking.') });
		} else if (approvalMode === 'fleet_manager') {
			steps.push({ key: 'fleet', title: t('Fleet manager approval'), desc: t('A fleet manager or administrator must approve before you can check out the vehicle.') });
		} else if (approvalMode === 'line_manager') {
			steps.push({ key: 'lm', title: t('Line manager approval'), desc: t('Your assigned line manager must approve before you can check out the vehicle.') });
		} else if (approvalMode === 'line_manager_then_fleet') {
			steps.push({ key: 'lm', title: t('Line manager approval'), desc: t('Your line manager approves first.') });
			steps.push({ key: 'fleet', title: t('Fleet manager approval'), desc: t('A fleet manager then confirms fleet-side constraints.') });
		} else {
			steps.push({ key: 'auto', title: t('Automatic confirmation'), desc: t('Approval is disabled in settings.') });
		}
		steps.push({ key: 'pickup', title: t('Pick up vehicle'), desc: t('Check-out records odometer, fuel and condition before you drive.') });
		steps.push({ key: 'trip', title: t('Trip'), desc: t('The vehicle is assigned to you until you check it back in.') });
		steps.push({ key: 'return', title: t('Return vehicle'), desc: t('Check-in closes the booking. For shared vehicles, say clearly where you parked the car.') });

		function isPending(st) {
			return st === 'pending_fleet' || st === 'pending_line_manager' || st === 'pending_approval';
		}

		function stepState(idx) {
			const step = steps[idx];
			if (step.key === 'reserve') {
				return 'done';
			}
			if (step.key === 'auto') {
				if (isPending(st)) return 'current';
				return 'done';
			}
			if (step.key === 'lm') {
				if (st === 'pending_line_manager') return 'current';
				if (st === 'pending_fleet' || st === 'approved' || st === 'active' || st === 'completed') return 'done';
				return 'upcoming';
			}
			if (step.key === 'fleet') {
				if (st === 'pending_fleet') return 'current';
				if (st === 'pending_line_manager') return 'upcoming';
				if (st === 'approved' || st === 'active' || st === 'completed') return 'done';
				return 'upcoming';
			}
			if (step.key === 'pickup') {
				if (isPending(st)) return 'upcoming';
				if (st === 'approved') return 'current';
				return 'done';
			}
			if (step.key === 'trip') {
				if (st === 'approved') return 'upcoming';
				if (st === 'active') return 'current';
				return 'done';
			}
			if (step.key === 'return') {
				if (st === 'active') return 'current';
				if (st === 'completed') return 'done';
				return 'upcoming';
			}
			return 'upcoming';
		}

		const ol = C.h('ol', { class: 'mc-workflow__list' });
		steps.forEach((step, idx) => {
			const state = stepState(idx);
			const li = C.h('li', {
				class: 'mc-workflow__step mc-workflow__step--' + state,
			});
			if (state === 'current') li.setAttribute('aria-current', 'step');
			const num = C.h('span', { class: 'mc-workflow__num', 'aria-hidden': 'true' }, String(idx + 1));
			const body = C.h('div', { class: 'mc-workflow__body' }, [
				C.h('h3', { class: 'mc-workflow__title' }, step.title),
				C.h('p', { class: 'mc-workflow__desc' }, step.desc),
			]);
			li.appendChild(num);
			li.appendChild(body);
			ol.appendChild(li);
		});
		host.appendChild(ol);
	}

	function renderProxyBanner(b) {
		const el = document.getElementById('mc-bk-proxy-banner');
		if (!el) return;
		const creator = String(b.created_by_user_id || '').trim();
		const driver = String(b.driver_user_id || '').trim();
		const ctx = C.bootstrap();
		const currentUserId = ctx.root && ctx.root.getAttribute('data-mc-current-user');
		el.innerHTML = '';
		if (creator && driver && creator !== driver && currentUserId === driver) {
			el.hidden = false;
			const p = C.h('p', { class: 'mc-callout__title' }, t('Booking created on your behalf'));
			const body = C.h('p', null, t('A colleague ({id}) reserved this vehicle for you. Review the times and vehicle; if anything is wrong, cancel while the booking is still pending or contact your fleet desk.', { id: creator }));
			el.appendChild(p);
			el.appendChild(body);
			const st = String(b.status || '');
			const pending = st === 'pending_fleet' || st === 'pending_line_manager' || st === 'pending_approval';
			if (pending) {
				el.appendChild(C.h('p', { class: 'mc-form-row__hint' }, t('You can cancel this request from the actions above if you did not ask for it.')));
			}
			return;
		}
		el.hidden = true;
	}

	function mayEditBookingDetails(b, ctx) {
		const st = String(b.status || '');
		const pending = st === 'pending_fleet' || st === 'pending_line_manager' || st === 'pending_approval';
		if (!pending && st !== 'approved') return false;
		if (b.logs && b.logs.checkout) return false;
		const currentUserId = ctx.root && ctx.root.getAttribute('data-mc-current-user');
		const isManager = ctx.root && ctx.root.getAttribute('data-mc-is-manager') === '1';
		const isAppAdmin = ctx.root && ctx.root.getAttribute('data-mc-is-app-admin') === '1';
		const isAuditor = ctx.root && ctx.root.getAttribute('data-mc-is-auditor') === '1';
		const lmScopedReader = ctx.root && ctx.root.getAttribute('data-mc-line-manager-scoped-reader') === '1';
		const isFleetActor = isManager || isAppAdmin;
		if (isAuditor || (lmScopedReader && !isFleetActor)) return false;
		return (b.driver_user_id === currentUserId || isFleetActor);
	}

	async function fillVehicleSelectForEdit(selectEl, currentVehicleId) {
		const rows = API.asArray(await API.get('/api/vehicles', { activeOnly: 1 }));
		const have = new Set(rows.map((r) => r.id));
		if (currentVehicleId && !have.has(currentVehicleId)) {
			try {
				const one = await API.get('/api/vehicles/' + currentVehicleId);
				if (one && one.id) rows.unshift(one);
			} catch (_) { /* keep list without stale vehicle */ }
		}
		selectEl.innerHTML = '';
		rows.forEach((v) => {
			const opt = document.createElement('option');
			opt.value = String(v.id);
			opt.textContent = (v.internal_name || ('#' + v.id)) + (v.licence_plate ? ' · ' + v.licence_plate : '');
			selectEl.appendChild(opt);
		});
		if (currentVehicleId) selectEl.value = String(currentVehicleId);
	}

	async function renderEditSection(b) {
		const host = document.getElementById('mc-bk-edit-host');
		if (!host) return;
		host.innerHTML = '';
		const ctx = C.bootstrap();
		if (!mayEditBookingDetails(b, ctx)) return;
		const isManager = ctx.root && ctx.root.getAttribute('data-mc-is-manager') === '1';
		const isAppAdmin = ctx.root && ctx.root.getAttribute('data-mc-is-app-admin') === '1';
		const isFleetActor = isManager || isAppAdmin;

		const section = C.h('section', { class: 'mc-card mc-section', 'aria-labelledby': 'mc-bk-edit-h' });
		section.appendChild(C.h('header', { class: 'mc-section__header' }, [
			C.h('div', null, [
				C.h('h2', { id: 'mc-bk-edit-h' }, t('Change booking details')),
				C.h('p', { class: 'mc-section__sub' }, t('Adjust the reservation before check-out. Changing the vehicle or moving times by more than fifteen minutes may send the booking through approval again, depending on your organisation rules.')),
			]),
		]));
		if (String(b.status || '') === 'approved' && approvalResetsOnTimeChange) {
			section.appendChild(C.h('div', { class: 'mc-callout mc-callout--neutral', role: 'note' }, [
				C.h('p', null, t('Your organisation is set to re-check approval when the start or end time moves by more than fifteen minutes on an already approved booking.')),
			]));
		}
		const form = C.h('form', { id: 'mc-bk-edit-form', class: 'mc-form', novalidate: true });
		const grid = C.h('div', { class: 'mc-grid-2' });
		const vehRow = C.h('div', { class: 'mc-form-row', 'data-mc-bk-edit-vehicle-row': '1', hidden: !isFleetActor });
		vehRow.appendChild(C.h('label', { for: 'mc-bk-edit-vehicle' }, [t('Vehicle'), ' ', C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*')]));
		vehRow.appendChild(C.h('select', { id: 'mc-bk-edit-vehicle', name: 'vehicleId', required: isFleetActor }));
		vehRow.appendChild(C.h('p', { class: 'mc-form-row__hint' }, t('Only a fleet manager may change the vehicle on an existing booking.')));
		vehRow.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		grid.appendChild(vehRow);
		grid.appendChild(C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: 'mc-bk-edit-start' }, [t('Start'), ' ', C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*')]),
			C.h('input', { id: 'mc-bk-edit-start', name: 'startDatetime', type: 'datetime-local', required: true, value: isoForDateTimeInput(b.start_datetime) }),
			C.h('p', { class: 'mc-form-row__error', role: 'alert' }),
		]));
		grid.appendChild(C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: 'mc-bk-edit-end' }, [t('End'), ' ', C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*')]),
			C.h('input', { id: 'mc-bk-edit-end', name: 'endDatetime', type: 'datetime-local', required: true, value: isoForDateTimeInput(b.end_datetime) }),
			C.h('p', { class: 'mc-form-row__hint' }, t('Minimum 15 minutes, maximum 90 days.')),
			C.h('p', { class: 'mc-form-row__error', role: 'alert' }),
		]));
		grid.appendChild(C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: 'mc-bk-edit-dest' }, t('Destination')),
			C.h('input', { id: 'mc-bk-edit-dest', name: 'destination', type: 'text', maxlength: 250, value: b.destination || '', autocomplete: 'off' }),
			C.h('p', { class: 'mc-form-row__error', role: 'alert' }),
		]));
		grid.appendChild(C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: 'mc-bk-edit-cc' }, t('Cost centre')),
			C.h('input', { id: 'mc-bk-edit-cc', name: 'costCentre', type: 'text', maxlength: 80, value: b.cost_centre || '', autocomplete: 'off' }),
			C.h('p', { class: 'mc-form-row__error', role: 'alert' }),
		]));
		grid.appendChild(C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: 'mc-bk-edit-km' }, t('Expected distance (km)')),
			C.h('input', { id: 'mc-bk-edit-km', name: 'expectedDistanceKm', type: 'number', min: 0, max: 1000000, value: b.expected_distance_km != null ? String(b.expected_distance_km) : '' }),
			C.h('p', { class: 'mc-form-row__error', role: 'alert' }),
		]));
		form.appendChild(grid);
		form.appendChild(C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: 'mc-bk-edit-purpose' }, [t('Purpose'), ' ', C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*')]),
			C.h('textarea', { id: 'mc-bk-edit-purpose', name: 'purpose', required: true, minlength: 4, maxlength: 250, rows: 3 }, b.purpose || ''),
			C.h('p', { class: 'mc-form-row__hint' }, t('Briefly describe the business purpose. Required for audit purposes (Fahrtenbuch).')),
			C.h('p', { class: 'mc-form-row__error', role: 'alert' }),
		]));
		form.appendChild(C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: 'mc-bk-edit-passengers' }, t('Passengers (optional)')),
			C.h('textarea', { id: 'mc-bk-edit-passengers', name: 'passengers', maxlength: 500, rows: 2 }, b.passengers || ''),
			C.h('p', { class: 'mc-form-row__error', role: 'alert' }),
		]));
		const passRow = C.h('div', { class: 'mc-form-row', hidden: !isFleetActor });
		passRow.appendChild(C.h('label', { for: 'mc-bk-edit-pass-search' }, t('Passenger accounts (optional, max four)')));
		const mount = C.h('div', { class: 'mc-multi-id-picker-host', 'data-mc-bk-pass-picker': '1' });
		const taPass = C.h('textarea', {
			name: 'passengerUserIds',
			class: 'mc-sr-only',
			tabIndex: -1,
			'aria-hidden': 'true',
			'aria-describedby': 'mc-bk-edit-pass-hint',
		}, Array.isArray(b.passenger_user_ids) ? b.passenger_user_ids.join('\n') : '');
		passRow.appendChild(mount);
		passRow.appendChild(taPass);
		passRow.appendChild(C.h('p', { id: 'mc-bk-edit-pass-hint', class: 'mc-form-row__hint' }, t('Search by name to link up to four Nextcloud user IDs. To remove all linked passengers, clear every chip and save.')));
		passRow.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		form.appendChild(passRow);

		await ensureStationsCatalog();
		const activeStations = stationsCatalog.filter((s) => s.isActive !== false && s.isActive !== 0);
		if (activeStations.length > 0) {
			const fs = C.h('fieldset', { class: 'mc-fieldset' });
			fs.appendChild(C.h('legend', null, t('Stations (optional)')));
			fs.appendChild(C.h('p', { class: 'mc-form-row__hint' }, t('Pick different sites only when your organisation uses depots and allows one-way bookings between them.')));
			const sg = C.h('div', { class: 'mc-grid-2' });
			const puSel = C.h('select', { id: 'mc-bk-edit-pickup-st', name: 'pickupStationId', 'aria-describedby': 'mc-bk-edit-stations-hint' });
			puSel.appendChild(C.h('option', { value: '' }, t('Use vehicle default')));
			activeStations.forEach((s) => {
				puSel.appendChild(C.h('option', { value: String(s.id) }, String(s.code || '') + ' · ' + String(s.name || '')));
			});
			if (b.pickup_station_id != null && String(b.pickup_station_id) !== '') {
				puSel.value = String(b.pickup_station_id);
			}
			const retSel = C.h('select', { id: 'mc-bk-edit-return-st', name: 'returnStationId', 'aria-describedby': 'mc-bk-edit-stations-hint' });
			retSel.appendChild(C.h('option', { value: '' }, t('Same as pickup')));
			activeStations.forEach((s) => {
				retSel.appendChild(C.h('option', { value: String(s.id) }, String(s.code || '') + ' · ' + String(s.name || '')));
			});
			if (b.return_station_id != null && String(b.return_station_id) !== '') {
				retSel.value = String(b.return_station_id);
			}
			sg.appendChild(C.h('div', { class: 'mc-form-row' }, [
				C.h('label', { for: 'mc-bk-edit-pickup-st' }, t('Pickup station')),
				puSel,
				C.h('p', { class: 'mc-form-row__error', role: 'alert' }),
			]));
			sg.appendChild(C.h('div', { class: 'mc-form-row' }, [
				C.h('label', { for: 'mc-bk-edit-return-st' }, t('Return station')),
				retSel,
				C.h('p', { class: 'mc-form-row__error', role: 'alert' }),
			]));
			fs.appendChild(sg);
			fs.appendChild(C.h('p', { id: 'mc-bk-edit-stations-hint', class: 'mc-form-row__hint' }, t('Leaving both on default follows each vehicle’s configured depot.')));
			form.appendChild(fs);
		}
		if (isFleetActor && stationStrictMode) {
			form.appendChild(C.h('div', { class: 'mc-form-row' }, [
				C.h('label', { for: 'mc-bk-edit-cross' }, t('Cross-site booking justification')),
				C.h('textarea', { id: 'mc-bk-edit-cross', name: 'crossStationReason', maxlength: 2000, rows: 2 }, b.cross_station_reason || ''),
				C.h('p', { id: 'mc-bk-edit-cross-hint', class: 'mc-form-row__hint' }, t('Fleet managers: when strict station rules apply and this reservation uses a vehicle outside the driver’s home site, enter at least 10 characters.')),
				C.h('p', { class: 'mc-form-row__error', role: 'alert' }),
			]));
		}

		form.appendChild(C.h('div', { class: 'mc-form-actions' }, [
			C.h('button', { type: 'submit', class: 'button button-primary' }, t('Save changes')),
		]));
		section.appendChild(form);
		host.appendChild(section);

		if (isFleetActor) {
			try {
				await fillVehicleSelectForEdit(form.querySelector('#mc-bk-edit-vehicle'), b.vehicle_id);
				const UP = window.MobilityCheckUserPicker;
				if (UP && taPass) {
					const sync = UP.attachMultiIdPicker(mount, taPass, { kind: 'user', inputId: 'mc-bk-edit-pass-search' });
					sync.syncFromTextarea();
				}
			} catch (e) {
				M.reportError(e);
			}
		}

		form.addEventListener('submit', async (ev) => {
			ev.preventDefault();
			C.clearErrors(form);
			const raw = C.collectForm(form);
			let expKm = null;
			if (raw.expectedDistanceKm !== '' && raw.expectedDistanceKm !== undefined) {
				expKm = parseInt(raw.expectedDistanceKm, 10);
				if (Number.isNaN(expKm)) {
					C.applyFieldError(form, 'expectedDistanceKm', t('Enter a valid distance or leave the field empty.'));
					return;
				}
			}
			const payload = {
				startDatetime: fromDateTimeLocalInput(raw.startDatetime),
				endDatetime: fromDateTimeLocalInput(raw.endDatetime),
				purpose: (raw.purpose || '').trim(),
				destination: (raw.destination || '').trim() || null,
				costCentre: (raw.costCentre || '').trim() || null,
				expectedDistanceKm: expKm,
				passengers: (raw.passengers || '').trim() || null,
			};
			if (isFleetActor) {
				payload.vehicleId = parseInt(raw.vehicleId, 10);
				const uids = String(raw.passengerUserIds || '')
					.split(/[\s,;\n]+/)
					.map((s) => s.trim())
					.filter(Boolean)
					.slice(0, 4);
				payload.passengerUserIds = uids;
			}
			if (form.querySelector('#mc-bk-edit-pickup-st')) {
				payload.pickupStationId = raw.pickupStationId || '';
				payload.returnStationId = raw.returnStationId || '';
			}
			if (isFleetActor && stationStrictMode && form.querySelector('#mc-bk-edit-cross')) {
				payload.crossStationReason = (raw.crossStationReason || '').trim();
			}
			if (!payload.startDatetime || !payload.endDatetime) {
				C.applyFieldError(form, 'startDatetime', t('Please pick a date range.'));
				return;
			}
			C.lockForm(form, true);
			try {
				await API.put('/api/bookings/' + id(), payload);
				M.toast(t('Booking details saved.'), 'success');
				C.lockForm(form, false);
				load();
			} catch (e) {
				C.lockForm(form, false);
				if (e.code === 'BOOKING_CONFLICT') {
					C.applyFieldError(form, 'startDatetime', M.resolveError(e));
				} else if (e.code === 'NOT_ELIGIBLE') {
					C.applyFieldError(form, isFleetActor ? 'vehicleId' : 'purpose', M.resolveError(e));
				} else if (e.context && e.context.field) {
					C.applyFieldError(form, e.context.field, M.resolveError(e));
				} else {
					M.reportError(e);
				}
			}
		});
	}

	async function load() {
		const bid = id();
		if (!bid) return;
		const dl = document.getElementById('mc-bk-dl');
		C.setLoading(dl, true);
		try {
			await ensureStationsCatalog();
			const b = await API.get('/api/bookings/' + bid);
			document.querySelectorAll('[data-mc-bind="status-line"]').forEach((el) => { el.textContent = describeStatus(b.status); });
			dl.innerHTML = '';
			row(dl, t('Status'), C.statusBadge(b.status));
			row(dl, t('Vehicle handover'), handoverSummary(b));
			row(dl, t('Driver'), b.driver_user_id);
			const vehLabel = (b.vehicle_internal_name || ('#' + b.vehicle_id))
				+ (b.vehicle_licence_plate ? ' · ' + b.vehicle_licence_plate : '');
			row(dl, t('Vehicle'), C.h('a', { class: 'mc-button-link', href: C.detailUrl('vehicles', b.vehicle_id) }, vehLabel));
			if (b.vehicle_assignment_mode) {
				row(dl, t('Vehicle use type'), assignmentLabel(b.vehicle_assignment_mode));
			}
			if (b.vehicle_base_location) {
				row(dl, t('Default base location'), b.vehicle_base_location);
			}
			if (b.vehicle_odometer_km != null && Number(b.vehicle_odometer_km) >= 0) {
				row(dl, t('Vehicle odometer (last known)'), Number(b.vehicle_odometer_km).toLocaleString() + ' km');
			}
			row(dl, t('Start'), fmtDateTime(b.start_datetime));
			row(dl, t('End'), fmtDateTime(b.end_datetime));
			row(dl, t('Pickup station'), stationLabel(b.pickup_station_id) || '—');
			row(dl, t('Return station'), stationLabel(b.return_station_id) || '—');
			const crs = String(b.cross_station_reason || '').trim();
			if (crs !== '') {
				row(dl, t('Cross-site justification'), crs);
			}
			row(dl, t('Purpose'), b.purpose);
			if (b.passengers) {
				row(dl, t('Passengers'), b.passengers);
			}
			if (b.passenger_user_ids && b.passenger_user_ids.length) {
				row(dl, t('Passenger accounts'), b.passenger_user_ids.join(', '));
			}
			row(dl, t('Destination'), b.destination);
			row(dl, t('Cost centre'), b.cost_centre);
			if (b.expected_distance_km != null && b.expected_distance_km !== '') {
				row(dl, t('Expected distance (km)'), String(b.expected_distance_km));
			}
			if (b.approval_mode_snapshot) {
				row(dl, t('Approval path at booking time'), approvalSnapshotLabel(b.approval_mode_snapshot));
			}
			if (b.cancellation_reason) row(dl, t('Cancellation reason'), formatCancellationReason(b.cancellation_reason));

			renderWorkflow(b);
			renderProxyBanner(b);
			void renderEditSection(b);
			renderReturnInsights(b);
			renderActions(b);
			renderLogs(b.logs || {});
			updatePickupHint(b);
			loadApprovalTrail();
			M.polite(t('Booking loaded.'));
			C.setLoading(dl, false);
		} catch (e) {
			C.setLoading(dl, false);
			M.reportError(e);
		}
	}

	function renderActions(b) {
		const host = document.getElementById('mc-bk-actions');
		if (!host) return;
		host.innerHTML = '';
		const ctx = C.bootstrap();
		const isManager = ctx.root && ctx.root.getAttribute('data-mc-is-manager') === '1';
		const isAppAdmin = ctx.root && ctx.root.getAttribute('data-mc-is-app-admin') === '1';
		const isLineManager = ctx.root && ctx.root.getAttribute('data-mc-is-line-manager') === '1';
		const isAuditor = ctx.root && ctx.root.getAttribute('data-mc-is-auditor') === '1';
		const lmScopedReader = ctx.root && ctx.root.getAttribute('data-mc-line-manager-scoped-reader') === '1';
		const currentUserId = ctx.root && ctx.root.getAttribute('data-mc-current-user');
		const isFleetActor = isManager || isAppAdmin;
		const canHandover = (b.driver_user_id === currentUserId) || isFleetActor;

		// §4.5a §C — Fleet manager directly approves only `pending_fleet`.
		// Line manager approval lives on `pending_line_manager`; if a fleet
		// manager wants to skip the LM step they must use the dedicated
		// override action (separate button below) so the LM is notified.
		const canFleetApprove = isFleetActor && b.status === 'pending_fleet';
		const canLmApprove = isLineManager && b.status === 'pending_line_manager';
		const canOverrideLm = isFleetActor && b.status === 'pending_line_manager';
		if (canFleetApprove || canLmApprove) {
			host.appendChild(C.h('button', { type: 'button', class: 'button button-primary', onClick: () => approve() }, t('Approve')));
			host.appendChild(C.h('button', { type: 'button', class: 'button mc-danger-button', onClick: () => reject() }, t('Reject')));
		}
		if (canOverrideLm) {
			host.appendChild(C.h('button', { type: 'button', class: 'button', onClick: () => overrideLineManager(), title: t('Bypass the line manager — used when the line manager is unavailable. The line manager is notified.') }, t('Override line manager')));
			host.appendChild(C.h('button', { type: 'button', class: 'button', onClick: () => reassignLineManagerDialog(), title: t('Send this approval to a different line manager.') }, t('Reassign line manager')));
			host.appendChild(C.h('button', { type: 'button', class: 'button mc-danger-button', onClick: () => reject() }, t('Reject')));
		}
		if (b.status === 'approved') {
			if (canHandover) {
				host.appendChild(C.h('button', { type: 'button', class: 'button button-primary', onClick: () => openCheckoutDialog(b) }, t('Check out')));
			}
			if (b.driver_user_id === currentUserId || isFleetActor) {
				host.appendChild(C.h('button', { type: 'button', class: 'button', onClick: () => cancel() }, t('Cancel')));
			}
		}
		if (b.status === 'active') {
			if (canHandover) {
				host.appendChild(C.h('button', { type: 'button', class: 'button button-primary', onClick: () => openCheckinDialog(b) }, t('Check in')));
				host.appendChild(C.h('button', { type: 'button', class: 'button', onClick: () => openExtendDialog(b), title: t('Add more time to this trip — your fleet manager can configure the maximum.') }, t('Extend booking')));
			}
			if (b.driver_user_id === currentUserId || isFleetActor) {
				host.appendChild(C.h('button', { type: 'button', class: 'button', onClick: () => cancel() }, t('Cancel')));
			}
		}
		const readOnlyViewer = isAuditor || (isLineManager && lmScopedReader && !isFleetActor);
		if (readOnlyViewer && (b.status === 'approved' || b.status === 'active') && !canHandover) {
			host.appendChild(C.h('p', {
				class: 'mc-callout mc-callout--neutral mc-bk-readonly-hint',
				role: 'status',
			}, t('Pick-up and return are performed by the assigned driver or fleet staff. You have read-only access to this booking.')));
		}
		if (b.status === 'pending_fleet' || b.status === 'pending_line_manager') {
			if (!isFleetActor && !canLmApprove && !canOverrideLm && b.driver_user_id === currentUserId) {
				host.appendChild(C.h('button', { type: 'button', class: 'button', onClick: () => cancel() }, t('Cancel my booking')));
			}
		}
	}

	async function loadApprovalTrail() {
		const host = document.querySelector('[data-mc-bind="approvals-body"]');
		if (!host) return;
		try {
			const list = await API.get('/api/bookings/' + id() + '/approvals');
			host.innerHTML = '';
			if (!Array.isArray(list) || list.length === 0) {
				host.appendChild(C.h('p', { class: 'mc-empty' }, t('No approval decisions recorded yet.')));
				return;
			}
			const stepLabel = {
				line_manager: t('Line manager'),
				fleet_manager: t('Fleet manager'),
			};
			const decisionLabel = {
				approved: t('Approved'),
				rejected: t('Rejected'),
				fleet_override: t('Line manager step bypassed (fleet override)'),
			};
			C.renderTable(host, [
				{ key: 'decidedAt', label: t('When'), render: (r) => fmtDateTime(r.decidedAt) },
				{ key: 'step', label: t('Step'), render: (r) => stepLabel[r.step] || r.step },
				{ key: 'decision', label: t('Decision'), render: (r) => decisionLabel[r.decision] || r.decision },
				{ key: 'approverUserId', label: t('Decided by') },
				{ key: 'reason', label: t('Reason / note'), render: (r) => r.reason || '—' },
			], list, { ariaLabel: t('Approval trail'), emptyHeading: t('No approval history.') });
		} catch (e) {
			host.innerHTML = '';
			host.appendChild(C.h('p', { class: 'mc-empty mc-empty--error' }, t('Could not load approval history.')));
		}
	}

	async function updatePickupHint(b) {
		const section = document.getElementById('mc-bk-pickup-hint');
		const body = section && section.querySelector('[data-mc-bind="pickup-hint-body"]');
		if (!section || !body) return;
		// Show the hint card only when:
		//  - vehicle is pool/group AND
		//  - booking is approved (before check-out; §4.5 step 7 / §13.28)
		const isPickupRelevant = b.status === 'approved';
		const isShared = b.vehicle_assignment_mode === 'pool' || b.vehicle_assignment_mode === 'group';
		if (!isPickupRelevant || !isShared || !b.vehicle_id) {
			section.hidden = true;
			return;
		}
		section.hidden = false;
		const ssr = section.getAttribute('data-mc-pickup-ssr') === '1';
		const histCount = parseInt(section.getAttribute('data-mc-pickup-history-count') || '0', 10);
		if (ssr && histCount <= 1) {
			return;
		}
		body.innerHTML = '';
		body.appendChild(C.h('p', { class: 'mc-empty' }, t('Loading the latest pickup location…')));
		try {
			const data = await API.get('/api/vehicles/' + b.vehicle_id + '/last-return-info?limit=5');
			body.innerHTML = '';
			const baseLine = b.vehicle_base_location
				? C.h('p', { class: 'mc-hint__base' }, [C.h('strong', null, t('Default base location:')), ' ', b.vehicle_base_location])
				: null;
			if (baseLine) body.appendChild(baseLine);
			if (data && data.lastReturn && data.lastReturn.note) {
				body.appendChild(C.h('div', { class: 'mc-hint__primary', role: 'region', 'aria-labelledby': 'mc-bk-pickup-primary-h' }, [
					C.h('h3', { id: 'mc-bk-pickup-primary-h', class: 'mc-hint__primary-title' }, t('Most recent return note')),
					C.h('p', { class: 'mc-hint__primary-note' }, data.lastReturn.note),
					C.h('p', { class: 'mc-hint__primary-meta' }, t('Recorded {when}.', { when: fmtDateTime(data.lastReturn.recordedAt) })),
				]));
			} else if (data && data.hasPriorCheckin === false) {
				body.appendChild(C.h('div', { class: 'mc-callout mc-callout--success', role: 'status' }, [
					C.h('p', null, [C.h('strong', null, t('First trip')), ' — ', t('No previous check-in was recorded for this vehicle. It should be at its default stand unless your fleet manager says otherwise.')]),
				]));
			} else {
				body.appendChild(C.h('div', { class: 'mc-callout mc-callout--neutral', role: 'status' }, [
					C.h('p', null, t('A colleague checked the vehicle in before, but left no return location note. Use the default base location or ask your fleet manager.')),
				]));
			}
			if (data && Array.isArray(data.history) && data.history.length > 1) {
				const earlier = data.history.slice(1);
				if (earlier.length > 0) {
					const det = C.h('details', { class: 'mc-hint__history' });
					det.appendChild(C.h('summary', null, t('Earlier return notes ({count})', { count: String(earlier.length) })));
					const ul = C.h('ul', { class: 'mc-hint__history-list' });
					earlier.forEach((entry) => {
						ul.appendChild(C.h('li', null, [
							C.h('time', null, fmtDateTime(entry.recordedAt)),
							C.h('span', { class: 'mc-hint__history-note' }, ' — ' + entry.note),
						]));
					});
					det.appendChild(ul);
					body.appendChild(det);
				}
			}
		} catch (e) {
			body.innerHTML = '';
			body.appendChild(C.h('p', { class: 'mc-empty mc-empty--error' }, t('Could not load the latest pickup location. The default base location still applies.')));
		}
	}

	function renderLogs(logs) {
		const host = document.getElementById('mc-bk-logs');
		if (!host) return;
		host.innerHTML = '';
		const rows = [];
		if (logs.checkout) rows.push({ event: t('Check-out'), ...logs.checkout });
		if (logs.checkin) rows.push({ event: t('Check-in'), ...logs.checkin });
		if (rows.length === 0) {
			host.appendChild(C.h('div', { class: 'mc-empty-state' }, [
				C.h('div', { class: 'mc-empty-state__icon', 'aria-hidden': 'true' }, '?'),
				C.h('div', { class: 'mc-empty-state__main' }, [
					C.h('h3', null, t('No check-out yet')),
					C.h('p', null, t('Once the driver picks up the vehicle the checkout log appears here.')),
				]),
			]));
			return;
		}
		const fuelLabel = Object.fromEntries(FUEL.map(([v, l]) => [v, t(l)]));
		C.renderTable(host, [
			{ key: 'event', label: t('Event') },
			{ key: 'recordedAt', label: t('Recorded at'), render: (r) => fmtDateTime(r.recordedAt) },
			{ key: 'recordedBy', label: t('By') },
			{ key: 'odometerKm', label: t('Odometer'), num: true, render: (r) => (r.odometerKm != null ? r.odometerKm.toLocaleString() + ' km' : '—') },
			{ key: 'fuelLevel', label: t('Fuel'), render: (r) => (r.fuelLevel && fuelLabel[r.fuelLevel] != null ? fuelLabel[r.fuelLevel] : '—') },
			{ key: 'pickupLocationNote', label: t('Pickup location note'), render: (r) => r.pickupLocationNote || '—' },
			{ key: 'returnLocationNote', label: t('Return location note'), render: (r) => r.returnLocationNote || '—' },
			{ key: 'conditionNotes', label: t('Condition notes'), render: (r) => r.conditionNotes || '—' },
		], rows, { ariaLabel: t('Check-out and check-in'), emptyHeading: t('No logs yet.') });
	}

	/**
	 * @param {object} opts
	 * @param {'checkout'|'checkin'} opts.mode
	 * @param {boolean} opts.requireReturnLocation
	 * @param {boolean} opts.requireBizAck
	 */
	function buildHandoverForm(opts) {
		const form = C.h('form', { class: 'mc-form' });
		const grid = C.h('div', { class: 'mc-grid-2' });
		const odo = C.h('div', { class: 'mc-form-row' });
		odo.appendChild(C.h('label', { for: 'mc-co-odo' }, [t('Odometer (km)'), C.h('span', { class: 'mc-required' }, '*')]));
		odo.appendChild(C.h('input', { id: 'mc-co-odo', name: 'odometerKm', type: 'number', required: true, min: 0, inputmode: 'numeric' }));
		odo.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		grid.appendChild(odo);
		const fuel = C.h('div', { class: 'mc-form-row' });
		fuel.appendChild(C.h('label', { for: 'mc-co-fuel' }, [t('Fuel level'), C.h('span', { class: 'mc-required' }, '*')]));
		fuel.appendChild(C.h('select', { id: 'mc-co-fuel', name: 'fuelLevel', required: true }, FUEL.map(([v, l]) => C.h('option', { value: v }, t(l)))));
		fuel.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		grid.appendChild(fuel);
		form.appendChild(grid);
		form.appendChild(C.h('fieldset', { class: 'mc-fieldset' }, [
			C.h('legend', null, t('Condition checklist — tick every zone that is undamaged')),
			C.h('ul', { class: 'mc-checklist' }, ZONES.map((z) => C.h('li', null, [
				C.h('input', { type: 'checkbox', id: 'mc-zone-' + z, name: 'zone_' + z, value: '1' }),
				C.h('label', { for: 'mc-zone-' + z }, zoneLabel(z)),
				C.h('span', { class: 'mc-checklist__hint' }, t('OK')),
			]))),
		]));
		const notes = C.h('div', { class: 'mc-form-row' });
		notes.appendChild(C.h('label', { for: 'mc-co-notes' }, t('Condition notes (optional)')));
		notes.appendChild(C.h('textarea', { id: 'mc-co-notes', name: 'conditionNotes', rows: 3, maxlength: 2000 }));
		notes.appendChild(C.h('p', { class: 'mc-form-row__hint' }, t('Describe scratches, dents or interior issues. Keep this separate from where the vehicle is parked.')));
		notes.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		form.appendChild(notes);

		if (opts.mode === 'checkout') {
			const pickup = C.h('div', { class: 'mc-form-row' });
			const pid = 'mc-co-pickup-loc';
			pickup.appendChild(C.h('label', { for: pid }, t('Where you collected the vehicle (optional)')));
			pickup.appendChild(C.h('textarea', {
				id: pid, name: 'pickupLocationNote', rows: 2, maxlength: 500,
				'aria-describedby': pid + '-hint',
			}));
			pickup.appendChild(C.h('p', { id: pid + '-hint', class: 'mc-form-row__hint' }, t('If the car was not at the default base location, say where you found it (for example car park level and bay).')));
			pickup.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
			form.appendChild(pickup);
		}

		if (opts.mode === 'checkin') {
			const ret = C.h('div', { class: 'mc-form-row' });
			const rid = 'mc-ci-return-loc';
			const req = !!opts.requireReturnLocation;
			ret.appendChild(C.h('label', { for: rid }, [
				t('Where you left the vehicle for the next driver'),
				req ? C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*') : C.h('span', { class: 'mc-optional-badge' }, t('optional')),
			]));
			ret.appendChild(C.h('textarea', {
				id: rid,
				name: 'return_location_note',
				rows: 3,
				maxlength: 500,
				required: req,
				minlength: req ? 3 : 0,
				'aria-describedby': rid + '-hint',
			}));
			ret.appendChild(C.h('p', { id: rid + '-hint', class: 'mc-form-row__hint' },
				req
					? t('Required for pool and group vehicles: parking deck, bay number, charging spot or building name so colleagues can find the car.')
					: t('Optional for dedicated vehicles. Still useful if someone else might pick the car up next.')));
			ret.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
			form.appendChild(ret);
		}

		if (opts.mode === 'checkout' && opts.requireBizAck) {
			const biz = C.h('div', { class: 'mc-form-row mc-form-row--callout' });
			const bid = 'mc-co-biz-only';
			biz.appendChild(C.h('input', { type: 'checkbox', id: bid, name: 'confirmedBusinessOnly', value: '1' }));
			biz.appendChild(C.h('label', { for: bid }, [
				C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*'),
				' ',
				t('I confirm this trip is for business use only (no private driving with this pool or group vehicle).'),
			]));
			biz.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
			form.appendChild(biz);
		}

		return form;
	}

	function collectHandoverPayload(form, mode) {
		const zones = {};
		ZONES.forEach((z) => {
			const el = form.querySelector('[name="zone_' + z + '"]');
			zones[z] = !!(el && el.checked);
		});
		const raw = C.collectForm(form);
		const out = {
			odometerKm: parseInt(raw.odometerKm, 10),
			fuelLevel: raw.fuelLevel,
			conditionZonesOk: zones,
			conditionNotes: raw.conditionNotes || null,
		};
		if (mode === 'checkout') {
			out.pickupLocationNote = raw.pickupLocationNote && String(raw.pickupLocationNote).trim() ? String(raw.pickupLocationNote).trim() : null;
			const cb = form.querySelector('[name="confirmedBusinessOnly"]');
			if (cb) {
				out.confirmedBusinessOnly = !!(cb && cb.checked);
			}
		}
		if (mode === 'checkin') {
			out.returnLocationNote = raw.return_location_note && String(raw.return_location_note).trim()
				? String(raw.return_location_note).trim() : null;
		}
		return out;
	}

	function openCheckoutDialog(booking) {
		const form = buildHandoverForm({
			mode: 'checkout',
			requireReturnLocation: false,
			requireBizAck: !!booking.checkout_requires_business_only_ack,
		});
		const odoIn = form.querySelector('[name="odometerKm"]');
		if (odoIn && booking.vehicle_odometer_km != null && Number(booking.vehicle_odometer_km) >= 0) {
			const v = Number(booking.vehicle_odometer_km);
			odoIn.setAttribute('min', String(v));
			odoIn.placeholder = String(v);
		}
		C.showDialog({
			title: t('Check out vehicle'),
			body: form,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Confirm check-out'), primary: true, closeOnClick: false,
					onClick: async () => {
						C.clearErrors(form);
						if (booking.checkout_requires_business_only_ack) {
							const cb = form.querySelector('[name="confirmedBusinessOnly"]');
							if (!cb || !cb.checked) {
								const row = cb && cb.closest('.mc-form-row');
								if (row) {
									row.classList.add('has-error');
									const errEl = row.querySelector('.mc-form-row__error');
									if (errEl) errEl.textContent = t('You must tick the business-use confirmation for this vehicle.');
								}
								return;
							}
						}
						C.lockForm(form, true);
						try {
							await API.post('/api/bookings/' + id() + '/checkout', collectHandoverPayload(form, 'checkout'));
							C.closeDialog();
							M.toast(t('Check-out recorded.'), 'success');
							load();
						} catch (e) {
							C.lockForm(form, false);
							if (e.context && e.context.field) C.applyFieldError(form, e.context.field, M.resolveError(e));
							else M.reportError(e);
						}
					},
				},
			],
		});
	}

	function openCheckinDialog(booking) {
		const needRet = requiresReturnLocationNote(booking.vehicle_assignment_mode);
		const form = buildHandoverForm({
			mode: 'checkin',
			requireReturnLocation: needRet,
			requireBizAck: false,
		});
		const checkoutKm = booking.logs && booking.logs.checkout && booking.logs.checkout.odometerKm != null
			? Number(booking.logs.checkout.odometerKm) : null;
		const odoIn = form.querySelector('[name="odometerKm"]');
		if (odoIn && checkoutKm != null && checkoutKm >= 0) {
			odoIn.setAttribute('min', String(checkoutKm));
			odoIn.placeholder = String(checkoutKm);
		}
		C.showDialog({
			title: t('Check in vehicle'),
			body: form,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Confirm check-in'), primary: true, closeOnClick: false,
					onClick: async () => {
						C.clearErrors(form);
						const ta = form.querySelector('[name="return_location_note"]');
						if (needRet && ta && String(ta.value || '').trim().length < 3) {
							C.applyFieldError(form, 'return_location_note', t('Please enter at least a few characters so the next driver can find the vehicle.'));
							return;
						}
						C.lockForm(form, true);
						try {
							await API.post('/api/bookings/' + id() + '/checkin', collectHandoverPayload(form, 'checkin'));
							C.closeDialog();
							M.toast(t('Check-in recorded. Trip complete.'), 'success');
							load();
						} catch (e) {
							C.lockForm(form, false);
							if (e.context && e.context.field) C.applyFieldError(form, e.context.field, M.resolveError(e));
							else M.reportError(e);
						}
					},
				},
			],
		});
	}

	async function approve() {
		try { await API.post('/api/bookings/' + id() + '/approve', {}); M.toast(t('Booking approved.'), 'success'); load(); } catch (e) { M.reportError(e); }
	}
	function openReasonDialog(opts) {
		const minLen = opts.minLength != null ? opts.minLength : (opts.required ? 3 : 0);
		const uid = 'mc-bk-rsn-' + Math.random().toString(36).slice(2, 9);
		const taId = uid + '-ta';
		const errId = uid + '-err';
		const hintId = uid + '-hint';
		const form = C.h('form', { class: 'mc-form' });
		const row = C.h('div', { class: 'mc-form-row' });
		row.appendChild(C.h('label', { for: taId }, [
			opts.label,
			opts.required ? C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*') : null,
		]));
		const descIds = opts.hint ? (hintId + ' ' + errId) : errId;
		const ta = C.h('textarea', {
			id: taId,
			name: 'reason',
			rows: 3,
			required: !!opts.required,
			minlength: minLen,
			maxlength: 2000,
			'aria-describedby': descIds,
		});
		row.appendChild(ta);
		if (opts.hint) {
			row.appendChild(C.h('p', { id: hintId, class: 'mc-form-row__hint' }, opts.hint));
		}
		row.appendChild(C.h('p', { id: errId, class: 'mc-form-row__error', role: 'alert' }));
		form.appendChild(row);
		C.showDialog({
			title: opts.title,
			body: form,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Submit'),
					primary: true,
					closeOnClick: false,
					onClick: async () => {
						C.clearErrors(form);
						const reason = (ta.value || '').trim();
						if (opts.required && reason.length < minLen) {
							C.applyFieldError(form, 'reason', opts.minLengthMessage || t('A reason is required.'));
							return;
						}
						C.lockForm(form, true);
						try {
							await opts.onSubmit(reason);
							C.closeDialog();
							if (opts.successToast) M.toast(opts.successToast[0], opts.successToast[1] || 'info');
							load();
						} catch (e) {
							C.lockForm(form, false);
							if (e.context && e.context.field) C.applyFieldError(form, e.context.field, M.resolveError(e));
							else M.reportError(e);
						}
					},
				},
			],
		});
	}

	async function reject() {
		openReasonDialog({
			title: t('Reject booking'),
			label: t('Reason for rejection (visible to the driver):'),
			hint: t('The driver sees this text together with the rejection.'),
			required: true,
			successToast: [t('Booking rejected.'), 'warning'],
			onSubmit: async (reason) => {
				await API.post('/api/bookings/' + id() + '/reject', { reason });
			},
		});
	}
	async function cancel() {
		const ok = await C.confirmDialog(t('Cancel this booking? This cannot be undone.'));
		if (!ok) return;
		openReasonDialog({
			title: t('Cancel booking'),
			label: t('Cancellation reason:'),
			hint: t('Everyone who can open this booking will see this reason.'),
			required: true,
			successToast: [t('Booking cancelled.'), 'info'],
			onSubmit: async (reason) => {
				await API.post('/api/bookings/' + id() + '/cancel', { reason });
			},
		});
	}

	function openExtendDialog(booking) {
		const form = C.h('form', { class: 'mc-form' });
		const currentEnd = new Date(booking.end_datetime.replace(' ', 'T') + 'Z');
		const proposed = new Date(currentEnd.getTime() + Math.min(60, bookingExtensionMaxMinutes) * 60 * 1000);
		function localIso(d) {
			const pad = (n) => String(n).padStart(2, '0');
			return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
		}
		const endHintId = 'mc-bk-ext-end-h';
		const endErrId = 'mc-bk-ext-end-e';
		const endRow = C.h('div', { class: 'mc-form-row' });
		endRow.appendChild(C.h('label', { for: 'mc-bk-extend-end' }, [t('New end time'), C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*')]));
		endRow.appendChild(C.h('input', {
			id: 'mc-bk-extend-end',
			name: 'newEndDatetime',
			type: 'datetime-local',
			required: true,
			value: localIso(proposed),
			'aria-describedby': endHintId + ' ' + endErrId,
		}));
		endRow.appendChild(C.h('p', { id: endHintId, class: 'mc-form-row__hint' },
			t('Original end: {when}. The fleet manager has configured the maximum extension to {minutes} minutes.', {
				when: fmtDateTime(booking.end_datetime),
				minutes: String(bookingExtensionMaxMinutes),
			})));
		endRow.appendChild(C.h('p', { id: endErrId, class: 'mc-form-row__error', role: 'alert' }));
		form.appendChild(endRow);

		const extRH = 'mc-bk-ext-r-h';
		const extRE = 'mc-bk-ext-r-e';
		const reasonRow = C.h('div', { class: 'mc-form-row' });
		reasonRow.appendChild(C.h('label', { for: 'mc-bk-extend-reason' }, [t('Reason for the extension'), C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*')]));
		reasonRow.appendChild(C.h('textarea', {
			id: 'mc-bk-extend-reason',
			name: 'reason',
			required: true,
			minlength: 3,
			maxlength: 500,
			rows: 3,
			'aria-describedby': extRH + ' ' + extRE,
		}));
		reasonRow.appendChild(C.h('p', { id: extRH, class: 'mc-form-row__hint' }, t('Be specific so the audit log is clear (for example: meeting overran, traffic delay).')));
		reasonRow.appendChild(C.h('p', { id: extRE, class: 'mc-form-row__error', role: 'alert' }));
		form.appendChild(reasonRow);

		C.showDialog({
			title: t('Extend booking'),
			body: form,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Confirm extension'),
					primary: true,
					closeOnClick: false,
					onClick: async () => {
						C.clearErrors(form);
						const payload = C.collectForm(form);
						const ne = payload.newEndDatetime;
						const reason = (payload.reason || '').trim();
						if (!ne) {
							C.applyFieldError(form, 'newEndDatetime', t('Please pick a new end time.'));
							return;
						}
						if (reason.length < 3) {
							C.applyFieldError(form, 'reason', t('Please give a short reason for the audit log.'));
							return;
						}
						C.lockForm(form, true);
						try {
							await API.post('/api/bookings/' + id() + '/extend', {
								newEndDatetime: new Date(ne).toISOString(),
								reason,
							});
							C.closeDialog();
							M.toast(t('Booking extended.'), 'success');
							load();
						} catch (e) {
							C.lockForm(form, false);
							if (e.context && e.context.field) C.applyFieldError(form, e.context.field, M.resolveError(e));
							else M.reportError(e);
						}
					},
				},
			],
		});
	}

	function overrideLineManager() {
		openReasonDialog({
			title: t('Override line manager'),
			label: t('Reason for bypassing the line manager (the line manager is notified):'),
			hint: t('The notified line manager receives this exact text — write clearly.'),
			required: true,
			minLength: 10,
			minLengthMessage: t('The bypass reason must be at least {count} characters. Please document why the line-manager step is being skipped.', { count: '10' }),
			successToast: [t('Approval moved past the line manager step.'), 'warning'],
			onSubmit: async (reason) => {
				await API.post('/api/bookings/' + id() + '/override-line-manager', { reason });
			},
		});
	}

	function reassignLineManagerDialog() {
		const UP = window.MobilityCheckUserPicker;
		const form = C.h('form', { class: 'mc-form' });
		const idRow = C.h('div', { class: 'mc-form-row' });
		const inputFor = UP ? 'mc-bk-reassign-lm-combo' : 'mc-bk-reassign-lm';
		idRow.appendChild(C.h('label', { for: inputFor }, [t('New line manager (Nextcloud user id)'), C.h('span', { class: 'mc-required' }, '*')]));
		const mount = C.h('div', { id: 'mc-bk-reassign-lm-mount' });
		idRow.appendChild(mount);
		idRow.appendChild(C.h('p', { class: 'mc-form-row__hint' }, t('Search by name or login. Only users with the line manager role are listed.')));
		idRow.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		form.appendChild(idRow);
		if (UP) {
			UP.attachUserCombobox(mount, {
				name: 'lineManagerUserId',
				idBase: 'mc-bk-reassign-lm',
				required: true,
				filterRow: (row) => (row.roles || []).indexOf('line_manager') !== -1,
			});
		} else {
			mount.appendChild(C.h('input', {
				id: 'mc-bk-reassign-lm',
				name: 'lineManagerUserId',
				type: 'text',
				autocomplete: 'off',
				required: true,
				maxlength: 64,
			}));
		}
		const reasonRow = C.h('div', { class: 'mc-form-row' });
		reasonRow.appendChild(C.h('label', { for: 'mc-bk-reassign-reason' }, [t('Reason'), C.h('span', { class: 'mc-required' }, '*')]));
		reasonRow.appendChild(C.h('textarea', {
			id: 'mc-bk-reassign-reason', name: 'reason', required: true, minlength: 3, maxlength: 500, rows: 3,
		}));
		reasonRow.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		form.appendChild(reasonRow);
		C.showDialog({
			title: t('Reassign line manager'),
			body: form,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Confirm reassignment'),
					primary: true,
					closeOnClick: false,
					onClick: async () => {
						C.clearErrors(form);
						const payload = C.collectForm(form);
						if (!(payload.lineManagerUserId || '').trim()) {
							C.applyFieldError(form, 'lineManagerUserId', t('Please pick a user.'));
							return;
						}
						if ((payload.reason || '').trim().length < 3) {
							C.applyFieldError(form, 'reason', t('Please give a short reason for the audit log.'));
							return;
						}
						C.lockForm(form, true);
						try {
							await API.post('/api/bookings/' + id() + '/reassign-line-manager', payload);
							C.closeDialog();
							M.toast(t('Approval reassigned.'), 'success');
							load();
						} catch (e) {
							C.lockForm(form, false);
							if (e.context && e.context.field) C.applyFieldError(form, e.context.field, M.resolveError(e));
							else M.reportError(e);
						}
					},
				},
			],
		});
	}

	async function init() {
		C.bootstrap();
		try {
			const boot = await API.get('/api/bootstrap');
			if (boot && boot.settings) {
				if (typeof boot.settings.approvalWorkflowEnabled !== 'undefined') {
					approvalWorkflowEnabled = !!boot.settings.approvalWorkflowEnabled;
				}
				if (typeof boot.settings.approvalMode === 'string') {
					approvalMode = boot.settings.approvalMode;
				}
				if (typeof boot.settings.bookingExtensionMaxMinutes === 'number') {
					bookingExtensionMaxMinutes = boot.settings.bookingExtensionMaxMinutes;
				}
				if (typeof boot.settings.approvalResetsOnTimeChange !== 'undefined') {
					approvalResetsOnTimeChange = !!boot.settings.approvalResetsOnTimeChange;
				}
				if (typeof boot.settings.stationStrictMode !== 'undefined') {
					stationStrictMode = !!boot.settings.stationStrictMode;
				}
			}
		} catch (_) {
			approvalWorkflowEnabled = true;
		}
		load();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
