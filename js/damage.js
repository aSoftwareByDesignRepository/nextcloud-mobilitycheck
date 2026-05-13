/**
 * Damage list page.
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const { fmtDateTime, fromDateTimeLocalInput, isoForDateTimeInput } = window.MobilityCheckDates;
	const t = M.t;

	const ZONES = [['front', 'Front'], ['rear', 'Rear'], ['left', 'Left'], ['right', 'Right'], ['roof', 'Roof'], ['interior', 'Interior'], ['underbody', 'Underbody']];
	const SEVERITIES = [['cosmetic', 'Cosmetic'], ['minor', 'Minor'], ['major', 'Major'], ['safety_critical', 'Safety critical']];

	const filters = { status: '', vehicleId: '' };

	async function load() {
		const host = document.getElementById('mc-dmg-list');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const rows = await API.get('/api/damage-reports', filters);
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'id', label: t('Report'), render: (r) => C.h('a', { class: 'mc-button-link', href: C.detailUrl('damage', r.id) }, '#' + r.id) },
				{ key: 'vehicle', label: t('Vehicle'), render: (r) => {
					const name = r.vehicle_internal_name || ('#' + r.vehicle_id);
					return name + (r.vehicle_licence_plate ? ' · ' + r.vehicle_licence_plate : '');
				} },
				{ key: 'discovery_datetime', label: t('Discovered'), render: (r) => fmtDateTime(r.discovery_datetime) },
				{ key: 'zone', label: t('Zone') },
				{ key: 'severity', label: t('Severity'), render: (r) => C.statusBadge(r.severity, 'severity') },
				{ key: 'status', label: t('Status'), render: (r) => C.statusBadge(r.status) },
				{ key: 'is_driveable', label: t('Driveable'), render: (r) => r.is_driveable ? t('Yes') : t('No') },
			], rows, { ariaLabel: t('Damage reports'), emptyHeading: t('No damage on record.') });
		} catch (e) {
			C.setLoading(host, false);
			M.reportError(e);
		}
	}

	async function vehicleOptions() {
		try {
			return await API.get('/api/vehicles', { activeOnly: 1 });
		} catch (e) { M.reportError(e); return []; }
	}

	async function openCreateDialog() {
		const vehicles = await vehicleOptions();
		const form = C.h('form', { class: 'mc-form' });
		const grid = C.h('div', { class: 'mc-grid-2' });

		const veh = C.h('div', { class: 'mc-form-row' });
		veh.appendChild(C.h('label', { for: 'mc-dmg-v' }, [t('Vehicle'), C.h('span', { class: 'mc-required' }, '*')]));
		const sel = C.h('select', { id: 'mc-dmg-v', name: 'vehicleId', required: true });
		vehicles.forEach((v) => sel.appendChild(C.h('option', { value: v.id }, v.internal_name + ' · ' + v.licence_plate)));
		veh.appendChild(sel);
		veh.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		grid.appendChild(veh);

		const disc = C.h('div', { class: 'mc-form-row' });
		disc.appendChild(C.h('label', { for: 'mc-dmg-d' }, [t('Discovered at'), C.h('span', { class: 'mc-required' }, '*')]));
		disc.appendChild(C.h('input', { id: 'mc-dmg-d', name: 'discoveryDatetime', type: 'datetime-local', required: true, value: isoForDateTimeInput(new Date()) }));
		disc.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		grid.appendChild(disc);

		const zone = C.h('div', { class: 'mc-form-row' });
		zone.appendChild(C.h('label', { for: 'mc-dmg-z' }, [t('Zone'), C.h('span', { class: 'mc-required' }, '*')]));
		const zsel = C.h('select', { id: 'mc-dmg-z', name: 'zone', required: true }, ZONES.map(([v, l]) => C.h('option', { value: v }, t(l))));
		zone.appendChild(zsel);
		zone.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		grid.appendChild(zone);

		const sev = C.h('div', { class: 'mc-form-row' });
		sev.appendChild(C.h('label', { for: 'mc-dmg-s' }, [t('Severity'), C.h('span', { class: 'mc-required' }, '*')]));
		const ssel = C.h('select', { id: 'mc-dmg-s', name: 'severity', required: true }, SEVERITIES.map(([v, l]) => C.h('option', { value: v }, t(l))));
		sev.appendChild(ssel);
		sev.appendChild(C.h('p', { class: 'mc-form-row__hint' }, t('Safety-critical → vehicle is automatically blocked until repaired.')));
		sev.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		grid.appendChild(sev);

		form.appendChild(grid);

		const drv = C.h('div', { class: 'mc-form-row mc-form-row--checkbox' });
		drv.appendChild(C.h('label', { class: 'mc-checkbox-row', for: 'mc-dmg-drv' }, [
			C.h('input', { type: 'checkbox', id: 'mc-dmg-drv', name: 'isDriveable', value: '1', checked: true }),
			C.h('span', null, t('Vehicle is still safe to drive')),
		]));
		form.appendChild(drv);
		const desc = C.h('div', { class: 'mc-form-row' });
		desc.appendChild(C.h('label', { for: 'mc-dmg-desc' }, [t('Description'), C.h('span', { class: 'mc-required' }, '*')]));
		desc.appendChild(C.h('textarea', { id: 'mc-dmg-desc', name: 'description', required: true, minlength: 5, maxlength: 4000, rows: 4 }));
		desc.appendChild(C.h('p', { class: 'mc-form-row__hint' }, t('What happened? Where exactly is the damage? Be specific so the workshop can plan the repair.')));
		desc.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		form.appendChild(desc);

		C.showDialog({
			title: t('Report damage'),
			body: form,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Submit report'), primary: true, closeOnClick: false,
					onClick: async () => {
						C.clearErrors(form);
						C.lockForm(form, true);
						try {
							const payload = C.collectForm(form);
							payload.vehicleId = parseInt(payload.vehicleId, 10);
							payload.isDriveable = !!payload.isDriveable;
							// Convert the browser's local wall-clock to a UTC ISO 8601 string
							// so the backend stores it unambiguously. Without this, a driver
							// reporting at 14:00 local in Europe/Berlin would persist 14:00 UTC.
							if (payload.discoveryDatetime) {
								payload.discoveryDatetime = fromDateTimeLocalInput(payload.discoveryDatetime);
							}
							const r = await API.post('/api/damage-reports', payload);
							C.closeDialog();
							M.toast(t('Damage report created. Adding photos is recommended.'), 'success');
							window.location.href = C.detailUrl('damage', r.id);
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
		const f = document.getElementById('mc-dmg-filters');
		await C.fillVehicleFilterSelect(document.getElementById('mc-dmg-vehicle'), t);
		C.applySearchParamsToFilters(filters, f);
		f?.addEventListener('change', (ev) => { filters[ev.target.name] = ev.target.value || ''; load(); });
		document.getElementById('mc-dmg-new')?.addEventListener('click', openCreateDialog);
		load();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { void init(); });
	else void init();
})();
