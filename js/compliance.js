/**
 * Compliance page.
 *
 * Instruction completion uses an accessible modal (no `window.prompt`).
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const { fmtDate, isoForDateInput } = window.MobilityCheckDates;
	const t = M.t;

	let year = new Date().getUTCFullYear();

	function driverUserIdFromUrl() {
		return (new URLSearchParams(window.location.search).get('driverUserId') || '').trim();
	}

	function filterRowsByUrlDriver(rows) {
		const uid = driverUserIdFromUrl();
		const list = API.asArray(rows);
		if (!uid) return list;
		return list.filter((r) => String(r.userId || '') === uid);
	}

	async function loadInstr() {
		const host = document.getElementById('mc-cmp-inst');
		if (!host) return;
		const root = C.bootstrap().root;
		const scopedLm = root && root.getAttribute('data-mc-line-manager-scoped-reader') === '1';
		C.setLoading(host, true);
		try {
			const rows = filterRowsByUrlDriver(await API.get('/api/compliance/instructions', { year }));
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'userId', label: t('Driver'), render: (r) => C.h('a', { class: 'mc-button-link', href: C.detailUrl('drivers', r.driverProfileId) }, r.userId) },
				{ key: 'completedDate', label: t('Completed'), render: (r) => r.completedDate ? fmtDate(r.completedDate) : '—' },
				{ key: 'status', label: t('Status'), render: (r) => C.statusBadge(r.completedDate ? 'completed' : 'open') },
				{ key: 'recordedBy', label: t('Recorded by'), render: (r) => r.recordedBy || '—' },
				{ key: 'actions', label: t('Actions'), render: (r) => {
					if (scopedLm) return '—';
					return r.completedDate ? '—' : C.h('button', { type: 'button', class: 'button', onClick: () => record(r.driverProfileId) }, t('Record now'));
				} },
			], rows, { ariaLabel: t('Yearly instructions (UVV)'), emptyHeading: t('No driver profiles to track yet.') });
		} catch (e) { C.setLoading(host, false); M.reportError(e); }
	}

	async function record(driverProfileId) {
		const uid = 'mc-cmp-rec-' + Math.random().toString(36).slice(2, 9);
		const todayYmd = isoForDateInput(new Date());
		const hintIntro = C.h('p', { class: 'mc-section__sub' }, t('Enter when the instruction was completed. The table above is filtered to calendar year {y}.', { y: String(year) }));
		const dhId = uid + '-dh';
		const deId = uid + '-de';
		const dateInput = C.h('input', {
			id: uid + '-date',
			type: 'date',
			class: 'mc-input',
			name: 'completedDate',
			value: todayYmd,
			max: todayYmd,
			required: true,
			'aria-required': 'true',
			'aria-describedby': dhId + ' ' + deId,
		});
		const dateErr = C.h('p', { class: 'mc-form-row__error', id: deId, 'aria-live': 'polite' });
		const dateRow = C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: uid + '-date', class: 'mc-form-row__label' }, t('Completed date')),
			dateInput,
			C.h('p', { class: 'mc-form-row__hint', id: dhId }, t('Use a date on or before today (UTC), matching the server.')),
			dateErr,
		]);
		const rhId = uid + '-rh';
		const reId = uid + '-re';
		const refInput = C.h('input', {
			id: uid + '-ref',
			type: 'text',
			class: 'mc-input',
			name: 'reference',
			maxlength: 120,
			autocomplete: 'off',
			'aria-describedby': rhId + ' ' + reId,
		});
		const refErr = C.h('p', { class: 'mc-form-row__error', id: reId, 'aria-live': 'polite' });
		const refRow = C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: uid + '-ref', class: 'mc-form-row__label' }, t('Reference')),
			refInput,
			C.h('p', { class: 'mc-form-row__hint', id: rhId }, t('Optional — certificate or file number (max. {max} characters).', { max: 120 })),
			refErr,
		]);
		const body = C.h('div', { class: 'mc-dialog__body-stack' }, [hintIntro, dateRow, refRow]);
		let submitting = false;
		function clearRowErrors() {
			dateRow.classList.remove('has-error');
			refRow.classList.remove('has-error');
			dateErr.textContent = '';
			refErr.textContent = '';
			dateInput.removeAttribute('aria-invalid');
			refInput.removeAttribute('aria-invalid');
		}
		function validateClient() {
			clearRowErrors();
			let ok = true;
			const d = (dateInput.value || '').trim();
			if (!d) {
				dateRow.classList.add('has-error');
				dateErr.textContent = t('Please choose a completion date.');
				dateInput.setAttribute('aria-invalid', 'true');
				dateInput.focus();
				ok = false;
			} else if (d > todayYmd) {
				dateRow.classList.add('has-error');
				dateErr.textContent = t('Completion date cannot be in the future.');
				dateInput.setAttribute('aria-invalid', 'true');
				dateInput.focus();
				ok = false;
			}
			const refV = refInput.value || '';
			if (refV.length > 120) {
				refRow.classList.add('has-error');
				refErr.textContent = t('Reference may be at most {max} characters.', { max: 120 });
				refInput.setAttribute('aria-invalid', 'true');
				if (ok) refInput.focus();
				ok = false;
			}
			return ok;
		}
		function setBusy(on) {
			submitting = !!on;
			backdrop.querySelectorAll('.mc-form-actions button').forEach((b) => { b.disabled = !!on; });
			dateInput.disabled = !!on;
			refInput.disabled = !!on;
		}
		const backdrop = C.showDialog({
			title: t('Record instruction'),
			closeOnBackdropClick: false,
			body,
			actions: [
				{ label: t('Cancel'), onClick: () => { if (submitting) return; } },
				{ label: t('Record now'), primary: true, closeOnClick: false, onClick: () => { void submit(); } },
			],
		});
		async function submit() {
			if (submitting) return;
			if (!validateClient()) return;
			setBusy(true);
			try {
				await API.post('/api/compliance/instructions/' + driverProfileId + '/complete', {
					calendarYear: year,
					completedDate: dateInput.value,
					reference: (refInput.value || '').trim(),
				});
				C.closeDialog();
				M.toast(t('Instruction recorded.'), 'success');
				loadInstr();
			} catch (e) {
				const field = e && e.context && e.context.field;
				if (field === 'completedDate' || field === 'calendarYear') {
					dateRow.classList.add('has-error');
					dateErr.textContent = M.resolveError(e);
					dateInput.setAttribute('aria-invalid', 'true');
					dateInput.focus();
				} else if (field === 'reference') {
					refRow.classList.add('has-error');
					refErr.textContent = M.resolveError(e);
					refInput.setAttribute('aria-invalid', 'true');
					refInput.focus();
				} else {
					M.reportError(e);
				}
			} finally {
				setBusy(false);
			}
		}
	}

	async function loadLic() {
		const host = document.getElementById('mc-cmp-lic');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const rows = filterRowsByUrlDriver(await API.get('/api/compliance/licences'));
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'userId', label: t('Driver'), render: (r) => C.h('a', { class: 'mc-button-link', href: C.detailUrl('drivers', r.id) }, r.userId) },
				{ key: 'licenceStatus', label: t('Status'), render: (r) => C.statusBadge(r.licenceStatus) },
				{ key: 'licenceExpiryDate', label: t('Expires'), render: (r) => r.licenceExpiryDate ? fmtDate(r.licenceExpiryDate) : '—' },
				{ key: 'licenceClasses', label: t('Classes'), render: (r) => r.licenceClasses || '—' },
				{ key: 'daysToExpiry', label: t('Days to expiry'), num: true, render: (r) => r.daysToExpiry == null ? '—' : r.daysToExpiry },
			], rows, { ariaLabel: t('Licence overview'), emptyHeading: t('No driver profiles yet.') });
		} catch (e) { C.setLoading(host, false); M.reportError(e); }
	}

	function init() {
		C.bootstrap();
		const y = document.getElementById('mc-cmp-year');
		y?.addEventListener('change', () => { year = parseInt(y.value, 10) || year; loadInstr(); });
		loadInstr();
		loadLic();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
