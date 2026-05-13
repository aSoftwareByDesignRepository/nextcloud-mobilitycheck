/**
 * Damage detail page — status transitions with accessible dialogs (no window.prompt).
 */
(function () {
	'use strict';
	const C = window.MobilityCheckComponents;
	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;
	const { fmtDateTime } = window.MobilityCheckDates;
	const t = M.t;

	function id() {
		const el = document.getElementById('mc-damage-id');
		return el ? parseInt(el.value, 10) : 0;
	}

	function row(dl, dt, dd) {
		dl.appendChild(C.h('dt', null, dt));
		dl.appendChild(C.h('dd', null, dd == null || dd === '' ? '—' : dd));
	}

	async function load() {
		const did = id();
		if (!did) return;
		const dl = document.getElementById('mc-dmg-dl');
		C.setLoading(dl, true);
		try {
			const r = await API.get('/api/damage-reports/' + did);
			document.querySelectorAll('[data-mc-bind="status-line"]').forEach((el) => {
				el.textContent = t('Severity: {s} — Zone: {z}', { s: r.severity, z: r.zone });
			});
			dl.innerHTML = '';
			row(dl, t('Status'), C.statusBadge(r.status));
			const vehLabel = (r.vehicle_internal_name || ('#' + r.vehicle_id))
				+ (r.vehicle_licence_plate ? ' · ' + r.vehicle_licence_plate : '');
			row(dl, t('Vehicle'), C.h('a', { class: 'mc-button-link', href: C.detailUrl('vehicles', r.vehicle_id) }, vehLabel));
			row(dl, t('Reported by'), r.reported_by_user_id);
			row(dl, t('Discovered'), fmtDateTime(r.discovery_datetime));
			row(dl, t('Severity'), C.statusBadge(r.severity, 'severity'));
			row(dl, t('Zone'), r.zone);
			row(dl, t('Driveable'), r.is_driveable ? t('Yes') : t('No'));
			const desc = C.h('div', { class: 'mc-description', tabindex: 0 });
			desc.textContent = r.description;
			row(dl, t('Description'), desc);
			if (r.booking_id) {
				row(dl, t('Booking'), C.h('a', { class: 'mc-button-link', href: C.detailUrl('bookings', r.booking_id) }, '#' + r.booking_id));
			}
			renderActions(r);
			renderPhotos(r.photos || []);
			renderAmendments(r.amendments || []);
			M.polite(t('Damage report loaded.'));
		} catch (e) {
			C.setLoading(dl, false);
			M.reportError(e);
		}
	}

	function renderActions(r) {
		const host = document.getElementById('mc-dmg-actions');
		if (!host) return;
		host.innerHTML = '';
		const ctx = C.bootstrap();
		const isManager = ctx.root && ctx.root.getAttribute('data-mc-is-manager') === '1';
		const transitions = {
			reported: [['under_assessment', t('Mark under assessment')], ['repair_scheduled', t('Schedule repair')]],
			under_assessment: [['repair_scheduled', t('Schedule repair')], ['closed_no_action', t('Close (no action)')]],
			repair_scheduled: [['in_repair', t('Start repair')], ['closed_no_action', t('Close (no action)')]],
			in_repair: [['repaired', t('Mark repaired')]],
		};
		if (isManager && transitions[r.status]) {
			transitions[r.status].forEach(([s, label]) => {
				host.appendChild(C.h('button', { type: 'button', class: 'button', onClick: () => openStatusChangeDialog(r, s, label) }, label));
			});
		}
	}

	function openStatusChangeDialog(report, nextStatus, actionLabel) {
		const isSafetyClose = report.severity === 'safety_critical' && nextStatus === 'closed_no_action';
		const terminal = nextStatus === 'closed_no_action' || nextStatus === 'repaired';
		const needsStrongReason = terminal && (report.severity === 'major' || report.severity === 'safety_critical');
		const form = C.h('form', { class: 'mc-form' });
		const intro = C.h('p', { class: 'mc-callout mc-callout--warning', id: 'mc-dmg-status-intro' }, [
			t('Describe why you are changing the status. This is stored in the audit log.'),
		]);
		form.appendChild(intro);
		if (isSafetyClose) {
			form.appendChild(C.h('p', { class: 'mc-callout mc-callout--critical', id: 'mc-dmg-safety-callout' }, [
				t('This report is marked as safety-critical. Closing it without a repair record means you confirm the vehicle is safe for use. Only proceed if an authorised person has verified this.'),
			]));
		}
		const reasonRow = C.h('div', { class: 'mc-form-row' });
		reasonRow.appendChild(C.h('label', { for: 'mc-dmg-reason' }, [
			t('Reason / notes'),
			(needsStrongReason || isSafetyClose) ? C.h('span', { class: 'mc-required', 'aria-label': t('required') }, '*') : null,
		]));
		const ta = C.h('textarea', {
			id: 'mc-dmg-reason',
			name: 'reason',
			rows: 4,
			minlength: isSafetyClose ? 10 : (needsStrongReason ? 5 : 0),
			required: needsStrongReason || isSafetyClose,
			'aria-required': (needsStrongReason || isSafetyClose) ? 'true' : 'false',
			maxlength: 4000,
		});
		reasonRow.appendChild(ta);
		reasonRow.appendChild(C.h('p', { class: 'mc-form-row__error', role: 'alert' }));
		form.appendChild(reasonRow);
		let ack = null;
		if (isSafetyClose) {
			const ackErrId = 'mc-dmg-safety-ack-err';
			const ackRow = C.h('div', { class: 'mc-form-row mc-form-row--checkbox' });
			ack = C.h('input', {
				type: 'checkbox',
				id: 'mc-dmg-safety-ack',
				name: 'safetyAck',
				value: '1',
				'aria-describedby': ackErrId,
			});
			ackRow.appendChild(C.h('div', { class: 'mc-checkbox-row' }, [
				ack,
				C.h('label', { for: 'mc-dmg-safety-ack' }, t('I have checked all details and confirm that closing without repair is correct and the vehicle is safe to use.')),
			]));
			ackRow.appendChild(C.h('p', { id: ackErrId, class: 'mc-form-row__error', role: 'alert' }));
			form.appendChild(ackRow);
		}
		C.showDialog({
			title: actionLabel,
			body: form,
			actions: [
				{ label: t('Cancel') },
				{
					label: t('Save status'),
					primary: true,
					closeOnClick: false,
					onClick: async () => {
						C.clearErrors(form);
						const reason = (ta.value || '').trim();
						if (isSafetyClose) {
							if (reason.length < 10) {
								C.applyFieldError(form, 'reason', t('Please enter at least 10 characters.'));
								return;
							}
							if (!ack || !ack.checked) {
								C.applyFieldError(form, 'safetyAck', t('Please confirm the safety-critical acknowledgement checkbox.'));
								return;
							}
						} else if (needsStrongReason && reason.length < 5) {
							C.applyFieldError(form, 'reason', t('Please enter a short reason (at least 5 characters).'));
							return;
						}
						C.lockForm(form, true);
						try {
							const body = { status: nextStatus, reason };
							if (isSafetyClose) body.safetyCriticalClosureAcknowledged = true;
							await API.put('/api/damage-reports/' + id() + '/status', body);
							C.closeDialog();
							M.toast(t('Status updated.'), 'success');
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

	function renderPhotos(photos) {
		const host = document.getElementById('mc-dmg-photos');
		if (!host) return;
		host.innerHTML = '';
		if (!photos.length) {
			host.appendChild(C.h('div', { class: 'mc-empty-state' }, [
				C.h('div', { class: 'mc-empty-state__icon', 'aria-hidden': 'true' }, '?'),
				C.h('div', { class: 'mc-empty-state__main' }, [
					C.h('h3', null, t('No photos yet')),
					C.h('p', null, t('Use the “Add photo” button to attach evidence.')),
				]),
			]));
			return;
		}
		C.renderTable(host, [
			{ key: 'fileId', label: t('File reference') },
			{ key: 'uploadedBy', label: t('Uploaded by') },
			{ key: 'uploadedAt', label: t('Uploaded at'), render: (r) => fmtDateTime(r.uploadedAt) },
		], photos, { ariaLabel: t('Photos'), emptyHeading: t('No photos.') });
	}

	function renderAmendments(am) {
		const host = document.getElementById('mc-dmg-amend');
		if (!host) return;
		C.renderTable(host, [
			{ key: 'id', label: t('Amendment'), render: (r) => '#' + r.id },
			{ key: 'status', label: t('Status'), render: (r) => C.statusBadge(r.status) },
			{ key: 'createdAt', label: t('Created at'), render: (r) => fmtDateTime(r.createdAt) },
		], am, { ariaLabel: t('Amendments'), emptyHeading: t('No amendments.') });
	}

	async function uploadPhoto(ev) {
		const file = ev.target.files && ev.target.files[0];
		if (!file) return;
		try {
			await API.upload('/api/damage-reports/' + id() + '/upload-photo', file);
			M.toast(t('Photo uploaded.'), 'success');
			load();
		} catch (e) { M.reportError(e); }
		ev.target.value = '';
	}

	function init() {
		C.bootstrap();
		document.getElementById('mc-dmg-photo')?.addEventListener('change', uploadPhoto);
		load();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
