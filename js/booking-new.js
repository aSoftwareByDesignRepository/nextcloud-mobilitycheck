/**
 * New booking page.
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const { fromDateTimeLocalInput } = window.MobilityCheckDates;
	const t = M.t;

	/** @type {Map<number, { base_location?: string|null }>} */
	let vehicleMetaById = new Map();

	/** @type {{ recommendedVehicleId?: number, allocationSummary?: string[] }|null} */
	let lastSearchAlloc = null;

	let bootCtx = {
		isFleetActor: false,
		stationStrictMode: false,
		canBookOnBehalf: false,
		intelligentAllocationEnabled: false,
		vehicleChoicePolicy: 'driver_may_choose',
		intelligentAllocationMode: 'suggest_only',
	};

	let vehicleChangeBound = false;
	let vehRefreshTimer = null;

	async function loadBootstrapContext() {
		try {
			const b = await API.get('/api/bootstrap');
			const u = b.user || {};
			const s = b.settings || {};
			bootCtx = {
				isFleetActor: !!(u.isManager || u.isFleetAdmin || u.isAppAdmin),
				canBookOnBehalf: !!(u.isManager || u.isAppAdmin),
				stationStrictMode: !!s.stationStrictMode,
				intelligentAllocationEnabled: !!s.intelligentAllocationEnabled,
				vehicleChoicePolicy: s.vehicleChoicePolicy || 'driver_may_choose',
				intelligentAllocationMode: s.intelligentAllocationMode || 'suggest_only',
			};
		} catch (_) {
			bootCtx = {
				isFleetActor: false,
				stationStrictMode: false,
				canBookOnBehalf: false,
				intelligentAllocationEnabled: false,
				vehicleChoicePolicy: 'driver_may_choose',
				intelligentAllocationMode: 'suggest_only',
			};
		}
	}

	function getBehalfUserId() {
		const h = document.getElementById('mc-bnew-behalf-val');
		return h ? String(h.value || '').trim() : '';
	}

	function scheduleVehicleRefresh() {
		window.clearTimeout(vehRefreshTimer);
		vehRefreshTimer = window.setTimeout(() => { void populateVehicles(); }, 320);
	}

	function bindVehicleChangeOnce() {
		const select = document.getElementById('mc-bnew-vehicle');
		if (!select || vehicleChangeBound) return;
		vehicleChangeBound = true;
		select.addEventListener('change', () => {
			updateStandHint();
			updateAllocHint();
		});
	}

	function initBehalfPicker() {
		const UP = window.MobilityCheckUserPicker;
		const section = document.getElementById('mc-bnew-proxy-section');
		const mount = document.getElementById('mc-bnew-behalf-mount');
		if (!bootCtx.canBookOnBehalf || !UP || !section || !mount) return;
		section.hidden = false;
		UP.attachUserCombobox(mount, {
			name: 'onBehalfOf',
			idBase: 'mc-bnew-behalf',
			allowClear: true,
			ariaDescribedBy: 'mc-bnew-behalf-hint',
			fetchUsers: async (q) => {
				const rows = API.asArray(await API.get('/api/drivers'));
				const tq = q.trim().toLowerCase();
				return rows
					.filter((r) => {
						const uid = String(r.user_id || '').toLowerCase();
						const dn = String(r.displayName || '').toLowerCase();
						return !tq || uid.includes(tq) || dn.includes(tq);
					})
					.map((r) => ({ id: r.user_id, displayName: r.displayName || r.user_id }));
			},
			onChange: () => { scheduleVehicleRefresh(); },
		});
		const hidden = document.getElementById('mc-bnew-behalf-val');
		if (hidden) hidden.addEventListener('input', () => { scheduleVehicleRefresh(); });
	}

	async function populateStationsUi() {
		const fs = document.getElementById('mc-bnew-stations-fieldset');
		const cross = document.getElementById('mc-bnew-cross-wrap');
		const pu = document.getElementById('mc-bnew-pickup-st');
		const ret = document.getElementById('mc-bnew-return-st');
		if (!fs || !pu || !ret) return;
		try {
			const rows = API.asArray(await API.get('/api/stations'));
			const active = rows.filter((s) => s.isActive !== false && s.isActive !== 0);
			if (active.length === 0) return;
			const fill = (sel) => {
				const first = sel.querySelector('option[value=""]');
				sel.innerHTML = '';
				if (first) sel.appendChild(first);
				active.forEach((s) => {
					const o = document.createElement('option');
					o.value = String(s.id);
					o.textContent = String(s.code || '') + ' · ' + String(s.name || '');
					sel.appendChild(o);
				});
			};
			fill(pu);
			fill(ret);
			fs.hidden = false;
			if (cross && bootCtx.isFleetActor && bootCtx.stationStrictMode) {
				cross.hidden = false;
			}
		} catch (_) {
			/* Stations are optional */
		}
	}

	function updateStandHint() {
		const select = document.getElementById('mc-bnew-vehicle');
		const stand = document.getElementById('mc-bnew-stand');
		if (!select || !stand) return;
		const vid = parseInt(select.value, 10);
		if (!vid) {
			stand.hidden = true;
			stand.textContent = '';
			return;
		}
		const meta = vehicleMetaById.get(vid);
		const loc = meta && meta.base_location ? String(meta.base_location).trim() : '';
		if (loc) {
			stand.textContent = t('Default stand for this vehicle: {location}', { location: loc });
		} else {
			stand.textContent = t('No default stand is on file for this vehicle. After approval, open the booking page — pool and group cars show where the last driver parked (return note) and the fleet default when set.');
		}
		stand.hidden = false;
	}

	function updateAllocHint() {
		const el = document.getElementById('mc-bnew-alloc-hint');
		if (!el) return;
		if (!bootCtx.intelligentAllocationEnabled) {
			el.hidden = true;
			el.textContent = '';
			return;
		}
		if (!bootCtx.intelligentAllocationEnabled || !lastSearchAlloc) {
			el.hidden = true;
			el.textContent = '';
			return;
		}
		el.hidden = false;
		const pol = bootCtx.vehicleChoicePolicy || 'driver_may_choose';
		let msg = '';
		if (pol === 'auto_assign_no_choice') {
			msg = t('You do not pick a specific unit: the best eligible vehicle is assigned for this slot when intelligent allocation is on.');
		} else if (pol === 'manager_assigns') {
			msg = t('Your organisation assigns concrete vehicles after booking. Pick any eligible vehicle here; the fleet may move you to another car before pickup.');
		} else {
			msg = t('Based on lease utilisation and fleet balance, the highlighted vehicle is pre-selected. You may pick another eligible vehicle.');
		}
		const rid = lastSearchAlloc.recommendedVehicleId;
		const select = document.getElementById('mc-bnew-vehicle');
		if (rid != null && select && String(select.value) === String(rid)) {
			const reasons = (lastSearchAlloc.allocationSummary || []).filter(Boolean).join(', ');
			if (reasons) {
				msg += ' ' + t('Signals: {reasons}').replace('{reasons}', reasons);
			}
		}
		el.textContent = msg;
	}

	async function populateVehicles() {
		const select = document.getElementById('mc-bnew-vehicle');
		if (!select) return;
		const prevVal = select.value;
		select.innerHTML = '';
		vehicleMetaById = new Map();
		lastSearchAlloc = null;
		try {
			const behalf = getBehalfUserId();
			const useSearch = bootCtx.canBookOnBehalf && behalf !== '';
			const startEl = document.getElementById('mc-bnew-start');
			const endEl = document.getElementById('mc-bnew-end');
			const fromWire = startEl ? fromDateTimeLocalInput(startEl.value) : '';
			const toWire = endEl ? fromDateTimeLocalInput(endEl.value) : '';

			let rows = [];
			if (useSearch) {
				if (!fromWire || !toWire) {
					const opt = document.createElement('option');
					opt.value = '';
					opt.textContent = t('Pick start and end times to load vehicles for the selected driver.');
					opt.disabled = true;
					opt.selected = true;
					select.appendChild(opt);
					updateStandHint();
					updateAllocHint();
					return;
				}
				const res = await API.post('/api/vehicles/search', {
					from: fromWire,
					to: toWire,
					driverUserId: behalf,
					requirements: {},
				});
				rows = API.asArray(res.matches);
				lastSearchAlloc = {
					recommendedVehicleId: res.recommendedVehicleId,
					allocationSummary: API.asArray(res.allocationSummary),
				};
			} else if (bootCtx.intelligentAllocationEnabled && fromWire && toWire) {
				const res = await API.post('/api/vehicles/search', {
					from: fromWire,
					to: toWire,
					requirements: {},
				});
				rows = API.asArray(res.matches);
				lastSearchAlloc = {
					recommendedVehicleId: res.recommendedVehicleId,
					allocationSummary: API.asArray(res.allocationSummary),
				};
			} else {
				rows = API.asArray(await API.get('/api/vehicles', { activeOnly: 1, status: 'available' }));
			}

			if (rows.length === 0) {
				const opt = document.createElement('option');
				opt.value = '';
				opt.textContent = useSearch
					? t('No vehicles match this driver and time window.')
					: (bootCtx.intelligentAllocationEnabled && fromWire && toWire
						? t('No vehicles match this time window.')
						: t('No available vehicles right now.'));
				opt.disabled = true;
				opt.selected = true;
				select.appendChild(opt);
				updateStandHint();
				updateAllocHint();
				return;
			}
			const recId = bootCtx.intelligentAllocationEnabled && lastSearchAlloc ? lastSearchAlloc.recommendedVehicleId : null;
			rows.forEach((v) => {
				const opt = document.createElement('option');
				opt.value = String(v.id);
				let label = v.internal_name + ' · ' + v.licence_plate + ' (' + v.required_licence_class + ')';
				if (recId != null && Number(v.id) === Number(recId)) {
					label += ' — ' + t('Recommended');
				}
				opt.textContent = label;
				select.appendChild(opt);
				vehicleMetaById.set(v.id, { base_location: v.base_location });
			});
			if (recId != null && [...select.options].some((o) => o.value === String(recId) && !o.disabled)) {
				select.value = String(recId);
			} else if (prevVal && [...select.options].some((o) => o.value === prevVal && !o.disabled)) {
				select.value = prevVal;
			}
			updateStandHint();
			updateAllocHint();
		} catch (e) {
			M.reportError(e);
		}
	}

	async function submit(ev) {
		ev.preventDefault();
		const form = ev.target;
		C.clearErrors(form);
		const raw = C.collectForm(form);
		const payload = {
			vehicleId: parseInt(raw.vehicleId, 10),
			purpose: raw.purpose,
			destination: raw.destination || null,
			costCentre: raw.costCentre || null,
			expectedDistanceKm: raw.expectedDistanceKm,
			startDatetime: fromDateTimeLocalInput(raw.startDatetime),
			endDatetime: fromDateTimeLocalInput(raw.endDatetime),
		};
		const behalf = String(raw.onBehalfOf || '').trim();
		if (behalf !== '') payload.onBehalfOf = behalf;
		const ps = raw.pickupStationId ? parseInt(raw.pickupStationId, 10) : 0;
		const rs = raw.returnStationId ? parseInt(raw.returnStationId, 10) : 0;
		if (ps > 0) payload.pickupStationId = ps;
		if (rs > 0) payload.returnStationId = rs;
		const cross = (raw.crossStationReason || '').trim();
		if (cross !== '') payload.crossStationReason = cross;
		if (!payload.vehicleId) {
			C.applyFieldError(form, 'vehicleId', t('Please select a vehicle.'));
			return;
		}
		C.lockForm(form, true);
		try {
			const booking = await API.post('/api/bookings', payload);
			M.toast(t('Booking submitted.'), 'success');
			const url = C.detailUrl('bookings', booking.id);
			window.setTimeout(() => { window.location.href = url; }, 250);
		} catch (e) {
			C.lockForm(form, false);
			if (e.code === 'BOOKING_CONFLICT') {
				C.applyFieldError(form, 'startDatetime', M.resolveError(e));
			} else if (e.code === 'NOT_ELIGIBLE') {
				C.applyFieldError(form, 'vehicleId', M.resolveError(e));
			} else if (e.context && e.context.field) {
				C.applyFieldError(form, e.context.field, M.resolveError(e));
			} else {
				M.reportError(e);
			}
		}
	}

	async function init() {
		C.bootstrap();
		await loadBootstrapContext();
		initBehalfPicker();
		bindVehicleChangeOnce();
		document.getElementById('mc-bnew-start')?.addEventListener('input', scheduleVehicleRefresh);
		document.getElementById('mc-bnew-end')?.addEventListener('input', scheduleVehicleRefresh);
		await populateVehicles();
		await populateStationsUi();
		const want = new URLSearchParams(window.location.search).get('vehicleId');
		if (want) {
			const sel = document.getElementById('mc-bnew-vehicle');
			if (sel && [...sel.options].some((o) => o.value === want && !o.disabled)) {
				sel.value = want;
				updateStandHint();
				updateAllocHint();
			}
		}
		document.getElementById('mc-bnew-form')?.addEventListener('submit', submit);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { void init(); });
	else void init();
})();
