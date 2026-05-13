/**
 * Reimbursement page (Appendix A4).
 *
 *  - Drivers: their claims + private vehicle registration.
 *  - Managers/admins: queue with approve/reject/mark-paid actions.
 *
 * All sensitive transitions go through the server (which enforces role
 * checks); the UI surfaces only the actions the user is allowed to attempt.
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const D = window.MobilityCheckDates;
	const Money = window.MobilityCheckMoney;
	const t = M.t;

	function isManager() {
		const r = document.getElementById('app-content');
		return r && r.getAttribute('data-mc-is-manager') === '1';
	}
	function isDriver() {
		const r = document.getElementById('app-content');
		return r && r.getAttribute('data-mc-is-driver') === '1';
	}
	function currentUserId() {
		const r = document.getElementById('app-content');
		return r && r.getAttribute('data-mc-current-user') || '';
	}

	const filters = { status: '', driverUserId: '' };

	async function loadDriverFilterOptions() {
		const el = document.getElementById('mc-ex-driver');
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

	function claimStatusBadge(s) {
		const map = {
			draft: { st: 'pending_approval', label: t('Draft') },
			submitted: { st: 'pending_approval', label: t('Submitted') },
			approved: { st: 'approved', label: t('Approved') },
			rejected: { st: 'rejected', label: t('Rejected') },
			paid: { st: 'completed', label: t('Paid') },
		};
		const v = map[s] || { st: s, label: s };
		return C.h('span', { class: 'mc-status-badge', 'data-status': v.st, 'aria-label': t('Status') + ': ' + v.label }, v.label);
	}

	function rowActions(r) {
		const wrap = C.h('div', { class: 'mc-form-actions' });
		const me = currentUserId();
		if (r.status === 'draft' && r.driver_user_id === me) {
			wrap.appendChild(C.h('button', { type: 'button', class: 'button', onClick: () => submitClaim(r.id) }, t('Submit')));
		}
		if (r.status === 'submitted' && isManager()) {
			wrap.appendChild(C.h('button', { type: 'button', class: 'button button-primary', onClick: () => openApprove(r) }, t('Approve')));
			wrap.appendChild(C.h('button', { type: 'button', class: 'button', onClick: () => openReject(r) }, t('Reject')));
		}
		if (r.status === 'approved' && isManager()) {
			wrap.appendChild(C.h('button', { type: 'button', class: 'button button-primary', onClick: () => openMarkPaid(r) }, t('Mark paid')));
		}
		return wrap.children.length ? wrap : '—';
	}

	async function loadClaims() {
		const host = document.getElementById('mc-ex-list');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const rows = await API.get('/api/reimbursement-claims', filters);
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'trip_date', label: t('Trip date'), render: (r) => D.fmtDate(r.trip_date) },
				{ key: 'driver_user_id', label: t('Driver') },
				{ key: 'distance_km', label: t('km'), num: true, render: (r) => String(r.distance_verified_km != null ? r.distance_verified_km : r.distance_km) },
				{ key: 'rate', label: t('Rate'), num: true, render: (r) => Money.format(r.rate_per_km_minor) + ' / km' },
				{ key: 'amount', label: t('Amount'), num: true, render: (r) => Money.format(r.amount_claimable_minor) },
				{ key: 'taxable', label: t('Taxable'), num: true, render: (r) => Money.format(r.amount_taxable_minor) },
				{ key: 'purpose', label: t('Purpose') },
				{ key: 'status', label: t('Status'), render: (r) => claimStatusBadge(r.status) },
				{ key: 'actions', label: t('Actions'), render: rowActions },
			], rows, {
				ariaLabel: t('Reimbursement claims'),
				emptyHeading: t('No claims match these filters.'),
				emptyHint: isDriver() ? t('Drivers can submit a new claim for a trip with their private vehicle.') : t('Submitted claims will appear here for review.'),
			});
		} catch (e) {
			C.setLoading(host, false);
			M.reportError(e);
		}
	}

	async function submitClaim(id) {
		const ok = await C.confirmDialog(t('Submit this claim for manager approval? You cannot edit it after submitting.'), { ok: t('Submit claim') });
		if (!ok) return;
		try {
			const r = await API.post('/api/reimbursement-claims/' + id + '/submit', {});
			M.toast(t('Claim submitted.'), 'success');
			if (r && r.warnings && r.warnings.indexOf('SAME_DAY_POOL_BOOKING_SOFT_WARNING') !== -1) {
				M.toast(t('Heads up: you have an approved company-vehicle booking on the same day. The reviewer will see this note.'), 'warning');
			}
			loadClaims();
		} catch (e) { M.reportError(e); }
	}

	function openApprove(claim) {
		const body = C.h('form', { class: 'mc-form', novalidate: true });
		body.appendChild(C.h('p', null, t('Optionally adjust the verified distance — the rate × verified distance becomes the payout.')));
		body.appendChild(C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: 'mc-ex-ap-dist' }, t('Verified distance (km)')),
			C.h('input', { id: 'mc-ex-ap-dist', name: 'distanceVerifiedKm', type: 'number', min: 0, max: 99999, value: String(claim.distance_km) }),
			C.h('p', { class: 'mc-form-row__hint' }, t('Leave unchanged to accept the driver’s figure.')),
		]));
		C.showDialog({
			title: t('Approve claim #{id}', { id: claim.id }),
			body,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Approve'), primary: true, closeOnClick: false, onClick: async () => {
						const v = body.querySelector('#mc-ex-ap-dist').value;
						try {
							await API.post('/api/reimbursement-claims/' + claim.id + '/approve', {
								distanceVerifiedKm: v === '' ? null : parseInt(v, 10),
							});
							C.closeDialog();
							M.toast(t('Claim approved.'), 'success');
							loadClaims();
						} catch (e) { M.reportError(e); }
					},
				},
			],
		});
	}

	function openReject(claim) {
		const body = C.h('form', { class: 'mc-form', novalidate: true });
		const hintId = 'mc-ex-rj-hint';
		const errId = 'mc-ex-rj-err';
		body.appendChild(C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: 'mc-ex-rj-reason' }, [t('Reason'), C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*')]),
			C.h('textarea', {
				id: 'mc-ex-rj-reason',
				name: 'reason',
				rows: 3,
				maxlength: 2000,
				required: true,
				'aria-required': 'true',
				'aria-describedby': hintId + ' ' + errId,
			}),
			C.h('p', { id: hintId, class: 'mc-form-row__hint' }, t('The driver will see this reason and can submit a corrected claim.')),
			C.h('p', { id: errId, class: 'mc-form-row__error', role: 'alert' }),
		]));
		C.showDialog({
			title: t('Reject claim #{id}', { id: claim.id }),
			body,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Reject'), danger: true, closeOnClick: false, onClick: async () => {
						C.clearErrors(body);
						const reason = (body.querySelector('#mc-ex-rj-reason').value || '').trim();
						if (!reason) {
							C.applyFieldError(body, 'reason', t('A reason is required.'));
							return;
						}
						try {
							await API.post('/api/reimbursement-claims/' + claim.id + '/reject', { reason });
							C.closeDialog();
							M.toast(t('Claim rejected.'), 'success');
							loadClaims();
						} catch (e) {
							if (e.context && e.context.field) C.applyFieldError(body, e.context.field, M.resolveError(e));
							else M.reportError(e);
						}
					},
				},
			],
		});
	}

	function openMarkPaid(claim) {
		const body = C.h('form', { class: 'mc-form', novalidate: true });
		const hintId = 'mc-ex-pd-hint';
		const errId = 'mc-ex-pd-err';
		body.appendChild(C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: 'mc-ex-pd-ref' }, [t('Payment reference'), C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*')]),
			C.h('input', {
				id: 'mc-ex-pd-ref',
				name: 'paymentReference',
				type: 'text',
				maxlength: 120,
				required: true,
				'aria-required': 'true',
				'aria-describedby': hintId + ' ' + errId,
			}),
			C.h('p', { id: hintId, class: 'mc-form-row__hint' }, t('Free-form, e.g. payroll batch number or SEPA reference.')),
			C.h('p', { id: errId, class: 'mc-form-row__error', role: 'alert' }),
		]));
		C.showDialog({
			title: t('Mark claim #{id} as paid', { id: claim.id }),
			body,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Mark paid'), primary: true, closeOnClick: false, onClick: async () => {
						C.clearErrors(body);
						const ref = (body.querySelector('#mc-ex-pd-ref').value || '').trim();
						if (!ref) {
							C.applyFieldError(body, 'paymentReference', t('A payment reference is required.'));
							return;
						}
						try {
							await API.post('/api/reimbursement-claims/' + claim.id + '/mark-paid', { paymentReference: ref });
							C.closeDialog();
							M.toast(t('Claim marked as paid.'), 'success');
							loadClaims();
						} catch (e) {
							if (e.context && e.context.field) C.applyFieldError(body, e.context.field, M.resolveError(e));
							else M.reportError(e);
						}
					},
				},
			],
		});
	}

	// ── Private vehicles section (driver) ───────────────────────────────
	async function loadPrivateVehicles() {
		const host = document.getElementById('mc-ex-pv-list');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const rows = await API.get('/api/private-vehicles');
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'name', label: t('Vehicle'), render: (r) => (r.make || '—') + ' · ' + (r.model || '—') },
				{ key: 'licence_plate', label: t('Plate') },
				{ key: 'engine_type', label: t('Engine'), render: (r) => ({ petrol: t('Petrol'), diesel: t('Diesel'), electric: t('Electric'), hybrid: t('Hybrid'), lpg: t('LPG'), cng: t('CNG') })[r.engine_type] || r.engine_type },
				{ key: 'is_active', label: t('Active'), render: (r) => r.is_active ? t('Yes') : t('No') },
				{
					key: 'actions', label: t('Actions'), render: (r) => C.h('div', { class: 'mc-form-actions' }, [
						C.h('button', { type: 'button', class: 'button', onClick: () => openPvEdit(r) }, t('Edit')),
						r.is_active ? C.h('button', { type: 'button', class: 'button', onClick: () => deactivatePv(r) }, t('Deactivate')) : null,
					].filter(Boolean)),
				},
			], rows, {
				ariaLabel: t('Private vehicles'),
				emptyHeading: t('You have no registered private vehicles.'),
				emptyHint: t('Register the car you intend to use before submitting a reimbursement claim.'),
				emptyAction: { label: t('Add private vehicle'), onClick: () => openPvEdit(null) },
			});
		} catch (e) { C.setLoading(host, false); M.reportError(e); }
	}

	function openPvEdit(existing) {
		const body = C.h('form', { class: 'mc-form', novalidate: true });
		const grid = C.h('div', { class: 'mc-grid-2' });
		const fields = [
			['make', t('Make'), { type: 'text', maxlength: 80, required: true, value: existing ? existing.make : '' }],
			['model', t('Model'), { type: 'text', maxlength: 80, required: true, value: existing ? existing.model : '' }],
			['licencePlate', t('Licence plate'), { type: 'text', maxlength: 30, required: true, value: existing ? existing.licence_plate : '' }],
		];
		fields.forEach(([name, label, attrs]) => {
			const id = 'mc-ex-pv-' + name;
			const errId = id + '-err';
			const inputAttrs = Object.assign({
				id,
				name,
				autocomplete: 'off',
				'aria-describedby': errId,
			}, attrs);
			grid.appendChild(C.h('div', { class: 'mc-form-row' }, [
				C.h('label', { for: id }, [label, C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*')]),
				C.h('input', inputAttrs),
				C.h('p', { id: errId, class: 'mc-form-row__error', role: 'alert' }),
			]));
		});
		const engHintId = 'mc-ex-pv-engine-hint';
		const engErrId = 'mc-ex-pv-engine-err';
		const engine = C.h('select', {
			id: 'mc-ex-pv-engine',
			name: 'engineType',
			required: true,
			'aria-required': 'true',
			'aria-describedby': engHintId + ' ' + engErrId,
		});
		['petrol', 'diesel', 'electric', 'hybrid', 'lpg', 'cng'].forEach((v) => {
			const o = document.createElement('option');
			o.value = v;
			o.textContent = ({ petrol: t('Petrol'), diesel: t('Diesel'), electric: t('Electric'), hybrid: t('Hybrid'), lpg: t('LPG'), cng: t('CNG') })[v];
			if (existing && existing.engine_type === v) o.selected = true;
			engine.appendChild(o);
		});
		grid.appendChild(C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: 'mc-ex-pv-engine' }, [t('Engine type'), C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*')]),
			engine,
			C.h('p', { id: engHintId, class: 'mc-form-row__hint' }, t('Determines the statutory rate band.')),
			C.h('p', { id: engErrId, class: 'mc-form-row__error', role: 'alert' }),
		]));
		body.appendChild(grid);
		C.showDialog({
			title: existing ? t('Edit private vehicle') : t('Add private vehicle'),
			body,
			actions: [
				{ label: t('Cancel') },
				{
					label: existing ? t('Save') : t('Add vehicle'), primary: true, closeOnClick: false, onClick: async () => {
						C.clearErrors(body);
						const payload = {
							make: body.querySelector('#mc-ex-pv-make').value.trim(),
							model: body.querySelector('#mc-ex-pv-model').value.trim(),
							licencePlate: body.querySelector('#mc-ex-pv-licencePlate').value.trim(),
							engineType: body.querySelector('#mc-ex-pv-engine').value,
						};
						let focusFirst = true;
						['make', 'model', 'licencePlate'].forEach((fn) => {
							const v = payload[fn];
							if (!v) {
								C.applyFieldError(body, fn, t('This field is required.'), { focus: focusFirst });
								focusFirst = false;
							}
						});
						if (!payload.make || !payload.model || !payload.licencePlate) return;
						try {
							if (existing) await API.put('/api/private-vehicles/' + existing.id, payload);
							else await API.post('/api/private-vehicles', payload);
							C.closeDialog();
							M.toast(existing ? t('Vehicle updated.') : t('Vehicle added.'), 'success');
							loadPrivateVehicles();
						} catch (e) {
							if (e.context && e.context.field) C.applyFieldError(body, e.context.field, M.resolveError(e));
							else M.reportError(e);
						}
					},
				},
			],
		});
	}

	async function deactivatePv(pv) {
		const ok = await C.confirmDialog(t('Deactivate {make} {model} ({plate})? You can keep filing past claims but not new ones.', { make: pv.make, model: pv.model, plate: pv.licence_plate }), { ok: t('Deactivate') });
		if (!ok) return;
		try {
			await API.post('/api/private-vehicles/' + pv.id + '/deactivate', {});
			M.toast(t('Vehicle deactivated.'), 'success');
			loadPrivateVehicles();
		} catch (e) { M.reportError(e); }
	}

	// ── Rate catalogue (info) ───────────────────────────────────────────
	async function loadRates() {
		const host = document.getElementById('mc-ex-rates');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const rows = await API.get('/api/reimbursement-rates');
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'jurisdiction_code', label: t('Jurisdiction') },
				{ key: 'vehicle_type', label: t('Vehicle type') },
				{ key: 'rate_tier', label: t('Tier'), render: (r) => r.rate_tier || '—' },
				{ key: 'rate', label: t('Rate / km'), num: true, render: (r) => Money.format(r.rate_per_km_minor) },
				{ key: 'taxable_above', label: t('Taxable above (km)'), num: true, render: (r) => r.taxable_threshold_km != null ? String(r.taxable_threshold_km) : '—' },
				{ key: 'valid_from', label: t('Valid from'), render: (r) => D.fmtDate(r.valid_from) },
				{ key: 'valid_until', label: t('Valid until'), render: (r) => r.valid_until ? D.fmtDate(r.valid_until) : t('open-ended') },
			], rows, {
				ariaLabel: t('Reimbursement rate catalogue'),
				emptyHeading: t('No rates configured yet.'),
				emptyHint: t('A fleet admin needs to add at least one rate before claims can be submitted.'),
			});
		} catch (e) { C.setLoading(host, false); /* non-fatal */ }
	}

	async function init() {
		C.bootstrap();
		const f = document.getElementById('mc-ex-filters');
		f?.addEventListener('change', (ev) => {
			if (!ev.target || !ev.target.name) return;
			filters[ev.target.name] = ev.target.value || '';
			loadClaims();
		});
		document.getElementById('mc-ex-pv-add')?.addEventListener('click', () => openPvEdit(null));
		await loadDriverFilterOptions();
		C.applySearchParamsToFilters(filters, f);
		loadClaims();
		if (isDriver()) loadPrivateVehicles();
		loadRates();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { void init(); });
	else void init();
})();
