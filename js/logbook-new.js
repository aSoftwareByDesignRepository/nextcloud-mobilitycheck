/**
 * New Fahrtenbuch (logbook) entry — driver flow for dedicated, logbook-taxed
 * vehicles. The server is the authority for eligibility; the UI only filters
 * the vehicle dropdown to vehicles the user is currently assigned to (the
 * server still rejects unauthorised submissions).
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const t = M.t;

	function currentUserId() {
		const r = document.getElementById('app-content');
		return r && r.getAttribute('data-mc-current-user') || '';
	}

	async function populateVehicles() {
		const sel = document.getElementById('mc-lbn-vehicle');
		if (!sel) return;
		sel.innerHTML = '';
		try {
			// Pull every active vehicle, then keep only ones with a current
			// dedicated + logbook assignment for the signed-in user.
			const me = currentUserId();
			const [vehicles, assignments] = await Promise.all([
				API.get('/api/vehicles', { activeOnly: 1 }),
				// no list-all endpoint for assignments → ask per vehicle below
				Promise.resolve(null),
			]);
			const eligible = [];
			for (const v of (vehicles || [])) {
				try {
					const rows = await API.get('/api/vehicle-assignments', { vehicleId: v.id });
					const today = new Date().toISOString().slice(0, 10);
					const active = (rows || []).find((a) =>
						a.assignment_mode === 'dedicated'
						&& a.tax_treatment === 'logbook'
						&& a.assigned_user_id === me
						&& a.valid_from <= today
						&& (!a.valid_until || a.valid_until >= today));
					if (active) eligible.push(v);
				} catch (_) { /* fall through */ }
			}
			if (eligible.length === 0) {
				const opt = document.createElement('option');
				opt.value = '';
				opt.textContent = t('No eligible vehicle. Manual entries are only allowed for vehicles assigned to you under the Fahrtenbuch method.');
				opt.disabled = true;
				opt.selected = true;
				sel.appendChild(opt);
				return;
			}
			eligible.forEach((v) => {
				const opt = document.createElement('option');
				opt.value = String(v.id);
				opt.textContent = (v.internal_name || ('#' + v.id)) + (v.licence_plate ? ' · ' + v.licence_plate : '');
				sel.appendChild(opt);
			});
		} catch (e) { M.reportError(e); }
	}

	function updateDistanceHint() {
		const start = parseInt(document.getElementById('mc-lbn-okm').value, 10);
		const end = parseInt(document.getElementById('mc-lbn-ekm').value, 10);
		const hint = document.getElementById('mc-lbn-distance-hint');
		if (!hint) return;
		if (Number.isFinite(start) && Number.isFinite(end) && end >= start) {
			hint.textContent = t('Distance: {km} km', { km: (end - start) });
		} else if (Number.isFinite(start) && Number.isFinite(end) && end < start) {
			hint.textContent = t('End odometer must be ≥ start odometer.');
		} else {
			hint.textContent = '';
		}
	}

	async function submit(ev) {
		ev.preventDefault();
		const form = ev.target;
		C.clearErrors(form);
		const raw = C.collectForm(form);
		const payload = {
			vehicleId: parseInt(raw.vehicleId, 10),
			tripType: raw.tripType,
			tripDate: raw.tripDate,
			departureTime: raw.departureTime || null,
			arrivalTime: raw.arrivalTime || null,
			startAddress: (raw.startAddress || '').trim(),
			endAddress: (raw.endAddress || '').trim(),
			odometerStartKm: parseInt(raw.odometerStartKm, 10),
			odometerEndKm: parseInt(raw.odometerEndKm, 10),
			purpose: (raw.purpose || '').trim(),
			clientOrContact: (raw.clientOrContact || '').trim() || null,
			projectReference: (raw.projectReference || '').trim() || null,
			isRoundTrip: !!raw.isRoundTrip,
		};
		if (!payload.vehicleId) { C.applyFieldError(form, 'vehicleId', t('Please choose a vehicle.')); return; }
		if (!payload.tripDate) { C.applyFieldError(form, 'tripDate', t('Please provide a trip date.')); return; }
		if (!Number.isFinite(payload.odometerStartKm)) { C.applyFieldError(form, 'odometerStartKm', t('Please enter the start odometer.')); return; }
		if (!Number.isFinite(payload.odometerEndKm)) { C.applyFieldError(form, 'odometerEndKm', t('Please enter the end odometer.')); return; }
		if (payload.odometerEndKm < payload.odometerStartKm) { C.applyFieldError(form, 'odometerEndKm', t('End odometer must be ≥ start odometer.')); return; }
		if (!payload.purpose || payload.purpose.length < 4) { C.applyFieldError(form, 'purpose', t('Please describe the trip purpose.')); return; }
		C.lockForm(form, true);
		try {
			const entry = await API.post('/api/logbook', payload);
			M.toast(t('Draft saved. Review the entry and confirm to lock it.'), 'success');
			const url = C.detailUrl('logbook', entry.id);
			window.setTimeout(() => { window.location.href = url; }, 250);
		} catch (e) {
			C.lockForm(form, false);
			if (e.context && e.context.field) {
				C.applyFieldError(form, e.context.field, M.resolveError(e));
			} else {
				M.reportError(e);
			}
		}
	}

	async function init() {
		C.bootstrap();
		await populateVehicles();
		const want = new URLSearchParams(window.location.search).get('vehicleId');
		if (want) {
			const sel = document.getElementById('mc-lbn-vehicle');
			if (sel && [...sel.options].some((o) => o.value === want)) {
				sel.value = want;
			}
		}
		document.getElementById('mc-lbn-okm')?.addEventListener('input', updateDistanceHint);
		document.getElementById('mc-lbn-ekm')?.addEventListener('input', updateDistanceHint);
		document.getElementById('mc-lbn-form')?.addEventListener('submit', submit);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { void init(); });
	else void init();
})();
