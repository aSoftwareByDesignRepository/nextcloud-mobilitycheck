/**
 * Drivers list page.
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const { fmtDate } = window.MobilityCheckDates;
	const t = M.t;

	async function load() {
		const host = document.getElementById('mc-driv-list');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const rows = API.asArray(await API.get('/api/drivers'));
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'user_id', label: t('User'), render: (r) => C.h('a', { class: 'mc-button-link', href: C.detailUrl('drivers', r.id) }, r.user_id) },
				{ key: 'licence_classes', label: t('Classes'), render: (r) => r.licence_classes || '—' },
				{ key: 'licence_status', label: t('Licence'), render: (r) => C.statusBadge(r.licence_status) },
				{ key: 'licence_expiry_date', label: t('Licence expiry'), render: (r) => r.licence_expiry_date ? fmtDate(r.licence_expiry_date) : '—' },
				{ key: 'compliance_status', label: t('Compliance'), render: (r) => C.statusBadge(r.compliance_status) },
			], rows, { ariaLabel: t('Drivers'), emptyHeading: t('No drivers yet'), emptyHint: t('Add a Nextcloud user as a driver to get started.') });
		} catch (e) {
			C.setLoading(host, false);
			M.reportError(e);
		}
	}

	function openCreateDialog() {
		const form = C.h('form', { class: 'mc-form', novalidate: true });
		const row = C.h('div', { class: 'mc-form-row' });
		row.appendChild(C.h('label', { for: 'mc-drv-user-combo' }, [t('User'), C.h('span', { class: 'mc-required' }, '*')]));
		const mount = C.h('div', { id: 'mc-drv-user-mount' });
		row.appendChild(mount);
		row.appendChild(C.h('p', { id: 'mc-drv-user-hint', class: 'mc-form-row__hint' }, t('Search for an account by name or login. A driver profile is created if one does not exist yet.')));
		row.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		form.appendChild(row);
		const UP = window.MobilityCheckUserPicker;
		if (UP) {
			UP.attachUserCombobox(mount, {
				name: 'userId',
				idBase: 'mc-drv-user',
				required: true,
				ariaDescribedBy: 'mc-drv-user-hint',
			});
		} else {
			row.appendChild(C.h('input', { id: 'mc-drv-user', name: 'userId', type: 'text', required: true, autocomplete: 'off', 'aria-describedby': 'mc-drv-user-hint' }));
		}
		C.showDialog({
			title: t('Add driver'),
			body: form,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Add'),
					primary: true,
					closeOnClick: false,
					onClick: async () => {
						C.clearErrors(form);
						const data = C.collectForm(form);
						C.lockForm(form, true);
						try {
							await API.post('/api/drivers', data);
							M.toast(t('Driver profile created.'), 'success');
							C.closeDialog();
							load();
						} catch (e) {
							C.lockForm(form, false);
							if (e && e.context && e.context.field) C.applyFieldError(form, e.context.field, M.resolveError(e));
							else M.reportError(e);
						}
					},
				},
			],
		});
	}

	function init() {
		const ctx = C.bootstrap();
		const root = ctx.root;
		const scopedLm = root && root.getAttribute('data-mc-line-manager-scoped-reader') === '1';
		if (scopedLm) {
			document.getElementById('mc-driv-new')?.setAttribute('hidden', 'true');
		}
		document.getElementById('mc-driv-new')?.addEventListener('click', openCreateDialog);
		load();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
