/**
 * Driver detail page.
 *
 *  - GET /api/drivers/{id} and /api/drivers/{id}/compliance
 *  - PUT to update profile, POST upload-licence to attach a scan
 *  - POST verify-licence / reject-licence (managers only) via accessible dialogs
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const { fmtDate, fmtDateTime } = window.MobilityCheckDates;
	const t = M.t;

	function id() {
		const el = document.getElementById('mc-driver-id');
		return el ? parseInt(el.value, 10) : 0;
	}

	/** Default date window for report deep links (matches vehicle detail pattern). */
	function reportRangeParams() {
		const to = new Date();
		const from = new Date(to.getTime());
		from.setUTCDate(from.getUTCDate() - 90);
		return {
			from: from.toISOString().slice(0, 10),
			to: to.toISOString().slice(0, 10),
		};
	}

	function renderRelatedDriverLinks(driverUserId) {
		const ul = document.getElementById('mc-drv-related');
		if (!ul || !driverUserId) return;
		ul.innerHTML = '';
		function add(href, label, ariaLabel) {
			ul.appendChild(C.h('li', null, [
				C.h('a', { class: 'mc-button-link', href, 'aria-label': ariaLabel }, label),
			]));
		}
		add(C.listUrl('bookings', { driverUserId: driverUserId }), t('Bookings'), t('Bookings filtered to this driver'));
		add(C.listUrl('logbook', { driverUserId: driverUserId }), t('Logbook (Fahrtenbuch)'), t('Logbook filtered to this driver'));
		add(C.listUrl('expenses', { driverUserId: driverUserId }), t('Reimbursement claims'), t('Reimbursement claims filtered to this driver'));
		add(C.listUrl('compliance', { driverUserId: driverUserId }), t('Compliance'), t('Open list pages with this driver pre-selected in the filter where supported.'));
		const rep = reportRangeParams();
		add(C.listUrl('reports', Object.assign({ tab: 'bookings', driverUserId: driverUserId }, rep)), t('Bookings report'), t('Bookings report'));
	}

	function row(dl, dt, dd) {
		dl.appendChild(C.h('dt', null, dt));
		dl.appendChild(C.h('dd', null, dd == null || dd === '' ? '—' : dd));
	}

	function fillForm(driver) {
		const form = document.getElementById('mc-drv-form');
		if (!form) return;
		['licence_number', 'licence_classes', 'licence_issue_date', 'licence_expiry_date', 'licence_authority', 'commute_distance_km', 'notes'].forEach((f) => {
			const el = form.elements[f];
			if (el && driver[f] != null) el.value = driver[f];
		});
	}

	const STATUS_LABELS = {
		not_provided: t('Licence not provided'),
		uploaded_pending_verification: t('Pending verification'),
		verified: t('Verified'),
		expired: t('Expired'),
		rejected: t('Rejected'),
		blocked: t('Blocked'),
	};

	function licenceStatusLabel(status) {
		return STATUS_LABELS[status] || t('Unknown status');
	}

	/**
	 * GET /api/drivers/{id}/compliance returns `{ driver, instructions, … }`.
	 * If the envelope was lost during unwrap, the payload may be the bare
	 * driver row (same shape as GET /api/drivers/{id}). Accept both.
	 *
	 * @param {unknown} raw
	 * @returns {{ driver: object, currentYear: number, daysToExpiry: unknown, instructions: unknown[], verifications: unknown[], currentYearInstructionComplete: boolean } | null}
	 */
	function normalizeDriverCompliancePayload(raw) {
		if (!raw || typeof raw !== 'object') {
			return null;
		}
		const o = /** @type {Record<string, unknown>} */ (raw);
		if (o.driver != null && typeof o.driver === 'object') {
			return /** @type {any} */ (raw);
		}
		if (typeof o.user_id === 'string' && o.id != null) {
			const y = new Date().getUTCFullYear();
			return {
				driver: /** @type {object} */ (raw),
				currentYear: y,
				daysToExpiry: null,
				instructions: [],
				verifications: [],
				currentYearInstructionComplete: false,
			};
		}
		return null;
	}

	async function load() {
		const did = id();
		if (!did) return;
		const dl = document.getElementById('mc-drv-dl');
		C.setLoading(dl, true);
		try {
			const raw = await API.get('/api/drivers/' + did + '/compliance');
			const compliance = normalizeDriverCompliancePayload(raw);
			if (!compliance) {
				const err = new Error('DRIVER_NOT_FOUND');
				err.code = 'DRIVER_NOT_FOUND';
				throw err;
			}
			const d = compliance.driver;
			document.querySelectorAll('[data-mc-bind="user_id"]').forEach((el) => { el.textContent = d.user_id; });
			document.querySelectorAll('[data-mc-bind="licence_status"]').forEach((el) => { el.textContent = licenceStatusLabel(d.licence_status); });
			dl.innerHTML = '';
			row(dl, t('Licence status'), C.statusBadge(d.licence_status));
			row(dl, t('Compliance status'), C.statusBadge(d.compliance_status));
			row(dl, t('Licence number'), d.licence_number);
			row(dl, t('Classes'), d.licence_classes);
			row(dl, t('Issue date'), d.licence_issue_date ? fmtDate(d.licence_issue_date) : null);
			row(dl, t('Expiry date'), d.licence_expiry_date ? fmtDate(d.licence_expiry_date) : null);
			row(dl, t('Days to expiry'), compliance.daysToExpiry == null ? null : compliance.daysToExpiry);
			row(dl, t('Authority'), d.licence_authority);
			row(dl, t('Yearly instruction for {y}', { y: compliance.currentYear }), compliance.currentYearInstructionComplete ? t('Completed') : t('Outstanding'));
			fillForm(d);

			renderRelatedDriverLinks(d.user_id);
			renderInstructions(compliance.instructions || []);
			renderVerifications(compliance.verifications || []);
			M.polite(t('Driver loaded.'));
		} catch (e) {
			C.setLoading(dl, false);
			M.reportError(e);
		}
	}

	function renderInstructions(rows) {
		const host = document.getElementById('mc-drv-instr');
		if (!host) return;
		C.renderTable(host, [
			{ key: 'year', label: t('Year') },
			{ key: 'completedDate', label: t('Completed'), render: (r) => fmtDate(r.completedDate) },
			{ key: 'recordedBy', label: t('Recorded by') },
			{ key: 'reference', label: t('Reference'), render: (r) => r.reference || '—' },
		], rows, { ariaLabel: t('Yearly instructions (UVV)'), emptyHeading: t('No instruction records yet.') });
	}

	function renderVerifications(rows) {
		const host = document.getElementById('mc-drv-ver');
		if (!host) return;
		C.renderTable(host, [
			{ key: 'verifiedAt', label: t('Verified at'), render: (r) => fmtDateTime(r.verifiedAt) },
			{ key: 'verifiedBy', label: t('Verified by') },
			{ key: 'licenceExpiryAtVerification', label: t('Expiry at check'), render: (r) => r.licenceExpiryAtVerification ? fmtDate(r.licenceExpiryAtVerification) : '—' },
			{ key: 'notes', label: t('Notes'), render: (r) => r.notes || '—' },
		], rows, { ariaLabel: t('Licence verifications'), emptyHeading: t('No verifications yet.') });
	}

	async function save(ev) {
		ev.preventDefault();
		const form = ev.target;
		C.clearErrors(form);
		const payload = C.collectForm(form);
		C.lockForm(form, true);
		try {
			await API.put('/api/drivers/' + id(), payload);
			M.toast(t('Driver profile saved.'), 'success');
			load();
		} catch (e) {
			if (e && e.context && e.context.field) C.applyFieldError(form, e.context.field, M.resolveError(e));
			else M.reportError(e);
		} finally {
			C.lockForm(form, false);
		}
	}

	async function upload(ev) {
		const file = ev.target.files && ev.target.files[0];
		if (!file) return;
		try {
			await API.upload('/api/drivers/' + id() + '/upload-licence', file);
			M.toast(t('Licence scan uploaded.'), 'success');
			load();
		} catch (e) { M.reportError(e); }
		ev.target.value = '';
	}

	async function verifyLicence() {
		const uid = 'mc-drv-vfy-' + Math.random().toString(36).slice(2, 9);
		const nh = uid + '-nh';
		const ne = uid + '-ne';
		const intro = C.h('p', { class: 'mc-section__sub' }, t('Enter optional notes for this verification. They are stored in the audit trail.'));
		const ta = C.h('textarea', {
			id: uid + '-n',
			class: 'mc-input',
			rows: 4,
			maxlength: 8000,
			'aria-describedby': nh + ' ' + ne,
		});
		const errEl = C.h('p', { class: 'mc-form-row__error', id: ne, 'aria-live': 'polite' });
		const row = C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: uid + '-n', class: 'mc-form-row__label' }, t('Notes')),
			ta,
			C.h('p', { class: 'mc-form-row__hint', id: nh }, t('Optional. Shown with the verification history on this driver profile.')),
			errEl,
		]);
		const body = C.h('div', { class: 'mc-dialog__body-stack' }, [intro, row]);
		let submitting = false;
		function setBusy(on) {
			submitting = !!on;
			backdrop.querySelectorAll('.mc-form-actions button').forEach((b) => { b.disabled = !!on; });
			ta.disabled = !!on;
		}
		function clearErr() {
			row.classList.remove('has-error');
			errEl.textContent = '';
			ta.removeAttribute('aria-invalid');
		}
		const backdrop = C.showDialog({
			title: t('Verify licence'),
			closeOnBackdropClick: false,
			body,
			actions: [
				{ label: t('Cancel'), onClick: () => { if (submitting) return; } },
				{ label: t('Verify licence'), primary: true, closeOnClick: false, onClick: () => { void submit(); } },
			],
		});
		async function submit() {
			if (submitting) return;
			clearErr();
			setBusy(true);
			try {
				const note = (ta.value || '').trim();
				await API.post('/api/drivers/' + id() + '/verify-licence', { note });
				C.closeDialog();
				M.toast(t('Licence verified.'), 'success');
				load();
			} catch (e) {
				const field = e && e.context && e.context.field;
				if (field === 'note') {
					row.classList.add('has-error');
					errEl.textContent = M.resolveError(e);
					ta.setAttribute('aria-invalid', 'true');
					ta.focus();
				} else {
					M.reportError(e);
				}
			} finally {
				setBusy(false);
			}
		}
	}

	async function rejectLicence() {
		const uid = 'mc-drv-rej-' + Math.random().toString(36).slice(2, 9);
		const rh = uid + '-rh';
		const re = uid + '-re';
		const intro = C.h('p', { class: 'mc-section__sub' }, t('The driver must upload a new licence scan after rejection. This is saved in the audit log.'));
		const ta = C.h('textarea', {
			id: uid + '-r',
			class: 'mc-input',
			rows: 4,
			maxlength: 8000,
			required: true,
			'aria-required': 'true',
			'aria-describedby': rh + ' ' + re,
		});
		const errEl = C.h('p', { class: 'mc-form-row__error', id: re, 'aria-live': 'polite' });
		const row = C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: uid + '-r', class: 'mc-form-row__label' }, t('Reason for rejection (visible to the driver):')),
			ta,
			C.h('p', { class: 'mc-form-row__hint', id: rh }, t('Describe why the scan cannot be accepted. This cannot be empty.')),
			errEl,
		]);
		const body = C.h('div', { class: 'mc-dialog__body-stack' }, [intro, row]);
		let submitting = false;
		function setBusy(on) {
			submitting = !!on;
			backdrop.querySelectorAll('.mc-form-actions button').forEach((b) => { b.disabled = !!on; });
			ta.disabled = !!on;
		}
		function clearErr() {
			row.classList.remove('has-error');
			errEl.textContent = '';
			ta.removeAttribute('aria-invalid');
		}
		const backdrop = C.showDialog({
			title: t('Reject licence'),
			closeOnBackdropClick: false,
			body,
			actions: [
				{ label: t('Cancel'), onClick: () => { if (submitting) return; } },
				{ label: t('Reject licence'), danger: true, closeOnClick: false, onClick: () => { void submit(); } },
			],
		});
		async function submit() {
			if (submitting) return;
			clearErr();
			const reason = (ta.value || '').trim();
			if (!reason) {
				row.classList.add('has-error');
				errEl.textContent = t('A reason is required.');
				ta.setAttribute('aria-invalid', 'true');
				ta.focus();
				return;
			}
			setBusy(true);
			try {
				await API.post('/api/drivers/' + id() + '/reject-licence', { reason });
				C.closeDialog();
				M.toast(t('Licence marked as rejected.'), 'warning');
				load();
			} catch (e) {
				const field = e && e.context && e.context.field;
				if (field === 'reason') {
					row.classList.add('has-error');
					errEl.textContent = M.resolveError(e);
					ta.setAttribute('aria-invalid', 'true');
					ta.focus();
				} else {
					M.reportError(e);
				}
			} finally {
				setBusy(false);
			}
		}
	}

	function init() {
		C.bootstrap();
		document.getElementById('mc-drv-form')?.addEventListener('submit', save);
		document.getElementById('mc-drv-upload')?.addEventListener('change', upload);
		document.getElementById('mc-drv-verify')?.addEventListener('click', verifyLicence);
		document.getElementById('mc-drv-reject')?.addEventListener('click', rejectLicence);
		load();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
