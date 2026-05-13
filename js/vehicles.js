/**
 * Vehicles list page.
 *
 *  - Loads /api/vehicles with filters
 *  - Renders the table + mobile card list
 *  - Opens a dialog with the "create vehicle" form (managers only)
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const { fmtDate } = window.MobilityCheckDates;
	const t = M.t;

	let lastFilters = { activeOnly: '1', status: '' };

	function vehicleLabel(v) {
		return v.internal_name || ('#' + v.id);
	}

	function openVehicleDetailsButton(v) {
		const name = vehicleLabel(v);
		const url = C.detailUrl('vehicles', v.id);
		return C.h('a', {
			class: 'button',
			href: url,
			'aria-label': t('Open vehicle details ({name})', { name }),
		}, t('Open'));
	}

	async function load() {
		const host = document.getElementById('mc-veh-list');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const rows = API.asArray(await API.get('/api/vehicles', lastFilters));
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'internal_name', label: t('Vehicle'), render: (r) => vehicleLabel(r) },
				{ key: 'licence_plate', label: t('Plate'), render: (r) => r.licence_plate || '—' },
				{ key: 'model', label: t('Make / model'), render: (r) => [r.make, r.model].filter(Boolean).join(' ') || '—' },
				{ key: 'required_licence_class', label: t('Required class'), render: (r) => r.required_licence_class || '—' },
				{ key: 'status', label: t('Status'), render: (r) => C.statusBadge(r.status) },
				{ key: 'odometer_km', label: t('Odometer'), num: true, render: (r) => (r.odometer_km != null ? r.odometer_km.toLocaleString() + ' km' : '—') },
				{ key: 'insurance_expiry_date', label: t('Insurance expires'), render: (r) => r.insurance_expiry_date ? fmtDate(r.insurance_expiry_date) : '—' },
				{ key: 'actions', label: t('Actions'), actions: true, render: openVehicleDetailsButton },
			], rows, { emptyHeading: t('No vehicles found'), emptyHint: t('Adjust your filters or add a vehicle from the action above.'), ariaLabel: t('Vehicles') });
		} catch (e) {
			C.setLoading(host, false);
			M.reportError(e);
		}
	}

	function openCreateDialog() {
		const form = C.h('form', { class: 'mc-form', novalidate: true });
		const grid = C.h('div', { class: 'mc-grid-2' });
		const fields = [
			{ name: 'internal_name', label: t('Internal name'), required: true, hint: t('A short label used on dashboards, e.g. “Pool 1”.') },
			{ name: 'licence_plate', label: t('Licence plate'), required: true, hint: t('Will be uppercased and validated.') },
			{ name: 'make', label: t('Make'), required: true },
			{ name: 'model', label: t('Model'), required: true },
			{ name: 'year', label: t('Year'), required: true, type: 'number', min: 1980, max: 2100 },
			{ name: 'colour', label: t('Colour'), required: false },
			{ name: 'seating_capacity', label: t('Seating capacity'), required: true, type: 'number', min: 1, max: 99 },
			{ name: 'required_licence_class', label: t('Required licence class'), required: true, hint: t('e.g. B for cars, C1 / C for trucks, BE for trailer.') },
			{ name: 'base_location', label: t('Base location'), required: false, hint: t('Usual stand (e.g. car park, level, bay). Drivers see this when booking; for pool and group cars the exact spot is updated from each check-in return note.') },
			{ name: 'odometer_km', label: t('Odometer (km)'), required: true, type: 'number', min: 0 },
			{ name: 'insurance_policy_number', label: t('Insurance policy number'), required: false },
			{ name: 'insurance_expiry_date', label: t('Insurance expiry'), required: false, type: 'date' },
			{ name: 'road_tax_expiry_date', label: t('Road tax expiry'), required: false, type: 'date' },
			{ name: 'next_service_date', label: t('Next service date'), required: false, type: 'date' },
			{ name: 'next_service_odometer_km', label: t('Next service odometer (km)'), required: false, type: 'number', min: 0 },
			{ name: 'lease_start_date', label: t('Lease start'), required: false, type: 'date' },
			{ name: 'lease_end_date', label: t('Lease end'), required: false, type: 'date' },
			{ name: 'lease_included_km', label: t('Lease included km'), required: false, type: 'number', min: 0 },
			{ name: 'lease_reference', label: t('Lease reference'), required: false, hint: t('Contract number (optional).') },
		];
		fields.forEach((f) => {
			const row = C.h('div', { class: 'mc-form-row' });
			row.appendChild(C.h('label', { for: 'mc-vf-' + f.name }, [
				f.label, f.required ? C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*') : null,
			]));
			row.appendChild(C.h('input', { id: 'mc-vf-' + f.name, name: f.name, type: f.type || 'text', required: f.required, min: f.min, max: f.max }));
			if (f.hint) row.appendChild(C.h('p', { class: 'mc-form-row__hint' }, f.hint));
			row.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
			grid.appendChild(row);
		});

		const selFuel = C.h('div', { class: 'mc-form-row' });
		selFuel.appendChild(C.h('label', { for: 'mc-vf-fuel_type' }, [t('Fuel type'), C.h('span', { class: 'mc-required' }, '*')]));
		const fs = C.h('select', { id: 'mc-vf-fuel_type', name: 'fuel_type', required: true }, [
			['petrol', t('Petrol')], ['diesel', t('Diesel')], ['electric', t('Electric')],
			['hybrid_petrol', t('Hybrid (petrol)')], ['hybrid_diesel', t('Hybrid (diesel)')], ['lpg', t('LPG')],
		].map(([v, l]) => C.h('option', { value: v }, l)));
		selFuel.appendChild(fs);
		selFuel.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		grid.appendChild(selFuel);

		const selTx = C.h('div', { class: 'mc-form-row' });
		selTx.appendChild(C.h('label', { for: 'mc-vf-transmission' }, [t('Transmission'), C.h('span', { class: 'mc-required' }, '*')]));
		const ts = C.h('select', { id: 'mc-vf-transmission', name: 'transmission', required: true }, [
			['manual', t('Manual')], ['automatic', t('Automatic')],
		].map(([v, l]) => C.h('option', { value: v }, l)));
		selTx.appendChild(ts);
		selTx.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		grid.appendChild(selTx);

		const dna = C.h('div', { class: 'mc-form-row mc-form-row--checkbox' });
		dna.appendChild(C.h('label', { class: 'mc-checkbox-row', for: 'mc-vf-do_not_auto_allocate' }, [
			C.h('input', { type: 'checkbox', id: 'mc-vf-do_not_auto_allocate', name: 'do_not_auto_allocate', value: '1' }),
			C.h('span', null, t('Exclude from automatic allocation')),
		]));
		dna.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		grid.appendChild(dna);

		form.appendChild(grid);
		const notesRow = C.h('div', { class: 'mc-form-row' });
		notesRow.appendChild(C.h('label', { for: 'mc-vf-notes' }, t('Notes')));
		notesRow.appendChild(C.h('textarea', { id: 'mc-vf-notes', name: 'notes', rows: 8 }));
		form.appendChild(notesRow);

		C.showDialog({
			title: t('Add vehicle'),
			body: form,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Create vehicle'),
					primary: true,
					closeOnClick: false,
					onClick: async () => {
						C.clearErrors(form);
						const payload = C.collectForm(form);
						payload.do_not_auto_allocate = !!(form.elements.do_not_auto_allocate && form.elements.do_not_auto_allocate.checked);
						if (payload.lease_included_km === '' || payload.lease_included_km == null) {
							delete payload.lease_included_km;
						} else {
							payload.lease_included_km = parseInt(payload.lease_included_km, 10);
						}
						['lease_start_date', 'lease_end_date', 'lease_reference'].forEach((k) => {
							if (payload[k] === '') delete payload[k];
						});
						C.lockForm(form, true);
						try {
							await API.post('/api/vehicles', payload);
							C.closeDialog();
							M.toast(t('Vehicle created.'), 'success');
							load();
						} catch (e) {
							C.lockForm(form, false);
							if (e && e.context && e.context.field) {
								C.applyFieldError(form, e.context.field, M.resolveError(e));
							} else {
								M.reportError(e);
							}
						}
					},
				},
			],
		});
	}

	function init() {
		C.bootstrap();
		const filters = document.getElementById('mc-veh-filters');
		filters?.addEventListener('change', (ev) => {
			lastFilters[ev.target.name] = ev.target.value;
			load();
		});
		document.getElementById('mc-veh-new')?.addEventListener('click', openCreateDialog);
		load();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
