/**
 * Logbook entry detail (Appendix A3).
 *
 *  - Draft: editable form + "Save draft" + "Confirm entry" (with attest checkbox).
 *  - Confirmed (not superseded): read-only definition list + "Amend" button.
 *  - Superseded: clearly marked, pointer to the successor.
 *
 * Field-level errors are surfaced inline; ODOMETER_CHAIN_BROKEN uses the
 * start-odometer field (no duplicate assertive alert). Attest checkbox and
 * amend dialog use the same `applyFieldError` pattern as other modules.
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const D = window.MobilityCheckDates;
	const t = M.t;

	const tripLabels = {
		business: t('Business'),
		commute: t('Commute'),
		private: t('Private'),
	};

	function isManager() {
		const r = document.getElementById('app-content');
		return r && r.getAttribute('data-mc-is-manager') === '1';
	}
	function isFleetAdmin() {
		const r = document.getElementById('app-content');
		return r && r.getAttribute('data-mc-is-fleet-admin') === '1';
	}
	function currentUserId() {
		const r = document.getElementById('app-content');
		return r && r.getAttribute('data-mc-current-user') || '';
	}
	function entryId() {
		const sec = document.querySelector('[data-entry-id]');
		return parseInt(sec ? sec.getAttribute('data-entry-id') : '0', 10);
	}

	function row(label, value) {
		return [C.h('dt', null, label), C.h('dd', null, value == null || value === '' ? '—' : String(value))];
	}

	function statusBadge(entry) {
		if (entry.is_superseded) return C.h('span', { class: 'mc-status-badge', 'data-status': 'cancelled', 'aria-label': t('Superseded by amendment') }, t('Superseded'));
		if (entry.amendment_of_entry_id) return C.h('span', { class: 'mc-status-badge', 'data-status': 'approved', 'aria-label': t('Amended entry') }, t('Amended'));
		if (entry.confirmed_at) return C.h('span', { class: 'mc-status-badge', 'data-status': 'completed', 'aria-label': t('Confirmed') }, t('Confirmed'));
		return C.h('span', { class: 'mc-status-badge', 'data-status': 'pending_approval', 'aria-label': t('Draft') }, t('Draft'));
	}

	async function load() {
		const host = document.getElementById('mc-lbd-host');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const entry = await API.get('/api/logbook/' + entryId());
			C.setLoading(host, false);
			render(host, entry);
		} catch (e) {
			C.setLoading(host, false);
			M.reportError(e);
		}
	}

	function render(host, entry) {
		host.innerHTML = '';
		const head = C.h('div', { class: 'mc-grid-2' }, [
			C.h('div', { class: 'mc-callout mc-callout--neutral', role: 'note' }, [
				C.h('h3', { class: 'mc-callout__title' }, t('State')),
				statusBadge(entry),
				entry.late_entry ? C.h('p', { class: 'mc-form-row__hint' }, t('Saved later than the configured grace period — flagged for review.')) : null,
				entry.amendment_of_entry_id ? C.h('p', { class: 'mc-form-row__hint' }, t('This is an amendment of entry #{id}.', { id: entry.amendment_of_entry_id })) : null,
				entry.is_superseded ? C.h('p', { class: 'mc-form-row__hint' }, t('A newer amendment has replaced this entry. It remains visible for the audit trail.')) : null,
			]),
			C.h('dl', { class: 'mc-dl' }, [
				row(t('Trip date'), D.fmtDate(entry.trip_date)),
				row(t('Type'), tripLabels[entry.trip_type] || entry.trip_type),
				row(t('Driver'), entry.driver_user_id),
				row(t('Vehicle'), '#' + entry.vehicle_id),
			]),
		]);
		host.appendChild(head);

		const canEditDraft = !entry.confirmed_at && (entry.driver_user_id === currentUserId() || isManager() || isFleetAdmin());
		if (canEditDraft) {
			host.appendChild(buildDraftEditor(entry));
		} else {
			host.appendChild(buildReadOnly(entry));
		}

		const canAmend = entry.confirmed_at && !entry.is_superseded && (entry.driver_user_id === currentUserId() || isManager() || isFleetAdmin());
		if (canAmend) {
			const actions = C.h('div', { class: 'mc-form-actions' }, [
				C.h('button', { type: 'button', class: 'button button-primary', onClick: () => openAmendDialog(entry) }, t('Amend this entry')),
			]);
			host.appendChild(actions);
		}
	}

	function buildReadOnly(entry) {
		const dl = C.h('dl', { class: 'mc-dl' }, [
			row(t('Departure time'), entry.departure_time || '—'),
			row(t('Arrival time'), entry.arrival_time || '—'),
			row(t('Start address'), entry.start_address),
			row(t('End address'), entry.end_address),
			row(t('Start odometer (km)'), entry.odometer_start_km),
			row(t('End odometer (km)'), entry.odometer_end_km),
			row(t('Distance (km)'), entry.distance_km),
			row(t('Purpose'), entry.purpose),
			row(t('Client / contact'), entry.client_or_contact),
			row(t('Project'), entry.project_reference),
			row(t('Confirmed at'), entry.confirmed_at ? D.fmtDateTime(entry.confirmed_at) : '—'),
			row(t('Confirmed by'), entry.confirmed_by_user_id),
			row(t('Created at'), D.fmtDateTime(entry.created_at)),
			entry.amendment_reason ? row(t('Amendment reason'), entry.amendment_reason) : null,
		].filter(Boolean));
		return C.h('section', { 'aria-label': t('Logbook entry details') }, dl);
	}

	function buildDraftEditor(entry) {
		const form = C.h('form', { class: 'mc-form', novalidate: true });
		form.id = 'mc-lbd-form';
		const grid = C.h('div', { class: 'mc-grid-2' });

		function field(name, label, attrs, hint) {
			const inputAttrs = Object.assign({ id: 'mc-lbd-' + name, name }, attrs || {});
			const tag = attrs && attrs.type === 'textarea' ? 'textarea' : (attrs && attrs.tag === 'select' ? 'select' : 'input');
			let input;
			if (tag === 'textarea') {
				delete inputAttrs.type;
				input = C.h('textarea', inputAttrs, attrs.value || '');
			} else if (tag === 'select') {
				delete inputAttrs.tag;
				delete inputAttrs.type;
				input = C.h('select', inputAttrs);
				(attrs.options || []).forEach((o) => {
					const opt = document.createElement('option');
					opt.value = o.value;
					opt.textContent = o.label;
					if (o.value === attrs.value) opt.selected = true;
					input.appendChild(opt);
				});
			} else {
				input = C.h('input', inputAttrs);
			}
			const row = C.h('div', { class: 'mc-form-row' }, [
				C.h('label', { for: inputAttrs.id }, label),
				input,
				hint ? C.h('p', { class: 'mc-form-row__hint' }, hint) : null,
				C.h('p', { class: 'mc-form-row__error', role: 'alert' }),
			].filter(Boolean));
			grid.appendChild(row);
		}

		field('tripType', t('Trip type'), { tag: 'select', value: entry.trip_type, options: [
			{ value: 'business', label: t('Business') }, { value: 'commute', label: t('Commute') }, { value: 'private', label: t('Private') },
		] });
		field('tripDate', t('Trip date'), { type: 'date', value: entry.trip_date, required: true });
		field('departureTime', t('Departure time'), { type: 'time', value: entry.departure_time || '' });
		field('arrivalTime', t('Arrival time'), { type: 'time', value: entry.arrival_time || '' });
		field('startAddress', t('Start address'), { type: 'text', value: entry.start_address, maxlength: 500, autocomplete: 'off' });
		field('endAddress', t('End address'), { type: 'text', value: entry.end_address, maxlength: 500, autocomplete: 'off' });
		field('odometerStartKm', t('Start odometer (km)'), { type: 'number', value: String(entry.odometer_start_km), min: 0, max: 9999999 });
		field('odometerEndKm', t('End odometer (km)'), { type: 'number', value: String(entry.odometer_end_km), min: 0, max: 9999999 });
		field('clientOrContact', t('Client or contact'), { type: 'text', value: entry.client_or_contact || '', maxlength: 250, autocomplete: 'off' });
		field('projectReference', t('Project / reference'), { type: 'text', value: entry.project_reference || '', maxlength: 120, autocomplete: 'off' });
		form.appendChild(grid);

		const purpose = C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: 'mc-lbd-purpose' }, t('Purpose')),
			C.h('textarea', { id: 'mc-lbd-purpose', name: 'purpose', rows: 3, maxlength: 4000 }, entry.purpose || ''),
			C.h('p', { class: 'mc-form-row__error', role: 'alert' }),
		]);
		form.appendChild(purpose);

		const attestErrId = 'mc-lbd-attest-err';
		const attest = C.h('div', { class: 'mc-form-row mc-form-row--checkbox' }, [
			C.h('div', { class: 'mc-checkbox-row' }, [
				C.h('input', {
					type: 'checkbox',
					id: 'mc-lbd-attest',
					name: 'attestConfirmed',
					'aria-describedby': attestErrId,
				}),
				C.h('label', { for: 'mc-lbd-attest' }, t('I confirm that this entry is correct and complete. Once confirmed it cannot be edited — only amended with a documented reason.')),
			]),
			C.h('p', { id: attestErrId, class: 'mc-form-row__error', role: 'alert' }),
		]);
		form.appendChild(attest);

		const actions = C.h('div', { class: 'mc-form-actions' }, [
			C.h('button', { type: 'submit', class: 'button' }, t('Save draft')),
			C.h('button', { type: 'button', class: 'button button-primary', onClick: () => confirmEntry(form, entry) }, t('Confirm entry')),
		]);
		form.appendChild(actions);

		form.addEventListener('submit', (ev) => { ev.preventDefault(); saveDraft(form, entry); });
		return form;
	}

	function collectDraftPayload(form) {
		return {
			tripType: form.elements.tripType.value,
			tripDate: form.elements.tripDate.value,
			departureTime: form.elements.departureTime.value || null,
			arrivalTime: form.elements.arrivalTime.value || null,
			startAddress: (form.elements.startAddress.value || '').trim(),
			endAddress: (form.elements.endAddress.value || '').trim(),
			odometerStartKm: parseInt(form.elements.odometerStartKm.value, 10),
			odometerEndKm: parseInt(form.elements.odometerEndKm.value, 10),
			clientOrContact: (form.elements.clientOrContact.value || '').trim() || null,
			projectReference: (form.elements.projectReference.value || '').trim() || null,
			purpose: (form.elements.purpose.value || '').trim(),
		};
	}

	async function saveDraft(form, entry) {
		C.clearErrors(form);
		const payload = collectDraftPayload(form);
		C.lockForm(form, true);
		try {
			await API.put('/api/logbook/' + entry.id, payload);
			M.toast(t('Draft saved.'), 'success');
			load();
		} catch (e) {
			C.lockForm(form, false);
			if (e.code === 'ODOMETER_CHAIN_BROKEN') {
				C.applyFieldError(form, 'odometerStartKm', M.resolveError(e));
			} else if (e.context && e.context.field) {
				C.applyFieldError(form, e.context.field, M.resolveError(e));
			} else {
				M.reportError(e);
			}
		}
	}

	async function confirmEntry(form, entry) {
		C.clearErrors(form);
		const attest = form.elements.attestConfirmed.checked;
		if (!attest) {
			C.applyFieldError(form, 'attestConfirmed', M.resolveError({ code: 'LOGBOOK_ATTEST_REQUIRED' }));
			return;
		}
		const draft = collectDraftPayload(form);
		C.lockForm(form, true);
		try {
			await API.put('/api/logbook/' + entry.id, draft);
			await API.post('/api/logbook/' + entry.id + '/confirm', { attestConfirmed: true });
			M.toast(t('Entry confirmed.'), 'success');
			load();
		} catch (e) {
			C.lockForm(form, false);
			if (e.code === 'ODOMETER_CHAIN_BROKEN') {
				C.applyFieldError(form, 'odometerStartKm', M.resolveError(e));
			} else if (e.context && e.context.field) {
				C.applyFieldError(form, e.context.field, M.resolveError(e));
			} else {
				M.reportError(e);
			}
		}
	}

	function openAmendDialog(entry) {
		const body = C.h('form', { class: 'mc-form', id: 'mc-lbd-amend-form', novalidate: true });
		body.appendChild(C.h('p', null, t('An amendment creates a new entry and marks this one as superseded. Both stay visible for the audit trail.')));
		const grid = C.h('div', { class: 'mc-grid-2' });
		const fields = [
			['tripType', t('Trip type'), 'select', entry.trip_type],
			['tripDate', t('Trip date'), 'date', entry.trip_date],
			['departureTime', t('Departure time'), 'time', entry.departure_time || ''],
			['arrivalTime', t('Arrival time'), 'time', entry.arrival_time || ''],
			['startAddress', t('Start address'), 'text', entry.start_address],
			['endAddress', t('End address'), 'text', entry.end_address],
			['odometerStartKm', t('Start odometer (km)'), 'number', String(entry.odometer_start_km)],
			['odometerEndKm', t('End odometer (km)'), 'number', String(entry.odometer_end_km)],
			['clientOrContact', t('Client or contact'), 'text', entry.client_or_contact || ''],
			['projectReference', t('Project / reference'), 'text', entry.project_reference || ''],
		];
		fields.forEach(([name, label, type, value]) => {
			const id = 'mc-lbd-am-' + name;
			let input;
			if (type === 'select') {
				input = C.h('select', { id, name });
				['business', 'commute', 'private'].forEach((v) => {
					const o = document.createElement('option');
					o.value = v; o.textContent = ({ business: t('Business'), commute: t('Commute'), private: t('Private') })[v];
					if (v === value) o.selected = true;
					input.appendChild(o);
				});
			} else {
				input = C.h('input', { id, name, type, value, maxlength: type === 'text' ? 500 : null });
			}
			grid.appendChild(C.h('div', { class: 'mc-form-row' }, [
				C.h('label', { for: id }, label),
				input,
				C.h('p', { class: 'mc-form-row__error', role: 'alert' }),
			]));
		});
		body.appendChild(grid);
		body.appendChild(C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: 'mc-lbd-am-purpose' }, t('Purpose')),
			C.h('textarea', { id: 'mc-lbd-am-purpose', name: 'purpose', rows: 3, maxlength: 4000 }, entry.purpose || ''),
			C.h('p', { class: 'mc-form-row__error', role: 'alert' }),
		]));
		body.appendChild(C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: 'mc-lbd-am-reason' }, t('Reason for amendment')),
			C.h('textarea', { id: 'mc-lbd-am-reason', name: 'reason', rows: 2, maxlength: 2000, required: true }),
			C.h('p', { class: 'mc-form-row__hint' }, t('Mandatory. Becomes part of the audit log.')),
			C.h('p', { class: 'mc-form-row__error', role: 'alert' }),
		]));

		C.showDialog({
			title: t('Amend logbook entry'),
			body,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Submit amendment'), primary: true, closeOnClick: false, 					onClick: async () => {
						C.clearErrors(body);
						const reason = (body.querySelector('#mc-lbd-am-reason').value || '').trim();
						if (!reason) {
							C.applyFieldError(body, 'reason', M.resolveError({ code: 'AMENDMENT_REASON_REQUIRED' }));
							return;
						}
						const payload = {
							tripType: body.querySelector('#mc-lbd-am-tripType').value,
							tripDate: body.querySelector('#mc-lbd-am-tripDate').value,
							departureTime: body.querySelector('#mc-lbd-am-departureTime').value || null,
							arrivalTime: body.querySelector('#mc-lbd-am-arrivalTime').value || null,
							startAddress: (body.querySelector('#mc-lbd-am-startAddress').value || '').trim(),
							endAddress: (body.querySelector('#mc-lbd-am-endAddress').value || '').trim(),
							odometerStartKm: parseInt(body.querySelector('#mc-lbd-am-odometerStartKm').value, 10),
							odometerEndKm: parseInt(body.querySelector('#mc-lbd-am-odometerEndKm').value, 10),
							clientOrContact: (body.querySelector('#mc-lbd-am-clientOrContact').value || '').trim() || null,
							projectReference: (body.querySelector('#mc-lbd-am-projectReference').value || '').trim() || null,
							purpose: (body.querySelector('#mc-lbd-am-purpose').value || '').trim(),
							reason,
						};
						try {
							const newEntry = await API.post('/api/logbook/' + entry.id + '/amend', payload);
							C.closeDialog();
							M.toast(t('Amendment created.'), 'success');
							window.location.href = C.detailUrl('logbook', newEntry.id);
						} catch (e) {
							if (e.context && e.context.field) C.applyFieldError(body, e.context.field, M.resolveError(e));
							else M.reportError(e);
						}
					},
				},
			],
		});
	}

	function init() {
		C.bootstrap();
		load();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
