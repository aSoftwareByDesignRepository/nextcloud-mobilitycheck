/**
 * Tax benefit preview (§A9 / §A10) — 1 %-Regelung monthly estimate.
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const Money = window.MobilityCheckMoney;
	const t = M.t;

	function monthDefault() {
		const d = new Date();
		const y = d.getFullYear();
		const m = String(d.getMonth() + 1).padStart(2, '0');
		return y + '-' + m;
	}

	async function loadVehicles() {
		const sel = document.getElementById('mc-tax-vehicle');
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

	function payrollCsvUrl(vehicleId, yearMonth) {
		const base = API.appBase;
		return base + '/api/tax-benefit/payroll-export?vehicleId=' + encodeURIComponent(String(vehicleId))
			+ '&yearMonth=' + encodeURIComponent(yearMonth);
	}

	function renderResult(data) {
		const host = document.getElementById('mc-tax-result');
		const csv = document.getElementById('mc-tax-csv');
		if (!host) return;
		host.hidden = false;
		host.className = 'mc-callout';
		host.innerHTML = '';

		if (!data.assignment) {
			host.classList.add('mc-callout--warning');
			host.appendChild(C.h('p', { role: 'status' }, t('No vehicle assignment on the first day of this month.')));
			if (csv) csv.hidden = true;
			return;
		}
		if (!data.appliesOnePercentRule) {
			host.classList.add('mc-callout--warning');
			host.appendChild(C.h('p', { role: 'status' }, t('Tax method is not the 1 %% rule for this month — no list-price benefit is calculated here.')));
			if (csv) csv.hidden = true;
			return;
		}
		if (data.listPriceMissing) {
			host.classList.add('mc-callout--critical');
			host.appendChild(C.h('p', { role: 'alert' }, t('List price is missing — the monthly benefit is shown as zero.')));
		} else {
			host.classList.add('mc-callout--success');
		}

		const dl = C.h('dl', { class: 'mc-dl' });
		const add = (dt, dd) => {
			dl.appendChild(C.h('dt', null, dt));
			dl.appendChild(C.h('dd', null, dd));
		};
		add(t('1 % of list price'), Money.format(data.onePercentMinor));
		add(t('0.03 % × {km} km (commute)', { km: String(data.commuteKmOneWay) }), Money.format(data.commuteSurchargeMinor));
		add(t('Total'), Money.format(data.totalMonthlyBenefitMinor));
		add(t('Commute distance (km, one way)'), String(data.commuteKmOneWay));
		host.appendChild(dl);

		if (csv) {
			csv.href = payrollCsvUrl(data.vehicleId, data.yearMonth);
			csv.hidden = false;
		}
	}

	async function submit(ev) {
		ev.preventDefault();
		const form = ev.target;
		C.clearErrors(form);
		const raw = C.collectForm(form);
		const vehicleId = parseInt(raw.vehicleId, 10);
		const yearMonth = (raw.yearMonth || '').trim();
		if (!vehicleId) {
			C.applyFieldError(form, 'vehicleId', t('Please choose a vehicle.'));
			return;
		}
		if (!yearMonth || yearMonth.length !== 7) {
			C.applyFieldError(form, 'yearMonth', t('Please choose an accounting month.'));
			return;
		}
		C.lockForm(form, true);
		try {
			const data = await API.get('/api/tax-benefit/monthly', { vehicleId, yearMonth });
			C.lockForm(form, false);
			renderResult(data);
		} catch (e) {
			C.lockForm(form, false);
			M.reportError(e);
		}
	}

	async function init() {
		C.bootstrap();
		const month = document.getElementById('mc-tax-month');
		if (month && !month.value) month.value = monthDefault();
		await loadVehicles();
		document.getElementById('mc-tax-form')?.addEventListener('submit', submit);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { void init(); });
	else void init();
})();
