/**
 * Maintenance schedules page.
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const { fmtDate } = window.MobilityCheckDates;
	const t = M.t;

	const filters = { vehicleId: '', blockingOnly: '' };

	function dueBadge(r) {
		const today = new Date(); today.setHours(0, 0, 0, 0);
		if (r.next_due_date) {
			const d = new Date(r.next_due_date + 'T00:00:00Z');
			if (d < today) {
				return C.h('span', {
					class: 'mc-status-badge',
					'data-status': 'cancelled',
					'aria-label': t('Overdue — maintenance date has passed'),
				}, t('Overdue'));
			}
		}
		return C.h('span', {
			class: 'mc-status-badge',
			'data-status': 'available',
			'aria-label': t('On schedule — maintenance not overdue'),
		}, t('On schedule'));
	}

	async function load() {
		const host = document.getElementById('mc-mnt-list');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const rows = await API.get('/api/maintenance-schedules', filters);
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'name', label: t('Schedule') },
				{ key: 'vehicle', label: t('Vehicle'), render: (r) => {
					const name = r.vehicle_internal_name || ('#' + r.vehicle_id);
					return name + (r.vehicle_licence_plate ? ' · ' + r.vehicle_licence_plate : '');
				} },
				{ key: 'trigger_type', label: t('Trigger') },
				{ key: 'next_due_date', label: t('Due date'), render: (r) => r.next_due_date ? fmtDate(r.next_due_date) : '—' },
				{ key: 'next_due_odometer_km', label: t('Due odometer'), num: true, render: (r) => r.next_due_odometer_km != null ? r.next_due_odometer_km.toLocaleString() + ' km' : '—' },
				{ key: 'is_blocking', label: t('Blocking'), render: (r) => r.is_blocking ? t('Yes') : t('No') },
				{ key: 'state', label: t('State'), render: dueBadge },
				{ key: 'actions', label: t('Actions'), render: (r) => C.h('button', { type: 'button', class: 'button', onClick: () => complete(r.id) }, t('Mark complete')) },
			], rows, { ariaLabel: t('Maintenance schedules'), emptyHeading: t('No maintenance schedules defined yet.') });
		} catch (e) {
			C.setLoading(host, false);
			M.reportError(e);
		}
	}

	function complete(scheduleId) {
		const form = C.h('form', { class: 'mc-form' });
		const row = C.h('div', { class: 'mc-form-row' });
		row.appendChild(C.h('label', { for: 'mc-m-complete-date' }, [t('Completed date (YYYY-MM-DD)'), C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*')]));
		const inp = C.h('input', {
			id: 'mc-m-complete-date',
			name: 'completedDate',
			type: 'date',
			required: true,
			value: new Date().toISOString().slice(0, 10),
		});
		row.appendChild(inp);
		row.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		form.appendChild(row);
		C.showDialog({
			title: t('Mark maintenance complete'),
			body: form,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Mark complete'),
					primary: true,
					closeOnClick: false,
					onClick: async () => {
						C.clearErrors(form);
						const completedDate = (inp.value || '').trim();
						if (!/^\d{4}-\d{2}-\d{2}$/.test(completedDate)) {
							C.applyFieldError(form, 'completedDate', t('Please use the date picker.'));
							return;
						}
						C.lockForm(form, true);
						try {
							await API.post('/api/maintenance-schedules/' + scheduleId + '/complete', { completedDate });
							C.closeDialog();
							M.toast(t('Maintenance recorded as complete.'), 'success');
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

	async function openCreateDialog() {
		const vehicles = await API.get('/api/vehicles', { activeOnly: 1 });
		const form = C.h('form', { class: 'mc-form' });
		const grid = C.h('div', { class: 'mc-grid-2' });

		const v = C.h('div', { class: 'mc-form-row' });
		v.appendChild(C.h('label', { for: 'mc-m-v' }, [t('Vehicle'), C.h('span', { class: 'mc-required' }, '*')]));
		const vs = C.h('select', { id: 'mc-m-v', name: 'vehicleId', required: true });
		vehicles.forEach((vv) => vs.appendChild(C.h('option', { value: vv.id }, vv.internal_name + ' · ' + vv.licence_plate)));
		v.appendChild(vs);
		v.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		grid.appendChild(v);

		const name = C.h('div', { class: 'mc-form-row' });
		name.appendChild(C.h('label', { for: 'mc-m-n' }, [t('Name'), C.h('span', { class: 'mc-required' }, '*')]));
		name.appendChild(C.h('input', { id: 'mc-m-n', name: 'name', required: true, maxlength: 120 }));
		grid.appendChild(name);

		const trig = C.h('div', { class: 'mc-form-row' });
		trig.appendChild(C.h('label', { for: 'mc-m-t' }, [t('Trigger'), C.h('span', { class: 'mc-required' }, '*')]));
		const ts = C.h('select', { id: 'mc-m-t', name: 'triggerType', required: true }, [
			C.h('option', { value: 'calendar' }, t('Calendar interval')),
			C.h('option', { value: 'odometer' }, t('Odometer interval')),
			C.h('option', { value: 'both' }, t('Whichever comes first')),
		]);
		trig.appendChild(ts);
		grid.appendChild(trig);

		const calM = C.h('div', { class: 'mc-form-row' });
		calM.appendChild(C.h('label', { for: 'mc-m-ci' }, t('Calendar interval (months)')));
		calM.appendChild(C.h('input', { id: 'mc-m-ci', name: 'calendarIntervalMonths', type: 'number', min: 1, max: 120 }));
		grid.appendChild(calM);

		const odoI = C.h('div', { class: 'mc-form-row' });
		odoI.appendChild(C.h('label', { for: 'mc-m-oi' }, t('Odometer interval (km)')));
		odoI.appendChild(C.h('input', { id: 'mc-m-oi', name: 'odometerIntervalKm', type: 'number', min: 100, max: 200000 }));
		grid.appendChild(odoI);

		const due = C.h('div', { class: 'mc-form-row' });
		due.appendChild(C.h('label', { for: 'mc-m-nd' }, [t('Next due date'), C.h('span', { class: 'mc-required' }, '*')]));
		due.appendChild(C.h('input', { id: 'mc-m-nd', name: 'nextDueDate', type: 'date', required: true }));
		grid.appendChild(due);

		const dueOdo = C.h('div', { class: 'mc-form-row' });
		dueOdo.appendChild(C.h('label', { for: 'mc-m-ndo' }, t('Next due odometer (km)')));
		dueOdo.appendChild(C.h('input', { id: 'mc-m-ndo', name: 'nextDueOdometerKm', type: 'number', min: 0 }));
		grid.appendChild(dueOdo);

		const blocking = C.h('div', { class: 'mc-form-row mc-form-row--checkbox' });
		blocking.appendChild(C.h('label', { class: 'mc-checkbox-row', for: 'mc-m-b' }, [
			C.h('input', { type: 'checkbox', id: 'mc-m-b', name: 'isBlocking', value: '1' }),
			C.h('span', null, t('Block new bookings when overdue')),
		]));
		grid.appendChild(blocking);

		form.appendChild(grid);
		C.showDialog({
			title: t('Add maintenance schedule'),
			body: form,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Save'), primary: true, closeOnClick: false,
					onClick: async () => {
						C.clearErrors(form);
						const raw = C.collectForm(form);
						raw.vehicleId = parseInt(raw.vehicleId, 10);
						if (raw.calendarIntervalMonths) raw.calendarIntervalMonths = parseInt(raw.calendarIntervalMonths, 10);
						if (raw.odometerIntervalKm) raw.odometerIntervalKm = parseInt(raw.odometerIntervalKm, 10);
						if (raw.nextDueOdometerKm) raw.nextDueOdometerKm = parseInt(raw.nextDueOdometerKm, 10);
						raw.isBlocking = !!raw.isBlocking;
						C.lockForm(form, true);
						try {
							await API.post('/api/maintenance-schedules', raw);
							C.closeDialog();
							M.toast(t('Schedule created.'), 'success');
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
		const f = document.getElementById('mc-mnt-filters');
		await C.fillVehicleFilterSelect(document.getElementById('mc-mnt-veh'), t);
		C.applySearchParamsToFilters(filters, f);
		f?.addEventListener('change', (ev) => { filters[ev.target.name] = ev.target.value || ''; load(); });
		document.getElementById('mc-mnt-new')?.addEventListener('click', openCreateDialog);
		load();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { void init(); });
	else void init();
})();
