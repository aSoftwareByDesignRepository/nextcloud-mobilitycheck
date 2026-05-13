/**
 * MobilityCheck shared components.
 *
 * Provides:
 *  - DOM helpers (h, text, on, qs, qsa)
 *  - Page bootstrap (URL map, role flags)
 *  - DataTable renderer: declarative columns → table + mobile card list
 *  - Form helpers (collectForm, applyErrors, lockForm)
 *  - Dialog open/close manager (modal with focus trap)
 *  - Confirm helper
 *  - Status-badge formatter (status, severity)
 *
 * Every function is designed so individual page scripts stay tiny and
 * focused on business logic.
 */
(function () {
	'use strict';

	// ── DOM helpers ──────────────────────────────────────────────
	function h(tag, attrs, children) {
		const el = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach((k) => {
				const v = attrs[k];
				if (v === null || v === undefined || v === false) return;
				if (k === 'class') { el.className = v; }
				else if (k === 'style' && typeof v === 'object') { Object.assign(el.style, v); }
				else if (k === 'dataset' && typeof v === 'object') { Object.assign(el.dataset, v); }
				else if (k === 'html') {
					/* Never assign raw HTML from data — XSS hardening (§8). */
					el.textContent = typeof v === 'string' ? v : '';
				}
				else if (k.startsWith('on') && typeof v === 'function') { el.addEventListener(k.slice(2).toLowerCase(), v); }
				else if (typeof v === 'boolean') { if (v) el.setAttribute(k, ''); }
				else { el.setAttribute(k, String(v)); }
			});
		}
		appendChildren(el, children);
		return el;
	}

	function appendChildren(el, children) {
		if (children === null || children === undefined || children === false) return;
		if (Array.isArray(children)) {
			children.forEach((c) => appendChildren(el, c));
			return;
		}
		if (children instanceof Node) { el.appendChild(children); return; }
		el.appendChild(document.createTextNode(String(children)));
	}

	const text = (s) => document.createTextNode(s == null ? '' : String(s));

	// ── Page bootstrap ───────────────────────────────────────────
	function bootstrap() {
		const root = document.getElementById('app-content');
		if (!root) return { urls: {}, roles: {} };
		let urls = {};
		try { urls = JSON.parse(root.getAttribute('data-mc-urls') || '{}'); } catch (_) {}
		return {
			pageId: root.getAttribute('data-mc-page') || '',
			timezone: root.getAttribute('data-mc-timezone') || 'UTC',
			urls,
			root,
		};
	}

	// ── Status badge (§2a.2 — text label + colour; full meaning in aria-label)
	const statusLabelKeys = {
		available: 'Available',
		booked: 'Booked',
		in_use: 'In use',
		in_maintenance: 'In maintenance',
		decommissioned: 'Decommissioned',
		pending_approval: 'Pending approval',
		pending_fleet: 'Pending fleet approval',
		pending_line_manager: 'Pending line manager approval',
		approved: 'Approved',
		rejected: 'Rejected',
		active: 'Active',
		completed: 'Completed',
		cancelled: 'Cancelled',
		reported: 'Reported',
		under_assessment: 'Under assessment',
		repair_scheduled: 'Repair scheduled',
		in_repair: 'In repair',
		repaired: 'Repaired',
		closed_no_action: 'Closed (no action)',
		cosmetic: 'Cosmetic',
		minor: 'Minor',
		major: 'Major',
		safety_critical: 'Safety critical',
		not_provided: 'Not provided',
		uploaded_pending_verification: 'Pending verification',
		verified: 'Verified',
		expired: 'Expired',
		blocked: 'Blocked',
		instructions_pending: 'Instruction pending',
		instruction_pending: 'Instruction pending',
		sent: 'Sent',
		failed: 'Failed',
		in_app: 'In-app',
		email: 'Email',
	};
	function statusBadge(status, type) {
		if (!status) return '';
		const t = window.MobilityCheckMessaging ? window.MobilityCheckMessaging.t : function (k) { return k; };
		const key = statusLabelKeys[status] || status;
		const label = t(key);
		const attr = type === 'severity' ? 'data-severity' : 'data-status';
		const aria = type === 'severity' ? (t('Severity') + ': ' + label) : (t('Status') + ': ' + label);
		return h('span', {
			class: 'mc-status-badge',
			[attr]: status,
			'aria-label': aria,
		}, label);
	}

	/** Booking list / dashboard: status badge plus no-show context (§4.5 step 9a). */
	function bookingStatusCell(row) {
		const t = window.MobilityCheckMessaging ? window.MobilityCheckMessaging.t : function (k) { return k; };
		const wrap = h('div', { class: 'mc-status-cell' });
		const st = row.status || '';
		wrap.appendChild(statusBadge(st));
		if (st === 'cancelled' && String(row.cancellation_reason || '').trim() === 'NO_SHOW') {
			const meta = h('p', { class: 'mc-status-cell__meta' });
			meta.textContent = t('No-show — vehicle not picked up in time.');
			wrap.appendChild(meta);
		}
		return wrap;
	}

	// ── DataTable ────────────────────────────────────────────────
	/**
	 * @typedef {Object} ColumnDef
	 * @property {string} key
	 * @property {string} label
	 * @property {(row:any)=>(string|Node)} [render]
	 * @property {boolean} [num] right-aligned numeric column
	 * @property {boolean} [hideOnMobile]
	 * @property {boolean} [actions] right-aligned action cell (e.g. row buttons)
	 */
	function renderTable(host, columns, rows, opts) {
		const options = opts || {};
		const t = window.MobilityCheckMessaging ? window.MobilityCheckMessaging.t : function (k) { return k; };
		const empty = options.empty || t('No records to display.');
		const rowList = Array.isArray(rows) ? rows : [];
		if (rowList !== rows && typeof console !== 'undefined' && console.warn) {
			console.warn('MobilityCheck: renderTable expected an array of rows');
		}
		host.innerHTML = '';
		if (rowList.length === 0) {
			const hint = options.emptyHint || options.emptyDescription;
			const mainBits = [
				h('h3', null, options.emptyHeading || empty),
				hint ? h('p', null, hint) : null,
			].filter(Boolean);
			const parts = [
				h('div', { class: 'mc-empty-state__icon', 'aria-hidden': 'true' }, '?'),
				h('div', { class: 'mc-empty-state__main' }, mainBits),
			];
			if (options.emptyAction && options.emptyAction.label) {
				const btn = h('button', {
					type: 'button',
					class: 'button button-primary',
					onClick: options.emptyAction.onClick || (() => {}),
				}, options.emptyAction.label);
				if (options.emptyAction.id) btn.id = options.emptyAction.id;
				parts.push(h('div', { class: 'mc-empty-state__action' }, btn));
			} else if (options.emptyAction && options.emptyAction.href) {
				parts.push(h('div', { class: 'mc-empty-state__action' }, [
					h('a', { class: 'button button-primary', href: options.emptyAction.href }, options.emptyAction.label || ''),
				]));
			}
			const node = h('div', { class: 'mc-empty-state' }, parts);
			const wrap = h('div', { class: 'mc-table-wrap', role: 'region', 'aria-label': options.ariaLabel || t('Data table') });
			wrap.appendChild(node);
			host.appendChild(wrap);
			return;
		}
		const wrap = h('div', { class: 'mc-table-wrap', role: 'region', 'aria-label': options.ariaLabel || t('Data table') });
		const table = h('table', { class: 'mc-table' });
		const thead = h('thead');
		const tr = h('tr');
		columns.forEach((col) => {
			const thClasses = [col.num ? 'mc-cell-num' : '', col.actions ? 'mc-cell-actions' : ''].filter(Boolean).join(' ');
			const th = h('th', { scope: 'col', class: thClasses || false }, col.label);
			tr.appendChild(th);
		});
		thead.appendChild(tr);
		table.appendChild(thead);
		const tbody = h('tbody');
		rowList.forEach((row) => {
			const trBody = h('tr', { dataset: { id: row.id != null ? String(row.id) : '' } });
			columns.forEach((col) => {
				const tdClasses = [col.num ? 'mc-cell-num' : '', col.actions ? 'mc-cell-actions' : ''].filter(Boolean).join(' ');
				const td = h('td', { class: tdClasses || false });
				const cellContent = col.render ? col.render(row) : (row[col.key] != null ? row[col.key] : '—');
				if (cellContent instanceof Node) td.appendChild(cellContent);
				else td.textContent = String(cellContent ?? '');
				trBody.appendChild(td);
			});
			tbody.appendChild(trBody);
		});
		table.appendChild(tbody);
		wrap.appendChild(table);

		const cards = h('ul', { class: 'mc-card-list' });
		rowList.forEach((row) => {
			const titleCol = columns[0];
			const card = h('li', { class: 'mc-card-item', dataset: { id: row.id != null ? String(row.id) : '' } });
			const titleVal = titleCol.render ? titleCol.render(row) : (row[titleCol.key] != null ? row[titleCol.key] : '—');
			const title = h('h3', { class: 'mc-card-item__title' });
			if (titleVal instanceof Node) title.appendChild(titleVal); else title.textContent = String(titleVal ?? '');
			card.appendChild(title);
			const dl = h('dl');
			columns.slice(1).forEach((col) => {
				const dt = h('dt', null, col.label);
				const dd = h('dd');
				const val = col.render ? col.render(row) : (row[col.key] != null ? row[col.key] : '—');
				if (val instanceof Node) dd.appendChild(val.cloneNode(true));
				else dd.textContent = String(val ?? '');
				dl.appendChild(dt); dl.appendChild(dd);
			});
			card.appendChild(dl);
			cards.appendChild(card);
		});
		wrap.appendChild(cards);
		host.appendChild(wrap);
	}

	// ── Loading state ────────────────────────────────────────────
	function setLoading(host, on) {
		if (!host) return;
		if (on) {
			host.setAttribute('aria-busy', 'true');
			host.innerHTML = '';
			host.appendChild(h('div', { class: 'mc-loading' }, window.MobilityCheckMessaging ? window.MobilityCheckMessaging.t('Loading…') : 'Loading…'));
		} else {
			host.removeAttribute('aria-busy');
			// Drop the loading placeholder only (do not clear the whole host — some
			// pages call setLoading(false) after they have rendered real content).
			const loader = host.querySelector(':scope > .mc-loading');
			if (loader) {
				loader.remove();
			}
		}
	}

	// ── Form helpers ─────────────────────────────────────────────
	function collectForm(form) {
		const out = {};
		Array.from(form.elements).forEach((el) => {
			if (!el.name || el.disabled) return;
			if (el.type === 'checkbox') {
				out[el.name] = el.checked;
				return;
			}
			if (el.type === 'radio') {
				if (el.checked) out[el.name] = el.value;
				return;
			}
			if (el.type === 'number') {
				const v = el.value === '' ? null : Number(el.value);
				out[el.name] = v;
				return;
			}
			out[el.name] = el.value;
		});
		return out;
	}

	function clearErrors(form) {
		if (!form) return;
		Array.from(form.querySelectorAll('.mc-form-row.has-error')).forEach((row) => {
			row.classList.remove('has-error');
			const err = row.querySelector('.mc-form-row__error');
			if (err) err.textContent = '';
		});
		Array.from(form.querySelectorAll('[aria-invalid="true"]')).forEach((el) => {
			el.removeAttribute('aria-invalid');
		});
	}

	function applyFieldError(form, fieldName, message, opts) {
		if (!fieldName) return;
		const options = opts || {};
		const input = form.querySelector('[name="' + CSS.escape(fieldName) + '"]');
		if (!input) return;
		const row = input.closest('.mc-form-row') || input.parentElement;
		if (!row) return;
		row.classList.add('has-error');
		const errEl = row.querySelector('.mc-form-row__error');
		if (errEl) errEl.textContent = message;
		input.setAttribute('aria-invalid', 'true');
		if (options.focus !== false && input.focus) input.focus();
	}

	function lockForm(form, on) {
		Array.from(form.elements).forEach((el) => { el.disabled = !!on; });
	}

	// ── Dialog manager ───────────────────────────────────────────
	// WCAG 2.1.2 (No Keyboard Trap — but for modals we DO want a focus trap),
	// WCAG 2.4.3 (Focus Order), WCAG 2.4.7 (Focus Visible). When a dialog
	// closes, focus is returned to the element that opened it.
	let openDialog = null;
	const FOCUSABLE_SELECTOR = [
		'a[href]',
		'area[href]',
		'button:not([disabled])',
		'input:not([disabled]):not([type="hidden"])',
		'select:not([disabled])',
		'textarea:not([disabled])',
		'iframe',
		'object',
		'embed',
		'[contenteditable="true"]',
		'[tabindex]:not([tabindex="-1"])',
	].join(',');
	function getFocusable(root) {
		return Array.from(root.querySelectorAll(FOCUSABLE_SELECTOR))
			.filter((el) => !el.hasAttribute('disabled')
				&& !el.getAttribute('aria-hidden')
				&& el.offsetParent !== null);
	}
	function showDialog(opts) {
		closeDialog();
		const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
		const titleId = 'mc-dialog-title-' + Math.random().toString(36).slice(2, 9);
		const backdrop = h('div', { class: 'mc-dialog-backdrop', role: 'presentation' });
		const dialog = h('div', { class: 'mc-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId, tabindex: '-1' });
		dialog.appendChild(h('h2', { id: titleId, class: 'mc-dialog__title' }, opts.title || ''));
		const body = h('div', { class: 'mc-dialog__body' });
		if (typeof opts.body === 'string') body.textContent = opts.body;
		else if (opts.body instanceof Node) body.appendChild(opts.body);
		dialog.appendChild(body);
		const actions = h('div', { class: 'mc-form-actions' });
		(opts.actions || []).forEach((a) => {
			const btn = h('button', { type: 'button', class: a.primary ? 'button button-primary' : (a.danger ? 'button mc-danger-button' : 'button') }, a.label);
			btn.addEventListener('click', () => {
				if (a.onClick) a.onClick();
				if (a.closeOnClick !== false) closeDialog();
			});
			actions.appendChild(btn);
		});
		dialog.appendChild(actions);
		backdrop.appendChild(dialog);
		document.body.appendChild(backdrop);
		openDialog = backdrop;

		// Inert the rest of the page for AT users
		const appContent = document.getElementById('app-content');
		const appNav = document.getElementById('app-navigation');
		const previousAriaState = [];
		[appContent, appNav].forEach((node) => {
			if (!node) return;
			previousAriaState.push({ node, prev: node.getAttribute('aria-hidden') });
			node.setAttribute('aria-hidden', 'true');
		});

		// Set initial focus: first focusable in body, else dialog itself.
		const initial = getFocusable(dialog)[0] || dialog;
		try { initial.focus({ preventScroll: false }); } catch (_) { initial.focus(); }

		const onKey = (ev) => {
			if (ev.key === 'Escape') {
				ev.preventDefault();
				closeDialog();
				return;
			}
			if (ev.key === 'Tab') {
				const items = getFocusable(dialog);
				if (items.length === 0) {
					ev.preventDefault();
					dialog.focus();
					return;
				}
				const first = items[0];
				const last = items[items.length - 1];
				if (ev.shiftKey && document.activeElement === first) {
					ev.preventDefault();
					last.focus();
				} else if (!ev.shiftKey && document.activeElement === last) {
					ev.preventDefault();
					first.focus();
				}
			}
		};
		document.addEventListener('keydown', onKey, true);
		if (opts.closeOnBackdropClick !== false) {
			backdrop.addEventListener('click', (ev) => { if (ev.target === backdrop) closeDialog(); });
		}
		backdrop._cleanup = () => {
			document.removeEventListener('keydown', onKey, true);
			previousAriaState.forEach(({ node, prev }) => {
				if (prev === null || prev === undefined) node.removeAttribute('aria-hidden');
				else node.setAttribute('aria-hidden', prev);
			});
			if (previouslyFocused && document.body.contains(previouslyFocused)) {
				try { previouslyFocused.focus(); } catch (_) {}
			}
		};
		return backdrop;
	}
	function closeDialog() {
		if (openDialog) {
			if (openDialog._cleanup) openDialog._cleanup();
			openDialog.remove();
			openDialog = null;
		}
	}
	function confirmDialog(message, opts) {
		return new Promise((resolve) => {
			const o = opts || {};
			showDialog({
				title: o.title || (window.MobilityCheckMessaging ? window.MobilityCheckMessaging.t('Confirm') : 'Confirm'),
				body: message,
				actions: [
					{ label: o.cancel || (window.MobilityCheckMessaging ? window.MobilityCheckMessaging.t('Cancel') : 'Cancel'), onClick: () => resolve(false) },
					{ label: o.ok || (window.MobilityCheckMessaging ? window.MobilityCheckMessaging.t('Confirm') : 'Confirm'), primary: true, onClick: () => resolve(true) },
				],
			});
		});
	}

	// ── URL builders ─────────────────────────────────────────────
	function detailUrl(name, id) {
		const ctx = bootstrap();
		const base = ctx.urls[name];
		if (!base) return '#';
		// Routes like '/index.php/apps/mobilitycheck/vehicles' need '/{id}' appended
		return base.replace(/\/$/, '') + '/' + encodeURIComponent(String(id));
	}

	/**
	 * Builds a list-page URL with query parameters (e.g. deep links from detail pages).
	 * Keys in `params` must match API / filter names (vehicleId, driverUserId, …).
	 *
	 * @param {string} pageKey key in data-mc-urls (bookings, damage, …)
	 * @param {Record<string, string|number|boolean|undefined|null>} [params]
	 */
	function listUrl(pageKey, params) {
		const ctx = bootstrap();
		let base = ctx.urls[pageKey];
		if (!base) return '#';
		base = String(base).replace(/\/$/, '');
		const sp = new URLSearchParams();
		if (params && typeof params === 'object') {
			Object.keys(params).forEach((k) => {
				const v = params[k];
				if (v === null || v === undefined || v === false) return;
				const s = String(v);
				if (s === '') return;
				sp.set(k, s);
			});
		}
		const q = sp.toString();
		return q ? base + '?' + q : base;
	}

	/**
	 * Copies recognised query-string parameters into `filters` and matching form controls.
	 *
	 * @param {Record<string, string>} filters
	 * @param {HTMLFormElement|null|undefined} form
	 */
	function applySearchParamsToFilters(filters, form) {
		const sp = new URLSearchParams(window.location.search);
		Object.keys(filters).forEach((key) => {
			if (!sp.has(key)) return;
			const raw = sp.get(key);
			const val = raw == null ? '' : String(raw);
			filters[key] = val;
			if (!form || !form.elements) return;
			const el = form.elements.namedItem(key);
			if (!el) return;
			if (el.type === 'checkbox') {
				el.checked = val === '1' || val === 'true';
			} else if ('value' in el) {
				el.value = val;
			}
		});
	}

	/**
	 * Populates a vehicle filter <select> from GET /api/vehicles?activeOnly=1.
	 * Restores the previous value when it still exists (e.g. after URL deep-link).
	 *
	 * @param {HTMLSelectElement|null|undefined} selectEl
	 * @param {(key: string) => string} t
	 */
	async function fillVehicleFilterSelect(selectEl, t) {
		if (!selectEl) return;
		const API = window.MobilityCheckApi;
		const M = window.MobilityCheckMessaging;
		if (!API) return;
		const translate = typeof t === 'function' ? t : (k) => k;
		const saved = String(selectEl.value || '');
		try {
			const rows = API.asArray(await API.get('/api/vehicles', { activeOnly: 1 }));
			selectEl.innerHTML = '';
			const o0 = document.createElement('option');
			o0.value = '';
			o0.textContent = translate('All vehicles');
			selectEl.appendChild(o0);
			rows.forEach((v) => {
				const opt = document.createElement('option');
				opt.value = String(v.id);
				opt.textContent = (v.internal_name || ('#' + v.id)) + (v.licence_plate ? ' · ' + v.licence_plate : '');
				selectEl.appendChild(opt);
			});
			if (saved && [...selectEl.options].some((o) => o.value === saved)) {
				selectEl.value = saved;
			}
		} catch (e) {
			if (M && typeof M.reportError === 'function') M.reportError(e);
		}
	}

	// ── Entity picker (typeahead with chip list) ────────────────
	/**
	 * Renders a controlled chip / typeahead picker into `host`.
	 *
	 * Accessibility:
	 *  - The text input carries `role="combobox"` with `aria-autocomplete`,
	 *    `aria-expanded`, `aria-controls`, `aria-activedescendant`.
	 *  - The result panel is a `role="listbox"` with `role="option"` items.
	 *  - Arrow keys move the active descendant; Enter selects; Esc collapses.
	 *  - Each chip is `role="listitem"` inside a labelled `role="list"`. The
	 *    chip’s delete button has a descriptive `aria-label` and ⌫ removes
	 *    the last chip when the input is empty (Twitter-like behaviour).
	 *
	 * @param {HTMLElement} host
	 * @param {{
	 *   inputId: string,
	 *   inputLabel: string,
	 *   placeholder?: string,
	 *   hint?: string,
	 *   selectedIds?: string[],
	 *   loadSelection?: (ids:string[]) => Promise<Array<{id:string,label:string}>>,
	 *   search: (query:string) => Promise<Array<{id:string,label:string,sub?:string}>>,
	 *   onChange: (ids:string[]) => void,
	 *   disabled?: boolean,
	 *   minChars?: number,
	 * }} options
	 */
	function entityPicker(host, options) {
		const minChars = Number.isFinite(options.minChars) ? options.minChars : 0;
		const state = {
			items: [],
			results: [],
			activeIdx: -1,
			open: false,
		};
		const listId = options.inputId + '-list';
		host.innerHTML = '';
		host.classList.add('mc-picker');

		const label = h('label', { class: 'mc-picker__label', for: options.inputId }, options.inputLabel);
		const wrap = h('div', {
			class: 'mc-picker__wrap',
			'aria-disabled': options.disabled ? 'true' : null,
		});
		const chipList = h('ul', { class: 'mc-picker__chips', role: 'list', 'aria-label': options.inputLabel + ' — ' + (window.MobilityCheckMessaging ? window.MobilityCheckMessaging.t('selected entries') : 'selected entries') });
		const input = h('input', {
			id: options.inputId,
			class: 'mc-picker__input',
			type: 'text',
			autocomplete: 'off',
			role: 'combobox',
			'aria-autocomplete': 'list',
			'aria-expanded': 'false',
			'aria-controls': listId,
			placeholder: options.placeholder || '',
			disabled: !!options.disabled,
		});
		const listbox = h('ul', { id: listId, class: 'mc-picker__results', role: 'listbox', hidden: true });
		wrap.appendChild(chipList);
		wrap.appendChild(input);
		host.appendChild(label);
		host.appendChild(wrap);
		host.appendChild(listbox);
		if (options.hint) {
			host.appendChild(h('p', { class: 'mc-form-row__hint', id: options.inputId + '-hint' }, options.hint));
			input.setAttribute('aria-describedby', options.inputId + '-hint');
		}

		function renderChips() {
			chipList.innerHTML = '';
			state.items.forEach((item) => {
				const li = h('li', { class: 'mc-picker__chip', role: 'listitem', dataset: { id: item.id } });
				li.appendChild(h('span', { class: 'mc-picker__chip-label' }, item.label));
				if (!options.disabled) {
					const btn = h('button', {
						type: 'button',
						class: 'mc-picker__chip-remove',
						'aria-label': (window.MobilityCheckMessaging ? window.MobilityCheckMessaging.t('Remove {label}', { label: item.label }) : 'Remove ' + item.label),
					}, '×');
					btn.addEventListener('click', () => removeId(item.id));
					li.appendChild(btn);
				}
				chipList.appendChild(li);
			});
		}

		function removeId(id) {
			state.items = state.items.filter((i) => i.id !== id);
			renderChips();
			options.onChange(state.items.map((i) => i.id));
			input.focus();
		}

		function addItem(item) {
			if (state.items.some((i) => i.id === item.id)) return;
			state.items.push(item);
			renderChips();
			options.onChange(state.items.map((i) => i.id));
		}

		function openResults() {
			if (state.results.length === 0) { closeResults(); return; }
			listbox.hidden = false;
			input.setAttribute('aria-expanded', 'true');
			state.open = true;
		}
		function closeResults() {
			listbox.hidden = true;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
			state.activeIdx = -1;
			state.open = false;
		}

		function setActive(idx) {
			Array.from(listbox.children).forEach((el, i) => {
				el.setAttribute('aria-selected', i === idx ? 'true' : 'false');
				el.classList.toggle('is-active', i === idx);
			});
			state.activeIdx = idx;
			if (idx >= 0 && listbox.children[idx]) {
				input.setAttribute('aria-activedescendant', listbox.children[idx].id);
				listbox.children[idx].scrollIntoView({ block: 'nearest' });
			} else {
				input.removeAttribute('aria-activedescendant');
			}
		}

		function renderResults(results, query) {
			state.results = results;
			listbox.innerHTML = '';
			if (results.length === 0) {
				const msg = window.MobilityCheckMessaging
					? window.MobilityCheckMessaging.t('No matches for “{q}”.', { q: query })
					: 'No matches for "' + query + '".';
				const li = h('li', { class: 'mc-picker__no-match', role: 'option', 'aria-disabled': 'true' }, msg);
				listbox.appendChild(li);
				openResults();
				return;
			}
			results.forEach((r, i) => {
				const optId = options.inputId + '-opt-' + i;
				const li = h('li', { id: optId, class: 'mc-picker__option', role: 'option', dataset: { id: r.id }, 'aria-selected': 'false' }, [
					h('span', { class: 'mc-picker__option-label' }, r.label),
					r.sub ? h('span', { class: 'mc-picker__option-sub' }, r.sub) : null,
				]);
				li.addEventListener('mousedown', (ev) => { ev.preventDefault(); addItem({ id: r.id, label: r.label }); input.value = ''; closeResults(); });
				listbox.appendChild(li);
			});
			openResults();
			setActive(0);
		}

		let debounceTimer = null;
		async function runSearch(q) {
			if (q.length < minChars && q.length > 0) { closeResults(); return; }
			try {
				const res = await options.search(q);
				renderResults(Array.isArray(res) ? res.filter((r) => !state.items.some((i) => i.id === r.id)) : [], q);
			} catch (_) { closeResults(); }
		}

		input.addEventListener('input', () => {
			if (debounceTimer) clearTimeout(debounceTimer);
			debounceTimer = setTimeout(() => runSearch(input.value.trim()), 180);
		});
		input.addEventListener('keydown', (ev) => {
			if (options.disabled) return;
			if (ev.key === 'ArrowDown') {
				ev.preventDefault();
				if (!state.open) { runSearch(input.value.trim()); return; }
				setActive(Math.min(state.activeIdx + 1, state.results.length - 1));
			} else if (ev.key === 'ArrowUp') {
				ev.preventDefault();
				setActive(Math.max(state.activeIdx - 1, 0));
			} else if (ev.key === 'Enter') {
				if (state.open && state.activeIdx >= 0 && state.results[state.activeIdx]) {
					ev.preventDefault();
					const r = state.results[state.activeIdx];
					addItem({ id: r.id, label: r.label });
					input.value = '';
					closeResults();
				}
			} else if (ev.key === 'Escape') {
				if (state.open) { ev.preventDefault(); closeResults(); }
			} else if (ev.key === 'Backspace' && input.value === '' && state.items.length > 0) {
				removeId(state.items[state.items.length - 1].id);
			}
		});
		input.addEventListener('focus', () => { if (input.value.trim() !== '') runSearch(input.value.trim()); });
		document.addEventListener('click', (ev) => { if (!host.contains(ev.target)) closeResults(); });

		// Initial hydration
		if (options.selectedIds && options.selectedIds.length > 0 && typeof options.loadSelection === 'function') {
			options.loadSelection(options.selectedIds).then((rows) => {
				const API = window.MobilityCheckApi;
				const list = API && typeof API.asArray === 'function' ? API.asArray(rows) : (Array.isArray(rows) ? rows : []);
				state.items = list.map((r) => ({ id: r.id, label: r.label }));
				renderChips();
			}).catch(() => {
				// fall back to raw ids
				state.items = options.selectedIds.map((id) => ({ id, label: id }));
				renderChips();
			});
		} else if (options.selectedIds && options.selectedIds.length > 0) {
			state.items = options.selectedIds.map((id) => ({ id, label: id }));
			renderChips();
		}

		return {
			getIds() { return state.items.map((i) => i.id); },
			setIds(ids) {
				if (typeof options.loadSelection === 'function') {
					options.loadSelection(ids || []).then((rows) => {
						const API = window.MobilityCheckApi;
						const list = API && typeof API.asArray === 'function' ? API.asArray(rows) : (Array.isArray(rows) ? rows : []);
						state.items = list.map((r) => ({ id: r.id, label: r.label }));
						renderChips();
					});
				} else {
					state.items = (ids || []).map((id) => ({ id, label: id }));
					renderChips();
				}
			},
			focus() { input.focus(); },
		};
	}

	window.MobilityCheckComponents = {
		h, text,
		bootstrap,
		renderTable,
		setLoading,
		statusBadge,
		bookingStatusCell,
		collectForm, clearErrors, applyFieldError, lockForm,
		showDialog, closeDialog, confirmDialog,
		detailUrl,
		listUrl,
		applySearchParamsToFilters,
		fillVehicleFilterSelect,
		entityPicker,
	};
})();
