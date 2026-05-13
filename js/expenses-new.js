/**
 * New reimbursement claim form (Appendix A4).
 *
 * The server is the authority on the applicable rate; the UI shows a live
 * preview ({@link previewAmount}) using the active rate catalogue. Distance
 * and rate are recomputed server-side at submit and again at approval.
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const Money = window.MobilityCheckMoney;
	const t = M.t;

	let pvCache = [];
	let rates = [];

	async function loadPrivateVehicles() {
		const sel = document.getElementById('mc-exn-pv');
		if (!sel) return;
		sel.innerHTML = '';
		try {
			const rows = await API.get('/api/private-vehicles');
			pvCache = (rows || []).filter((r) => r.is_active);
			if (pvCache.length === 0) {
				const opt = document.createElement('option');
				opt.value = '';
				opt.textContent = t('No private vehicles registered. Add one first on the Expenses page.');
				opt.disabled = true;
				opt.selected = true;
				sel.appendChild(opt);
				return;
			}
			pvCache.forEach((v) => {
				const opt = document.createElement('option');
				opt.value = String(v.id);
				opt.textContent = v.make + ' ' + v.model + ' · ' + v.licence_plate;
				sel.appendChild(opt);
			});
		} catch (e) { M.reportError(e); }
	}

	async function loadRates() {
		try {
			rates = await API.get('/api/reimbursement-rates') || [];
		} catch (_) { /* preview is best-effort only */ }
	}

	function mapEngineToVehicleType(engine) {
		return engine === 'electric' ? 'electric' : 'car';
	}

	function previewAmount() {
		const hint = document.getElementById('mc-exn-preview');
		if (!hint) return;
		const pvId = parseInt(document.getElementById('mc-exn-pv').value, 10);
		const jc = (document.getElementById('mc-exn-jc').value || 'DE').toUpperCase();
		const date = document.getElementById('mc-exn-date').value;
		const dist = parseInt(document.getElementById('mc-exn-dist').value, 10);
		if (!pvId || !date || !Number.isFinite(dist) || dist <= 0) {
			hint.textContent = '';
			return;
		}
		const pv = pvCache.find((v) => v.id === pvId);
		if (!pv) { hint.textContent = ''; return; }
		const vt = mapEngineToVehicleType(pv.engine_type);
		const match = rates.find((r) =>
			r.jurisdiction_code === jc
			&& r.vehicle_type === vt
			&& r.valid_from <= date
			&& (!r.valid_until || r.valid_until >= date)
		);
		if (!match) {
			hint.textContent = t('No rate configured for {jc}/{vt} on {date}. Submission will fail until a rate is added.', { jc, vt, date });
			return;
		}
		const amount = match.rate_per_km_minor * dist;
		hint.textContent = t('Estimated: {rate}/km × {km} km = {total} (server recalculates on submit).', {
			rate: Money.format(match.rate_per_km_minor),
			km: dist,
			total: Money.format(amount),
		});
	}

	async function submit(ev) {
		ev.preventDefault();
		const form = ev.target;
		C.clearErrors(form);
		const raw = C.collectForm(form);
		const payload = {
			privateVehicleId: parseInt(raw.privateVehicleId, 10),
			jurisdictionCode: (raw.jurisdictionCode || 'DE').toUpperCase(),
			tripDate: raw.tripDate,
			departureTime: raw.departureTime || null,
			arrivalTime: raw.arrivalTime || null,
			startAddress: (raw.startAddress || '').trim(),
			endAddress: (raw.endAddress || '').trim(),
			distanceKm: parseInt(raw.distanceKm, 10),
			purpose: (raw.purpose || '').trim(),
			clientOrContact: (raw.clientOrContact || '').trim() || null,
			projectReference: (raw.projectReference || '').trim() || null,
			passengers: (raw.passengers || '').trim() || null,
		};
		if (!payload.privateVehicleId) { C.applyFieldError(form, 'privateVehicleId', t('Please choose a private vehicle.')); return; }
		if (!payload.tripDate) { C.applyFieldError(form, 'tripDate', t('Please provide a trip date.')); return; }
		if (!Number.isFinite(payload.distanceKm) || payload.distanceKm <= 0) { C.applyFieldError(form, 'distanceKm', t('Please enter a positive distance in kilometres.')); return; }
		if (!payload.startAddress || !payload.endAddress) { C.applyFieldError(form, 'startAddress', t('Please enter the start and end address.')); return; }
		if (!payload.purpose || payload.purpose.length < 4) { C.applyFieldError(form, 'purpose', t('Please describe the purpose.')); return; }
		C.lockForm(form, true);
		try {
			await API.post('/api/reimbursement-claims', payload);
			M.toast(t('Draft claim created.'), 'success');
			window.setTimeout(() => { window.location.href = (window.MobilityCheckComponents.bootstrap().urls.expenses) || '#'; }, 250);
		} catch (e) {
			C.lockForm(form, false);
			if (e.context && e.context.field) {
				C.applyFieldError(form, e.context.field, M.resolveError(e));
			} else {
				M.reportError(e);
			}
		}
	}

	function init() {
		C.bootstrap();
		Promise.all([loadPrivateVehicles(), loadRates()]).then(previewAmount);
		['mc-exn-pv', 'mc-exn-jc', 'mc-exn-date', 'mc-exn-dist'].forEach((id) => {
			document.getElementById(id)?.addEventListener('input', previewAmount);
			document.getElementById(id)?.addEventListener('change', previewAmount);
		});
		document.getElementById('mc-exn-form')?.addEventListener('submit', submit);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
