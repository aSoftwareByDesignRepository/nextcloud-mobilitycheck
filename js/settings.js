/**
 * Settings page. Fleet admins view + manage roles + read audit; only
 * app admins may save the access policy and operational defaults
 * (server enforces; UI gates form submission via data-mc-is-app-admin).
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const { fmtDateTime } = window.MobilityCheckDates;
	const t = M.t;

	const ROLES = ['fleet_admin', 'fleet_manager', 'line_manager', 'driver', 'workshop', 'auditor'];

	const ROLE_LABELS = {
		fleet_admin: t('Fleet admin'),
		fleet_manager: t('Fleet manager'),
		line_manager: t('Line manager'),
		driver: t('Driver'),
		workshop: t('Workshop'),
		auditor: t('Auditor'),
	};

	function isAppAdmin() {
		const shell = document.getElementById('app-content');
		return shell && shell.getAttribute('data-mc-is-app-admin') === '1';
	}

	let loadedPolicy = { appAdminUserIds: [], allowedUserIds: [], allowedGroupIds: [], accessRestrictionEnabled: false };
	const UP = window.MobilityCheckUserPicker;
	let policyPickers = [];
	let lmDriverPicker;
	let lmManagerPicker;
	let auditUserPicker;

	function syncPolicyPickersFromDom() {
		policyPickers.forEach((p) => p.syncFromTextarea());
	}

	function initPolicyPickers() {
		if (!isAppAdmin() || !UP) return;
		const g = document.getElementById('mc-set-groups-ui');
		const taG = document.getElementById('mc-set-groups');
		if (g && taG) policyPickers.push(UP.attachMultiIdPicker(g, taG, { kind: 'group', inputId: 'mc-set-groups-search' }));
		const u = document.getElementById('mc-set-users-allowed-ui');
		const taU = document.getElementById('mc-set-users-allowed');
		if (u && taU) policyPickers.push(UP.attachMultiIdPicker(u, taU, { kind: 'user', inputId: 'mc-set-users-allowed-search' }));
		const a = document.getElementById('mc-set-appadmins-ui');
		const taA = document.getElementById('mc-set-appadmins');
		if (a && taA) policyPickers.push(UP.attachMultiIdPicker(a, taA, { kind: 'user', inputId: 'mc-set-appadmins-search' }));
	}

	function initLmPickers() {
		if (!UP) return;
		const dW = document.getElementById('mc-lm-driver-wrap');
		if (dW) {
			lmDriverPicker = UP.attachUserCombobox(dW, {
				name: 'driverUserId',
				idBase: 'mc-lm-driver',
				required: true,
				fetchUsers: async (q) => {
					const rows = await API.get('/api/drivers');
					const tq = q.trim().toLowerCase();
					return (rows || []).filter((r) => {
						const uid = String(r.user_id || '').toLowerCase();
						const dn = String(r.displayName || '').toLowerCase();
						return !tq || uid.includes(tq) || dn.includes(tq);
					}).map((r) => ({ id: r.user_id, displayName: r.displayName || r.user_id }));
				},
			});
		}
		const mW = document.getElementById('mc-lm-manager-wrap');
		if (mW) {
			lmManagerPicker = UP.attachUserCombobox(mW, {
				name: 'lineManagerUserId',
				idBase: 'mc-lm-manager',
				required: true,
				filterRow: (row) => (row.roles || []).indexOf('line_manager') !== -1,
			});
		}
	}

	function initAuditPicker() {
		const w = document.getElementById('mc-set-audit-user-wrap');
		if (!UP || !w) return;
		auditUserPicker = UP.attachUserCombobox(w, {
			name: 'userId',
			idBase: 'mc-set-audit-user',
			allowClear: true,
			onChange: (uid) => {
				auditFilters.userId = uid || '';
				debounceLoadAudit();
			},
		});
	}

	function splitIds(text) {
		return String(text || '')
			.split(/[\s,;]+/)
			.map((s) => s.trim())
			.filter((s) => s.length > 0);
	}

	function setTextarea(form, name, ids) {
		const el = form.elements[name];
		if (!el) return;
		el.value = (Array.isArray(ids) ? ids : []).join('\n');
	}

	async function loadPolicy() {
		try {
			const p = await API.get('/api/admin/policy');
			loadedPolicy = {
				appAdminUserIds: p.appAdminUserIds || [],
				allowedUserIds: p.allowedUserIds || [],
				allowedGroupIds: p.allowedGroupIds || [],
				accessRestrictionEnabled: !!p.accessRestrictionEnabled,
			};
			const form = document.getElementById('mc-set-policy');
			if (!form) return;
			if (form.elements.mode) form.elements.mode.value = loadedPolicy.accessRestrictionEnabled ? 'restricted' : 'open';
			setTextarea(form, 'allowedGroups', loadedPolicy.allowedGroupIds);
			setTextarea(form, 'allowedUsers', loadedPolicy.allowedUserIds);
			setTextarea(form, 'appAdmins', loadedPolicy.appAdminUserIds);
			syncPolicyPickersFromDom();
		} catch (e) { console.error('[MC settings] loadPolicy failed', e, e && e.stack); M.reportError(e); }
	}

	async function savePolicy(ev) {
		ev.preventDefault();
		if (!isAppAdmin()) {
			M.toast(t('Only an app administrator can change the access policy.'), 'error');
			return;
		}
		const form = ev.target;
		C.clearErrors(form);
		const restriction = form.elements.mode && form.elements.mode.value === 'restricted';
		const allowedGroupIds = form.elements.allowedGroups ? splitIds(form.elements.allowedGroups.value) : [];
		const allowedUserIds = form.elements.allowedUsers ? splitIds(form.elements.allowedUsers.value) : [];
		const appAdminUserIds = form.elements.appAdmins ? splitIds(form.elements.appAdmins.value) : [];
		if (restriction && allowedGroupIds.length === 0 && allowedUserIds.length === 0) {
			const msg = t('Restricted mode needs at least one allowed user or group.');
			C.applyFieldError(form, 'allowedUsers', msg);
			C.applyFieldError(form, 'allowedGroups', msg, { focus: false });
			return;
		}
		const payload = {
			accessRestrictionEnabled: !!restriction,
			allowedGroupIds,
			allowedUserIds,
			appAdminUserIds,
		};
		try {
			const p = await API.post('/api/admin/policy', payload);
			loadedPolicy = {
				appAdminUserIds: p.appAdminUserIds || [],
				allowedUserIds: p.allowedUserIds || [],
				allowedGroupIds: p.allowedGroupIds || [],
				accessRestrictionEnabled: !!p.accessRestrictionEnabled,
			};
			setTextarea(form, 'allowedGroups', loadedPolicy.allowedGroupIds);
			setTextarea(form, 'allowedUsers', loadedPolicy.allowedUserIds);
			setTextarea(form, 'appAdmins', loadedPolicy.appAdminUserIds);
			syncPolicyPickersFromDom();
			M.toast(t('Access policy saved.'), 'success');
		} catch (e) { M.reportError(e); }
	}

	async function loadOps() {
		try {
			const s = await API.get('/api/admin/settings');
			const form = document.getElementById('mc-set-ops');
			if (form && form.elements) {
				form.elements.currency.value = s.currency || 'EUR';
				form.elements.defaultVatBp.value = String(s.defaultVatBp || 1900);
				form.elements.defaultTimezone.value = s.defaultTimezone || '';
				const mode = s.approvalMode || (s.approvalWorkflow ? 'fleet_manager' : 'none');
				if (form.elements.approvalMode) form.elements.approvalMode.value = mode;
				if (form.elements.approvalFallbackNoLm) {
					form.elements.approvalFallbackNoLm.checked = s.approvalFallbackNoLm !== false && s.approvalFallbackNoLm !== 0;
				}
				form.elements.checkinGraceMinutes.value = s.checkinGraceMinutes || 120;
				form.elements.maxUploadBytes.value = s.maxUploadBytes || (10 * 1024 * 1024);
				const ics = form.elements.bookingEmailAttachIcs;
				if (ics) {
					ics.checked = s.bookingEmailAttachIcs !== false && s.bookingEmailAttachIcs !== 0;
				}
			}
			const af = document.getElementById('mc-set-alloc');
			if (af && af.elements) {
				const en = af.elements.intelligentAllocationEnabled;
				if (en) en.checked = !!s.intelligentAllocationEnabled;
				if (af.elements.intelligentAllocationMode) af.elements.intelligentAllocationMode.value = s.intelligentAllocationMode || 'suggest_only';
				if (af.elements.intelligentAllocationOnNoReplacement) {
					af.elements.intelligentAllocationOnNoReplacement.value = s.intelligentAllocationOnNoReplacement || 'flag_for_manual';
				}
				if (af.elements.vehicleChoicePolicy) af.elements.vehicleChoicePolicy.value = s.vehicleChoicePolicy || 'driver_may_choose';
				if (af.elements.minRemainingLeaseDaysForBooking) {
					af.elements.minRemainingLeaseDaysForBooking.value = String(s.minRemainingLeaseDaysForBooking ?? 0);
				}
				if (af.elements.minRemainingLeaseKmPercent) {
					af.elements.minRemainingLeaseKmPercent.value = String(s.minRemainingLeaseKmPercent ?? 0);
				}
				const w = s.intelligentAllocationWeights || {};
				if (af.elements.wLease) af.elements.wLease.value = String(w.wLease ?? 1);
				if (af.elements.wAge) af.elements.wAge.value = String(w.wAge ?? 1);
				if (af.elements.wUtil) af.elements.wUtil.value = String(w.wUtil ?? 1);
				if (af.elements.wOps) af.elements.wOps.value = String(w.wOps ?? 1);
			}
		} catch (e) { M.reportError(e); }
	}

	async function saveAlloc(ev) {
		ev.preventDefault();
		if (!isAppAdmin()) {
			M.toast(t('Only an app administrator can change the operational defaults.'), 'error');
			return;
		}
		const af = ev.target;
		const payload = {
			intelligentAllocationEnabled: !!(af.elements.intelligentAllocationEnabled && af.elements.intelligentAllocationEnabled.checked),
			intelligentAllocationMode: af.elements.intelligentAllocationMode ? af.elements.intelligentAllocationMode.value : 'suggest_only',
			intelligentAllocationOnNoReplacement: af.elements.intelligentAllocationOnNoReplacement
				? af.elements.intelligentAllocationOnNoReplacement.value
				: 'flag_for_manual',
			vehicleChoicePolicy: af.elements.vehicleChoicePolicy ? af.elements.vehicleChoicePolicy.value : 'driver_may_choose',
			minRemainingLeaseDaysForBooking: parseInt(af.elements.minRemainingLeaseDaysForBooking.value, 10) || 0,
			minRemainingLeaseKmPercent: parseInt(af.elements.minRemainingLeaseKmPercent.value, 10) || 0,
			intelligentAllocationWeights: {
				wLease: Math.max(0, Math.min(10, parseInt(af.elements.wLease.value, 10) || 0)),
				wAge: Math.max(0, Math.min(10, parseInt(af.elements.wAge.value, 10) || 0)),
				wUtil: Math.max(0, Math.min(10, parseInt(af.elements.wUtil.value, 10) || 0)),
				wOps: Math.max(0, Math.min(10, parseInt(af.elements.wOps.value, 10) || 0)),
			},
		};
		try {
			await API.post('/api/admin/settings', payload);
			M.toast(t('Intelligent allocation settings saved.'), 'success');
		} catch (e) { M.reportError(e); }
	}

	async function saveOps(ev) {
		ev.preventDefault();
		if (!isAppAdmin()) {
			M.toast(t('Only an app administrator can change the operational defaults.'), 'error');
			return;
		}
		const form = ev.target;
		const payload = {
			currency: (form.elements.currency.value || 'EUR').toUpperCase(),
			defaultVatBp: parseInt(form.elements.defaultVatBp.value, 10),
			defaultTimezone: form.elements.defaultTimezone.value,
			approvalMode: form.elements.approvalMode ? form.elements.approvalMode.value : 'none',
			approvalFallbackNoLm: !!(form.elements.approvalFallbackNoLm && form.elements.approvalFallbackNoLm.checked),
			approvalWorkflow: form.elements.approvalMode ? (form.elements.approvalMode.value !== 'none') : false,
			checkinGraceMinutes: parseInt(form.elements.checkinGraceMinutes.value, 10),
			maxUploadBytes: parseInt(form.elements.maxUploadBytes.value, 10),
			bookingEmailAttachIcs: !!(form.elements.bookingEmailAttachIcs && form.elements.bookingEmailAttachIcs.checked),
		};
		try {
			await API.post('/api/admin/settings', payload);
			M.toast(t('Operational defaults saved.'), 'success');
		} catch (e) { M.reportError(e); }
	}

	let userSearchTimer = null;
	function debounceLoadUsers(value) {
		if (userSearchTimer) clearTimeout(userSearchTimer);
		userSearchTimer = setTimeout(() => loadUsers(value), 200);
	}

	async function loadUsers(search) {
		const host = document.getElementById('mc-set-users');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const users = await API.get('/api/admin/users', { search });
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'id', label: t('User ID') },
				{ key: 'displayName', label: t('Display name') },
				{
					key: 'roles', label: t('Roles'), render: (r) => {
						const wrap = C.h('div', { class: 'mc-taglist' });
						ROLES.forEach((role) => {
							const id = 'mc-r-' + r.id + '-' + role;
							const checked = (r.roles || []).indexOf(role) !== -1;
							wrap.appendChild(C.h('label', { class: 'mc-checkbox-row', for: id }, [
								C.h('input', { type: 'checkbox', id, checked, dataset: { user: r.id, role } }),
								C.h('span', null, ROLE_LABELS[role] || role),
							]));
						});
						return wrap;
					},
				},
				{ key: 'actions', label: t('Actions'), render: (r) => C.h('button', { type: 'button', class: 'button button-primary', onClick: () => saveRoles(r.id) }, t('Save roles')) },
			], users, { ariaLabel: t('Role assignment'), emptyHeading: t('No users match your search.'), emptyDescription: t('Try a different name or user ID.') });
		} catch (e) { C.setLoading(host, false); M.reportError(e); }
	}

	async function saveRoles(userId) {
		const roles = Array.from(document.querySelectorAll('input[data-user="' + CSS.escape(userId) + '"]:checked')).map((el) => el.dataset.role);
		try {
			await API.post('/api/admin/roles', { userId, roles });
			M.toast(t('Roles updated for {user}.').replace('{user}', userId), 'success');
		} catch (e) { M.reportError(e); }
	}

	async function loadLmAssignments() {
		const host = document.getElementById('mc-set-lm-list');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const rows = await API.get('/api/admin/line-manager-assignments');
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'driverUserId', label: t('Driver') },
				{ key: 'lineManagerUserId', label: t('Line manager') },
				{ key: 'validFrom', label: t('From') },
				{ key: 'validUntil', label: t('Until'), render: (r) => r.validUntil || '—' },
				{
					key: 'actions', label: t('Actions'), render: (r) => C.h('button', {
						type: 'button', class: 'button', onClick: () => closeLm(r.id),
					}, t('Close')),
				},
			], rows, { ariaLabel: t('Line manager assignments'), emptyHeading: t('No assignments yet.') });
		} catch (e) {
			C.setLoading(host, false);
			if (e.code !== 'INSUFFICIENT_ROLE') M.reportError(e);
		}
	}

	async function closeLm(id) {
		try {
			await API.post('/api/admin/line-manager-assignments/' + id + '/close', {});
			M.toast(t('Assignment closed.'), 'success');
			loadLmAssignments();
		} catch (e) { M.reportError(e); }
	}

	async function saveLm(ev) {
		ev.preventDefault();
		const form = ev.target;
		const payload = {
			driverUserId: (form.elements.driverUserId && form.elements.driverUserId.value ? form.elements.driverUserId.value : '').trim(),
			lineManagerUserId: (form.elements.lineManagerUserId && form.elements.lineManagerUserId.value ? form.elements.lineManagerUserId.value : '').trim(),
			validFrom: form.elements.validFrom.value.trim() || undefined,
			notes: form.elements.notes.value.trim() || undefined,
		};
		try {
			await API.post('/api/admin/line-manager-assignments', payload);
			M.toast(t('Assignment created.'), 'success');
			form.reset();
			lmDriverPicker?.clear();
			lmManagerPicker?.clear();
			loadLmAssignments();
		} catch (e) { M.reportError(e); }
	}

	let auditFilters = { entityType: '', userId: '' };
	let auditTimer = null;
	function debounceLoadAudit() {
		if (auditTimer) clearTimeout(auditTimer);
		auditTimer = setTimeout(loadAudit, 200);
	}

	async function loadAudit() {
		const host = document.getElementById('mc-set-audit');
		if (!host) return;
		C.setLoading(host, true);
		try {
			const rows = await API.get('/api/admin/audit-log', auditFilters);
			C.setLoading(host, false);
			C.renderTable(host, [
				{ key: 'performed_at', label: t('When'), render: (r) => fmtDateTime(r.performed_at || r.created_at) },
				{ key: 'performed_by_user_id', label: t('Actor'), render: (r) => r.performed_by_user_id || r.user_id || '—' },
				{ key: 'entity_type', label: t('Entity') },
				{ key: 'entity_id', label: t('ID') },
				{ key: 'action', label: t('Action') },
				{ key: 'reason', label: t('Reason'), render: (r) => r.reason ? r.reason : '—' },
				{
					key: 'details', label: t('Details'), render: (r) => {
						if (!r.details && !r.new_value && !r.old_value) return '—';
						const payload = r.details || { old: r.old_value, new: r.new_value };
						return JSON.stringify(payload).slice(0, 200);
					},
				},
			], rows, { ariaLabel: t('Audit log'), emptyHeading: t('No audit entries match these filters.'), emptyDescription: t('Clear the filters to see the most recent entries.') });
		} catch (e) { C.setLoading(host, false); M.reportError(e); }
	}

	function init() {
		C.bootstrap();
		initPolicyPickers();
		initLmPickers();
		initAuditPicker();
		document.getElementById('mc-set-policy')?.addEventListener('submit', savePolicy);
		document.getElementById('mc-set-ops')?.addEventListener('submit', saveOps);
		document.getElementById('mc-set-alloc')?.addEventListener('submit', saveAlloc);
		document.getElementById('mc-set-lm-add')?.addEventListener('submit', saveLm);
		const search = document.getElementById('mc-set-q');
		search?.addEventListener('input', () => debounceLoadUsers(search.value));
		const auf = document.getElementById('mc-set-audit-filters');
		auf?.addEventListener('input', (ev) => {
			if (!ev.target || !ev.target.name) return;
			if (ev.target.name === 'entityType') {
				auditFilters.entityType = ev.target.value || '';
				debounceLoadAudit();
			}
		});
		loadPolicy(); loadOps(); loadUsers(''); loadAudit(); loadLmAssignments();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
