/**
 * Cost entries page.
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const { fmtDate } = window.MobilityCheckDates;
	const Money = window.MobilityCheckMoney;
	const t = M.t;

	const filters = { vehicleId: '', from: '', to: '' };

	async function load() {
		const host = document.getElementById('mc-cost-list');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const rows = await API.get('/api/cost-entries', filters);
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'entry_date', label: t('Date'), render: (r) => fmtDate(r.entry_date) },
				{ key: 'vehicle', label: t('Vehicle'), render: (r) => {
					const name = r.vehicle_internal_name || ('#' + r.vehicle_id);
					return name + (r.vehicle_licence_plate ? ' · ' + r.vehicle_licence_plate : '');
				} },
				{ key: 'category', label: t('Category'), render: (r) => r.category_name || ('#' + r.category_id) },
				{ key: 'amount_gross_minor', label: t('Gross'), num: true, render: (r) => Money.format(r.amount_gross_minor) },
				{ key: 'amount_net_minor', label: t('Net'), num: true, render: (r) => Money.format(r.amount_net_minor) },
				{ key: 'vat_amount_minor', label: t('VAT'), num: true, render: (r) => Money.format(r.vat_amount_minor) + ' (' + (r.vat_rate_bp / 100).toFixed(2) + '%)' },
				{ key: 'receipt_reference', label: t('Receipt'), render: (r) => r.receipt_reference || '—' },
			], rows, { ariaLabel: t('Cost entries'), emptyHeading: t('No cost entries match your filters.') });
		} catch (e) {
			C.setLoading(host, false);
			M.reportError(e);
		}
	}

	async function openCreateDialog() {
		const [vehicles, categories] = await Promise.all([
			API.get('/api/vehicles', { activeOnly: 1 }),
			API.get('/api/cost-categories'),
		]);
		const form = C.h('form', { class: 'mc-form' });
		const grid = C.h('div', { class: 'mc-grid-2' });

		const v = C.h('div', { class: 'mc-form-row' });
		v.appendChild(C.h('label', { for: 'mc-c-v' }, [t('Vehicle'), C.h('span', { class: 'mc-required' }, '*')]));
		const vs = C.h('select', { id: 'mc-c-v', name: 'vehicle_id', required: true });
		vehicles.forEach((vv) => vs.appendChild(C.h('option', { value: vv.id }, vv.internal_name + ' · ' + vv.licence_plate)));
		v.appendChild(vs); v.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		grid.appendChild(v);

		const cat = C.h('div', { class: 'mc-form-row' });
		cat.appendChild(C.h('label', { for: 'mc-c-cat' }, [t('Category'), C.h('span', { class: 'mc-required' }, '*')]));
		const cs = C.h('select', { id: 'mc-c-cat', name: 'category_id', required: true });
		categories.forEach((c) => cs.appendChild(C.h('option', { value: c.id }, c.name)));
		cat.appendChild(cs); cat.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		grid.appendChild(cat);

		const date = C.h('div', { class: 'mc-form-row' });
		date.appendChild(C.h('label', { for: 'mc-c-d' }, [t('Entry date'), C.h('span', { class: 'mc-required' }, '*')]));
		date.appendChild(C.h('input', { id: 'mc-c-d', name: 'entry_date', type: 'date', required: true }));
		date.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		grid.appendChild(date);

		const amt = C.h('div', { class: 'mc-form-row' });
		amt.appendChild(C.h('label', { for: 'mc-c-a' }, [t('Gross amount'), C.h('span', { class: 'mc-required' }, '*')]));
		const amtHint = C.h('p', { id: 'mc-c-a-hint', class: 'mc-form-row__hint' }, t('Comma or dot decimal — net and VAT are calculated.'));
		const amtErr = C.h('p', { id: 'mc-c-a-err', class: 'mc-form-row__error', role: 'alert' });
		amt.appendChild(C.h('input', {
			id: 'mc-c-a',
			name: 'amount_gross',
			type: 'text',
			inputmode: 'decimal',
			required: true,
			placeholder: t('Example amount (gross)'),
			'aria-describedby': 'mc-c-a-hint mc-c-a-err',
		}));
		amt.appendChild(amtHint);
		amt.appendChild(amtErr);
		grid.appendChild(amt);

		const vat = C.h('div', { class: 'mc-form-row' });
		vat.appendChild(C.h('label', { for: 'mc-c-vat' }, [t('VAT rate'), C.h('span', { class: 'mc-required' }, '*')]));
		const vsel = C.h('select', { id: 'mc-c-vat', name: 'vat_rate_bp', required: true }, [
			C.h('option', { value: 1900 }, '19% (' + t('standard') + ')'),
			C.h('option', { value: 700 }, '7% (' + t('reduced') + ')'),
			C.h('option', { value: 0 }, '0%'),
		]);
		vat.appendChild(vsel); vat.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		grid.appendChild(vat);

		const rec = C.h('div', { class: 'mc-form-row' });
		rec.appendChild(C.h('label', { for: 'mc-c-r' }, t('Receipt reference')));
		rec.appendChild(C.h('input', { id: 'mc-c-r', name: 'receipt_reference', maxlength: 120 }));
		rec.appendChild(C.h('p', { class: 'mc-form-row__hint' }, t('Invoice number or scan ID.')));
		grid.appendChild(rec);

		form.appendChild(grid);
		const notes = C.h('div', { class: 'mc-form-row' });
		notes.appendChild(C.h('label', { for: 'mc-c-n' }, t('Notes')));
		notes.appendChild(C.h('textarea', { id: 'mc-c-n', name: 'notes', rows: 3 }));
		form.appendChild(notes);

		C.showDialog({
			title: t('Record cost entry'),
			body: form,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Save'), primary: true, closeOnClick: false,
					onClick: async () => {
						C.clearErrors(form);
						const raw = C.collectForm(form);
						const grossMinor = Money.parseToMinor(raw.amount_gross);
						if (!Number.isFinite(grossMinor) || grossMinor === null) {
							C.applyFieldError(form, 'amount_gross', t('Please enter a valid amount, for example 12,34.'));
							return;
						}
						const payload = {
							vehicle_id: parseInt(raw.vehicle_id, 10),
							category_id: parseInt(raw.category_id, 10),
							entry_date: raw.entry_date,
							amount_gross_minor: grossMinor,
							vat_rate_bp: parseInt(raw.vat_rate_bp, 10),
							receipt_reference: raw.receipt_reference || null,
							notes: raw.notes || null,
						};
						C.lockForm(form, true);
						try {
							await API.post('/api/cost-entries', payload);
							C.closeDialog();
							M.toast(t('Cost entry recorded.'), 'success');
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
		const ctx = C.bootstrap();
		const root = ctx.root;
		if (root && root.getAttribute('data-mc-line-manager-scoped-reader') === '1') {
			document.getElementById('mc-cost-new')?.setAttribute('hidden', 'true');
		}
		const f = document.getElementById('mc-cost-filters');
		await C.fillVehicleFilterSelect(document.getElementById('mc-cost-veh'), t);
		C.applySearchParamsToFilters(filters, f);
		f?.addEventListener('change', (ev) => { filters[ev.target.name] = ev.target.value || ''; load(); });
		document.getElementById('mc-cost-new')?.addEventListener('click', openCreateDialog);
		load();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { void init(); });
	else void init();
})();
