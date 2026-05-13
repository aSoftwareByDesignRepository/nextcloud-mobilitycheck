/**
 * Vehicle detail page.
 *
 *  - GET /api/vehicles/{id} (includes active_assignment)
 *  - GET /api/vehicle-assignments?vehicleId= — allocation history (authorised viewers)
 *  - Fleet managers: POST new allocation, POST close open period
 *  - GET /api/vehicles/{id}/availability, damage list, decommission
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const Money = window.MobilityCheckMoney;
	const UP = window.MobilityCheckUserPicker;
	const { fmtDate, fmtDateTime, isoForDateInput } = window.MobilityCheckDates;
	const t = M.t;

	function appRoot() {
		return document.querySelector('#app-content.mc-app') || document.querySelector('.mc-app');
	}

	function isLineManagerScopedReader() {
		const el = appRoot();
		return el?.dataset.mcLineManagerScopedReader === '1';
	}

	function isDriverUser() {
		const el = appRoot();
		return el?.dataset.mcIsDriver === '1';
	}

	function isFleetManager() {
		const el = appRoot();
		return el?.dataset.mcIsManager === '1';
	}

	function id() {
		const el = document.getElementById('mc-vehicle-id');
		return el ? parseInt(el.value, 10) : 0;
	}

	function row(dl, dt, dd) {
		dl.appendChild(C.h('dt', null, dt));
		dl.appendChild(C.h('dd', null, dd == null || dd === '' ? '—' : dd));
	}

	function modeLabel(m) {
		if (m === 'pool') return t('Pool (shared organisation fleet)');
		if (m === 'group') return t('Group (restricted driver list)');
		if (m === 'dedicated') return t('Dedicated (one driver — company car / Dienstwagen)');
		return m || '—';
	}

	function taxLabel(x) {
		if (x === 'business_only') return t('Business / commute only');
		if (x === 'one_percent_rule') return t('1% rule (list price on record)');
		if (x === 'logbook_method') return t('Fahrtenbuch (logbook — trip-by-trip)');
		return x || '—';
	}

	function describeActiveAssignment(a) {
		if (!a) return null;
		const bits = [modeLabel(a.assignment_mode), taxLabel(a.tax_treatment)];
		if (a.assignment_mode === 'dedicated' && a.assigned_user_id) {
			bits.push(t('Driver: {id}', { id: a.assigned_user_id }));
		}
		if (a.assignment_mode === 'group' && a.assigned_group_id) {
			bits.push(t('Group: {id}', { id: a.assigned_group_id }));
		}
		if (a.tax_treatment === 'one_percent_rule' && a.monthly_gross_list_price_minor != null) {
			bits.push(t('Monthly list price: {amount}', { amount: Money.format(a.monthly_gross_list_price_minor) }));
		}
		bits.push(t('Valid from {from}', { from: fmtDate(a.valid_from) }) + (a.valid_until ? ' · ' + t('until {to}', { to: fmtDate(a.valid_until) }) : ' · ' + t('open-ended')));
		return bits.join(' · ');
	}

	async function loadAssignments(vid) {
		const host = document.getElementById('mc-veh-assignments');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const rows = API.asArray(await API.get('/api/vehicle-assignments', { vehicleId: vid }));
			C.setLoading(host, false);
			const mgr = isFleetManager();
			const cols = [
				{ key: 'valid_from', label: t('Valid from'), render: (r) => fmtDate(r.valid_from) },
				{ key: 'valid_until', label: t('Valid until'), render: (r) => (r.valid_until ? fmtDate(r.valid_until) : t('Open (current)')) },
				{ key: 'assignment_mode', label: t('Mode'), render: (r) => modeLabel(r.assignment_mode) },
				{ key: 'tax_treatment', label: t('Tax method'), render: (r) => taxLabel(r.tax_treatment) },
				{
					key: 'assignee',
					label: t('Assigned to'),
					render: (r) => {
						if (r.assignment_mode === 'dedicated' && r.assigned_user_id) return r.assigned_user_id;
						if (r.assignment_mode === 'group' && r.assigned_group_id) return r.assigned_group_id;
						return '—';
					},
				},
				{
					key: 'list',
					label: t('List price / month'),
					render: (r) => (r.monthly_gross_list_price_minor != null ? Money.format(r.monthly_gross_list_price_minor) : '—'),
				},
				{ key: 'notes', label: t('Notes'), render: (r) => r.notes || '—' },
			];
			if (mgr) {
				cols.push({
					key: 'actions',
					label: t('Actions'),
					render: (r) => {
						if (!r.valid_until) {
							return C.h('button', {
								type: 'button',
								class: 'button',
								onClick: () => openCloseAssignmentDialog(r),
							}, t('End this period'));
						}
						return '—';
					},
				});
			}
			C.renderTable(host, cols, rows, { ariaLabel: t('Allocation history'), emptyHeading: t('No allocation rows yet. Defaults apply until you add one.') });
		} catch (e) {
			C.setLoading(host, false);
			host.innerHTML = '';
			host.appendChild(C.h('p', { class: 'mc-empty mc-empty--error', role: 'alert' }, t('Could not load allocation history.')));
		}
	}

	function openCloseAssignmentDialog(a) {
		const uid = 'mc-asg-close-' + Math.random().toString(36).slice(2, 8);
		const form = C.h('form', { class: 'mc-form' });
		const row = C.h('div', { class: 'mc-form-row' });
		row.appendChild(C.h('label', { for: uid + '-d' }, [t('End date'), C.h('span', { class: 'mc-required' }, '*')]));
		const inp = C.h('input', { type: 'date', id: uid + '-d', name: 'validUntil', required: true, class: 'mc-input' });
		row.appendChild(inp);
		row.appendChild(C.h('p', { class: 'mc-form-row__hint' }, t('The allocation ends on this calendar day. A new period usually starts the next day.')));
		form.appendChild(row);
		C.showDialog({
			title: t('End allocation period'),
			body: form,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Save'),
					primary: true,
					closeOnClick: false,
					onClick: async () => {
						const d = (inp.value || '').trim();
						if (!d) return;
						C.lockForm(form, true);
						try {
							await API.post('/api/vehicle-assignments/' + a.id + '/close', { validUntil: d });
							C.closeDialog();
							M.toast(t('Allocation period updated.'), 'success');
							load();
						} catch (e) {
							C.lockForm(form, false);
							M.reportError(e);
						}
					},
				},
			],
		});
	}

	function openNewAssignmentDialog() {
		const vid = id();
		const uid = 'mc-asg-new-' + Math.random().toString(36).slice(2, 8);
		const form = C.h('form', { class: 'mc-form' });
		const vfRow = C.h('div', { class: 'mc-form-row' });
		vfRow.appendChild(C.h('label', { for: uid + '-vf' }, [t('Valid from'), C.h('span', { class: 'mc-required' }, '*')]));
		const vf = C.h('input', { type: 'date', id: uid + '-vf', name: 'validFrom', required: true, class: 'mc-input', value: isoForDateInput(new Date()) });
		vfRow.appendChild(vf);
		vfRow.appendChild(C.h('p', { class: 'mc-form-row__hint' }, t('New rules apply from this date. Any current open allocation is closed the day before.')));
		form.appendChild(vfRow);

		const modeRow = C.h('div', { class: 'mc-form-row' });
		modeRow.appendChild(C.h('label', { for: uid + '-mode' }, [t('How the vehicle is shared'), C.h('span', { class: 'mc-required' }, '*')]));
		const modeSel = C.h('select', { id: uid + '-mode', name: 'assignmentMode', required: true, class: 'mc-input' }, [
			C.h('option', { value: 'pool' }, t('Pool (shared organisation fleet)')),
			C.h('option', { value: 'group' }, t('Group (restricted driver list)')),
			C.h('option', { value: 'dedicated' }, t('Dedicated (one driver — company car / Dienstwagen)')),
		]);
		modeRow.appendChild(modeSel);
		form.appendChild(modeRow);

		const taxRow = C.h('div', { class: 'mc-form-row' });
		taxRow.appendChild(C.h('label', { for: uid + '-tax' }, [t('Tax / payroll method (Germany)'), C.h('span', { class: 'mc-required' }, '*')]));
		const taxSel = C.h('select', { id: uid + '-tax', name: 'taxTreatment', required: true, class: 'mc-input' }, [
			C.h('option', { value: 'business_only' }, t('Business / commute only')),
			C.h('option', { value: 'one_percent_rule' }, t('1% rule (list price on record)')),
			C.h('option', { value: 'logbook_method' }, t('Fahrtenbuch (logbook — trip-by-trip)')),
		]);
		taxRow.appendChild(taxSel);
		taxRow.appendChild(C.h('p', { class: 'mc-form-row__hint' }, t('This is an operational flag for MobilityCheck workflows (check-out declaration, logbook entry, exports). Your payroll or tax adviser decides the legal choice.')));
		form.appendChild(taxRow);

		const userWrap = C.h('div', { class: 'mc-form-row', id: uid + '-user-wrap', hidden: true });
		userWrap.appendChild(C.h('label', { for: uid + '-um' }, [t('Dedicated driver (Nextcloud account)'), C.h('span', { class: 'mc-required' }, '*')]));
		const userMount = C.h('div', { id: uid + '-um' });
		userWrap.appendChild(userMount);
		userWrap.appendChild(C.h('p', { class: 'mc-form-row__hint' }, t('The person must already hold the “driver” role in MobilityCheck.')));
		form.appendChild(userWrap);

		const grpRow = C.h('div', { class: 'mc-form-row', id: uid + '-grp-wrap', hidden: true });
		grpRow.appendChild(C.h('label', { for: uid + '-grp' }, [t('Nextcloud group ID'), C.h('span', { class: 'mc-required' }, '*')]));
		const grpIn = C.h('input', { type: 'text', id: uid + '-grp', name: 'assignedGroupId', class: 'mc-input', maxlength: 64, autocomplete: 'off' });
		grpRow.appendChild(grpIn);
		grpRow.appendChild(C.h('p', { class: 'mc-form-row__hint' }, t('Must match an existing Nextcloud group. Members may book this vehicle when in group mode.')));
		form.appendChild(grpRow);

		const priceRow = C.h('div', { class: 'mc-form-row', id: uid + '-price-wrap', hidden: true });
		priceRow.appendChild(C.h('label', { for: uid + '-price' }, [t('Monthly gross list price (Bruttolistenpreis)'), C.h('span', { class: 'mc-required' }, '*')]));
		const priceIn = C.h('input', { type: 'text', id: uid + '-price', name: 'monthlyGrossListPrice', class: 'mc-input', inputmode: 'decimal' });
		priceRow.appendChild(priceIn);
		priceRow.appendChild(C.h('p', { class: 'mc-form-row__hint' }, t('Required for the 1% rule. Enter gross amount including VAT in your currency (e.g. 45.000,00).')));
		form.appendChild(priceRow);

		const notesRow = C.h('div', { class: 'mc-form-row' });
		notesRow.appendChild(C.h('label', { for: uid + '-notes' }, t('Internal notes (optional)')));
		const notesTa = C.h('textarea', { id: uid + '-notes', name: 'notes', rows: 2, maxlength: 4000, class: 'mc-input' });
		notesRow.appendChild(notesTa);
		form.appendChild(notesRow);

		function syncAssignmentFields() {
			const mode = modeSel.value;
			userWrap.hidden = mode !== 'dedicated';
			grpRow.hidden = mode !== 'group';
			const tax = taxSel.value;
			priceRow.hidden = tax !== 'one_percent_rule';
		}
		modeSel.addEventListener('change', syncAssignmentFields);
		taxSel.addEventListener('change', syncAssignmentFields);

		if (UP && typeof UP.attachUserCombobox === 'function') {
			UP.attachUserCombobox(userMount, {
				name: 'assignedUserId',
				idBase: uid + '-drv',
				required: false,
				filterRow: (row) => Array.isArray(row.roles) && row.roles.indexOf('driver') >= 0,
			});
		}

		syncAssignmentFields();

		C.showDialog({
			title: t('New allocation period'),
			body: form,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Create'),
					primary: true,
					closeOnClick: false,
					onClick: async () => {
						C.clearErrors(form);
						const mode = modeSel.value;
						const tax = taxSel.value;
						const raw = C.collectForm(form);
						const payload = {
							vehicleId: vid,
							assignmentMode: mode,
							taxTreatment: tax,
							validFrom: (raw.validFrom || '').trim(),
							notes: (raw.notes || '').trim() || null,
						};
						if (mode === 'dedicated') {
							const hid = form.querySelector('input[name="assignedUserId"]');
							const uidVal = (hid && hid.value) ? String(hid.value).trim() : '';
							if (!uidVal) {
								M.reportError({ code: 'VALIDATION_FAILED', context: { field: 'assigned_user_id' } });
								return;
							}
							payload.assignedUserId = uidVal;
						}
						if (mode === 'group') {
							const gid = (grpIn.value || '').trim();
							if (!gid) {
								M.reportError({ code: 'VALIDATION_FAILED', context: { field: 'assigned_group_id' } });
								return;
							}
							payload.assignedGroupId = gid;
						}
						if (tax === 'one_percent_rule') {
							const minor = Money.parseToMinor((priceIn.value || '').trim());
							if (minor == null || !Number.isFinite(minor) || minor <= 0) {
								const rowEl = priceRow;
								rowEl.classList.add('has-error');
								const err = rowEl.querySelector('.mc-form-row__error') || C.h('p', { class: 'mc-form-row__error', role: 'alert' });
								if (!rowEl.querySelector('.mc-form-row__error')) rowEl.appendChild(err);
								err.textContent = t('Enter a positive monthly list price.');
								return;
							}
							payload.monthlyGrossListPriceMinor = minor;
						}
						C.lockForm(form, true);
						try {
							await API.post('/api/vehicle-assignments', payload);
							C.closeDialog();
							M.toast(t('Allocation created.'), 'success');
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

	async function load() {
		const vid = id();
		if (!vid) return;
		const dl = document.getElementById('mc-veh-dl');
		C.setLoading(dl, true);
		try {
			const v = await API.get('/api/vehicles/' + vid);
			document.querySelectorAll('[data-mc-bind="internal_name"]').forEach((el) => { el.textContent = v.internal_name; });
			document.querySelectorAll('[data-mc-bind="model"]').forEach((el) => { el.textContent = [v.make, v.model, v.year].filter(Boolean).join(' '); });
			dl.innerHTML = '';
			const badge = C.statusBadge(v.status);
			row(dl, t('Status'), badge);
			row(dl, t('Licence plate'), v.licence_plate);
			row(dl, t('Required licence class'), v.required_licence_class);
			row(dl, t('Fuel'), v.fuel_type);
			row(dl, t('Transmission'), v.transmission);
			row(dl, t('Seating capacity'), v.seating_capacity);
			row(dl, t('Colour'), v.colour);
			row(dl, t('Base location'), v.base_location);
			row(dl, t('Odometer'), (v.odometer_km ?? 0).toLocaleString() + ' km');
			row(dl, t('Insurance expiry'), v.insurance_expiry_date ? fmtDate(v.insurance_expiry_date) : null);
			row(dl, t('Road tax expiry'), v.road_tax_expiry_date ? fmtDate(v.road_tax_expiry_date) : null);
			row(dl, t('Next service'), v.next_service_date ? fmtDate(v.next_service_date) + (v.next_service_odometer_km ? ' / ' + v.next_service_odometer_km.toLocaleString() + ' km' : '') : null);
			row(dl, t('Insurance policy'), v.insurance_policy_number);
			row(dl, t('Notes'), v.notes);
			row(dl, t('Lease start'), v.lease_start_date ? fmtDate(v.lease_start_date) : null);
			row(dl, t('Lease end'), v.lease_end_date ? fmtDate(v.lease_end_date) : null);
			row(dl, t('Lease included km'), v.lease_included_km != null ? String(v.lease_included_km) : null);
			row(dl, t('Lease reference'), v.lease_reference);
			row(dl, t('Automatic allocation'), v.do_not_auto_allocate ? t('Excluded from automatic allocation') : t('Eligible for automatic allocation'));
			const activeTxt = describeActiveAssignment(v.active_assignment);
			row(dl, t('Current allocation (summary)'), activeTxt || t('No explicit row — default pool assignment from vehicle creation applies.'));

			await Promise.all([loadAvailability(vid), loadDamage(vid), loadAssignments(vid)]);
			M.polite(t('Vehicle details loaded.'));
		} catch (e) {
			M.reportError(e);
		} finally {
			C.setLoading(dl, false);
		}
	}

	async function loadAvailability(vid) {
		const host = document.getElementById('mc-veh-bookings');
		if (!host) return;
		const today = isoForDateInput(new Date());
		const ahead = new Date();
		ahead.setDate(ahead.getDate() + 90);
		const to = isoForDateInput(ahead);
		C.setLoading(host, true);
		try {
			const rows = API.asArray(await API.get('/api/vehicles/' + vid + '/availability', { from: today, to }));
			C.setLoading(host, false);
			const currentUid = appRoot()?.dataset.mcCurrentUser || '';
			const cols = [
				{ key: 'start', label: t('From'), render: (r) => fmtDateTime(r.start) },
				{ key: 'end', label: t('To'), render: (r) => fmtDateTime(r.end) },
			];
			if (isLineManagerScopedReader() && !isDriverUser()) {
				cols.push({
					key: 'driverUserId',
					label: t('Driver (account)'),
					render: (r) => {
						const uid = r.driverUserId || '';
						if (uid && uid === currentUid) return t('You');
						return uid || '—';
					},
				});
			}
			cols.push(
				{ key: 'status', label: t('Status'), render: (r) => C.statusBadge(r.status) },
				{ key: 'actions', label: t('Booking'), render: (r) => C.h('a', { class: 'mc-button-link', href: C.detailUrl('bookings', r.bookingId) }, '#' + r.bookingId) }
			);
			C.renderTable(host, cols, rows, { ariaLabel: t('Upcoming reserved windows'), emptyHeading: t('No reservations in the next 90 days.') });
		} catch (e) {
			C.setLoading(host, false);
			M.reportError(e);
		}
	}

	async function loadDamage(vid) {
		const host = document.getElementById('mc-veh-damage');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const rows = API.asArray(await API.get('/api/damage-reports', { vehicleId: vid }));
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'discoveryDatetime', label: t('Discovered'), render: (r) => fmtDateTime(r.discovery_datetime || r.discoveryDatetime) },
				{ key: 'severity', label: t('Severity'), render: (r) => C.statusBadge(r.severity, 'severity') },
				{ key: 'status', label: t('Status'), render: (r) => C.statusBadge(r.status) },
				{ key: 'description', label: t('Description'), render: (r) => r.description || '—' },
				{ key: 'actions', label: t('Report'), render: (r) => C.h('a', { class: 'mc-button-link', href: C.detailUrl('damage', r.id) }, '#' + r.id) },
			], rows, { ariaLabel: t('Damage reports'), emptyHeading: t('No damage reports on file.') });
		} catch (e) {
			C.setLoading(host, false);
			M.reportError(e);
		}
	}

	function decommission() {
		const reasonFieldId = 'mc-decom-reason-' + Math.random().toString(36).slice(2, 9);
		const hintId = reasonFieldId + '-hint';
		const errId = reasonFieldId + '-err';
		const intro = C.h('p', { class: 'mc-section__sub' }, t('Are you sure you want to decommission this vehicle? Future approved bookings will be cancelled. This cannot be undone.'));
		const errEl = C.h('p', { class: 'mc-form-row__error', id: errId, 'aria-live': 'polite' });
		const ta = C.h('textarea', {
			id: reasonFieldId,
			name: 'reason',
			class: 'mc-input',
			rows: 4,
			maxlength: 8000,
			'aria-required': 'true',
			'aria-invalid': 'false',
			'aria-describedby': hintId + ' ' + errId,
		});
		const formRow = C.h('div', { class: 'mc-form-row' }, [
			C.h('label', { for: reasonFieldId, class: 'mc-form-row__label' }, t('Reason for decommissioning (visible in audit log):')),
			ta,
			C.h('p', { class: 'mc-form-row__hint', id: hintId }, t('Describe why this vehicle is being decommissioned. This text is stored in the audit log.')),
			errEl,
		]);
		const body = C.h('div', { class: 'mc-dialog__body-stack' }, [intro, formRow]);
		let submitting = false;
		const backdrop = C.showDialog({
			title: t('Decommission vehicle'),
			closeOnBackdropClick: false,
			body,
			actions: [
				{
					label: t('Keep active'),
					onClick: () => {
						if (submitting) return;
					},
				},
				{
					label: t('Decommission'),
					danger: true,
					closeOnClick: false,
					onClick: () => {
						void submitDecommission();
					},
				},
			],
		});
		function setBusy(on) {
			submitting = !!on;
			backdrop.querySelectorAll('.mc-form-actions button').forEach((btn) => {
				btn.disabled = !!on;
			});
			ta.disabled = !!on;
		}
		async function submitDecommission() {
			if (submitting) return;
			const reason = (ta.value || '').trim();
			if (!reason) {
				formRow.classList.add('has-error');
				errEl.textContent = t('A reason is required.');
				ta.setAttribute('aria-invalid', 'true');
				ta.focus();
				return;
			}
			formRow.classList.remove('has-error');
			errEl.textContent = '';
			ta.setAttribute('aria-invalid', 'false');
			setBusy(true);
			try {
				const data = await API.post('/api/vehicles/' + id() + '/decommission', { reason });
				C.closeDialog();
				M.toast(t('Vehicle decommissioned. {n} booking(s) cancelled.', { n: (data.cancelledBookingIds || []).length }), 'warning');
				load();
			} catch (e) {
				M.reportError(e);
			} finally {
				setBusy(false);
			}
		}
	}

	function openVehicleEditDialog() {
		if (!isFleetManager()) return;
		const vid = id();
		const uid = 'mc-ved-' + Math.random().toString(36).slice(2, 8);
		void (async () => {
			let v;
			try {
				v = await API.get('/api/vehicles/' + vid);
			} catch (e) {
				M.reportError(e);
				return;
			}
			const form = C.h('form', { class: 'mc-form', novalidate: true });
			const grid = C.h('div', { class: 'mc-grid-2' });
			function addField(name, label, input, required) {
				const wrap = C.h('div', { class: 'mc-form-row' });
				wrap.appendChild(C.h('label', { for: uid + '-' + name }, [
					label,
					required ? C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*') : null,
				]));
				wrap.appendChild(input);
				wrap.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
				grid.appendChild(wrap);
			}
			function textInput(name, val, extra = {}) {
				return C.h('input', Object.assign({
					id: uid + '-' + name, name, class: 'mc-input', type: 'text',
					value: val != null ? String(val) : '',
				}, extra));
			}
			function numInput(name, val, min, max) {
				return C.h('input', {
					id: uid + '-' + name, name, class: 'mc-input', type: 'number',
					value: val != null ? String(val) : '',
					min, max,
				});
			}
			addField('internal_name', t('Internal name'), textInput('internal_name', v.internal_name, { required: true, maxlength: 120 }), true);
			addField('licence_plate', t('Licence plate'), textInput('licence_plate', v.licence_plate, { required: true, maxlength: 20 }), true);
			addField('make', t('Make'), textInput('make', v.make, { required: true, maxlength: 80 }), true);
			addField('model', t('Model'), textInput('model', v.model, { required: true, maxlength: 80 }), true);
			addField('year', t('Year'), numInput('year', v.year, 1980, 2100), true);
			addField('colour', t('Colour'), textInput('colour', v.colour, { maxlength: 40 }), false);
			addField('seating_capacity', t('Seating capacity'), numInput('seating_capacity', v.seating_capacity, 1, 50), true);
			addField('required_licence_class', t('Required licence class'), textInput('required_licence_class', v.required_licence_class, { maxlength: 20 }), true);
			addField('base_location', t('Base location'), textInput('base_location', v.base_location, { maxlength: 120 }), false);
			addField('odometer_km', t('Odometer (km)'), numInput('odometer_km', v.odometer_km, 0, 20000000), true);

			const stRow = C.h('div', { class: 'mc-form-row' });
			stRow.appendChild(C.h('label', { for: uid + '-status' }, [t('Status'), C.h('span', { class: 'mc-required' }, '*')]));
			const stSel = C.h('select', { id: uid + '-status', name: 'status', class: 'mc-input', required: true });
			['available', 'booked', 'in_use', 'in_maintenance', 'decommissioned'].forEach((s) => {
				stSel.appendChild(C.h('option', { value: s }, s));
			});
			stSel.value = String(v.status || 'available');
			stRow.appendChild(stSel);
			stRow.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
			grid.appendChild(stRow);

			const fuelRow = C.h('div', { class: 'mc-form-row' });
			fuelRow.appendChild(C.h('label', { for: uid + '-fuel' }, [t('Fuel type'), C.h('span', { class: 'mc-required' }, '*')]));
			const fuelSel = C.h('select', { id: uid + '-fuel', name: 'fuel_type', required: true, class: 'mc-input' });
			[
				['petrol', t('Petrol')], ['diesel', t('Diesel')], ['electric', t('Electric')],
				['hybrid_petrol', t('Hybrid (petrol)')], ['hybrid_diesel', t('Hybrid (diesel)')], ['lpg', t('LPG')],
			].forEach(([val, lab]) => {
				fuelSel.appendChild(C.h('option', { value: val }, lab));
			});
			fuelSel.value = String(v.fuel_type || 'petrol');
			fuelRow.appendChild(fuelSel);
			fuelRow.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
			grid.appendChild(fuelRow);

			const txRow = C.h('div', { class: 'mc-form-row' });
			txRow.appendChild(C.h('label', { for: uid + '-tx' }, [t('Transmission'), C.h('span', { class: 'mc-required' }, '*')]));
			const txSel = C.h('select', { id: uid + '-tx', name: 'transmission', required: true, class: 'mc-input' });
			[['manual', t('Manual')], ['automatic', t('Automatic')]].forEach(([val, lab]) => {
				txSel.appendChild(C.h('option', { value: val }, lab));
			});
			txSel.value = String(v.transmission || 'manual');
			txRow.appendChild(txSel);
			txRow.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
			grid.appendChild(txRow);

			addField('insurance_policy_number', t('Insurance policy number'), textInput('insurance_policy_number', v.insurance_policy_number, { maxlength: 80 }), false);
			addField('insurance_expiry_date', t('Insurance expiry'), C.h('input', {
				id: uid + '-insurance_expiry_date', name: 'insurance_expiry_date', type: 'date', class: 'mc-input',
				value: v.insurance_expiry_date ? isoForDateInput(v.insurance_expiry_date) : '',
			}), false);
			addField('road_tax_expiry_date', t('Road tax expiry'), C.h('input', {
				id: uid + '-road_tax_expiry_date', name: 'road_tax_expiry_date', type: 'date', class: 'mc-input',
				value: v.road_tax_expiry_date ? isoForDateInput(v.road_tax_expiry_date) : '',
			}), false);
			addField('next_service_date', t('Next service date'), C.h('input', {
				id: uid + '-next_service_date', name: 'next_service_date', type: 'date', class: 'mc-input',
				value: v.next_service_date ? isoForDateInput(v.next_service_date) : '',
			}), false);
			addField('next_service_odometer_km', t('Next service odometer (km)'), numInput('next_service_odometer_km', v.next_service_odometer_km, 0, 20000000), false);

			addField('lease_start_date', t('Lease start'), C.h('input', {
				id: uid + '-lease_start_date', name: 'lease_start_date', type: 'date', class: 'mc-input',
				value: v.lease_start_date ? isoForDateInput(v.lease_start_date) : '',
			}), false);
			addField('lease_end_date', t('Lease end'), C.h('input', {
				id: uid + '-lease_end_date', name: 'lease_end_date', type: 'date', class: 'mc-input',
				value: v.lease_end_date ? isoForDateInput(v.lease_end_date) : '',
			}), false);
			addField('lease_included_km', t('Lease included km'), numInput('lease_included_km', v.lease_included_km, 0, 10000000), false);
			addField('lease_reference', t('Lease reference'), textInput('lease_reference', v.lease_reference, { maxlength: 120 }), false);

			const dnaRow = C.h('div', { class: 'mc-form-row mc-form-row--checkbox' });
			dnaRow.appendChild(C.h('label', { class: 'mc-checkbox-row', for: uid + '-dna' }, [
				C.h('input', { type: 'checkbox', id: uid + '-dna', name: 'do_not_auto_allocate', value: '1', checked: !!v.do_not_auto_allocate }),
				C.h('span', null, t('Exclude from automatic allocation')),
			]));
			dnaRow.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
			grid.appendChild(dnaRow);

			form.appendChild(grid);
			const notesRow = C.h('div', { class: 'mc-form-row' });
			notesRow.appendChild(C.h('label', { for: uid + '-notes' }, t('Notes')));
			notesRow.appendChild(C.h('textarea', { id: uid + '-notes', name: 'notes', rows: 4, class: 'mc-input', maxlength: 8000 }, v.notes || ''));
			notesRow.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
			form.appendChild(notesRow);

			C.showDialog({
				title: t('Edit vehicle'),
				body: form,
				actions: [
					{ label: t('Cancel') },
					{
						label: t('Save'),
						primary: true,
						closeOnClick: false,
						onClick: async () => {
							C.clearErrors(form);
							const raw = C.collectForm(form);
							const payload = Object.assign({}, raw, {
								year: parseInt(raw.year, 10),
								seating_capacity: parseInt(raw.seating_capacity, 10),
								odometer_km: parseInt(raw.odometer_km, 10),
								do_not_auto_allocate: !!(form.elements.do_not_auto_allocate && form.elements.do_not_auto_allocate.checked),
							});
							if (raw.next_service_odometer_km !== '' && raw.next_service_odometer_km != null) {
								payload.next_service_odometer_km = parseInt(raw.next_service_odometer_km, 10);
							} else {
								payload.next_service_odometer_km = null;
							}
							if (raw.lease_included_km !== '' && raw.lease_included_km != null) {
								payload.lease_included_km = parseInt(raw.lease_included_km, 10);
							} else {
								payload.lease_included_km = null;
							}
							['insurance_expiry_date', 'road_tax_expiry_date', 'next_service_date', 'lease_start_date', 'lease_end_date'].forEach((k) => {
								if (!raw[k]) payload[k] = null;
							});
							C.lockForm(form, true);
							try {
								await API.put('/api/vehicles/' + vid, payload);
								C.closeDialog();
								M.toast(t('Vehicle updated.'), 'success');
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
		})();
	}

	function init() {
		C.bootstrap();
		document.getElementById('mc-veh-decom')?.addEventListener('click', decommission);
		document.getElementById('mc-veh-assign-new')?.addEventListener('click', openNewAssignmentDialog);
		document.getElementById('mc-veh-edit')?.addEventListener('click', () => { openVehicleEditDialog(); });
		load();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
