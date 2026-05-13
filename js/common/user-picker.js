/**
 * Nextcloud user / group directory pickers for MobilityCheck.
 * Uses /api/admin/users and /api/admin/groups (fleet admin or fleet manager).
 */
(function (global) {
	'use strict';
	const C = global.MobilityCheckComponents;
	const API = global.MobilityCheckApi;
	const M = global.MobilityCheckMessaging;
	const t = M.t;

	function debounce(fn, ms) {
		let id;
		return function () {
			const a = arguments;
			const self = this;
			clearTimeout(id);
			id = setTimeout(() => fn.apply(self, a), ms);
		};
	}

	function defaultUserLabel(row) {
		const dn = row.displayName || row.id;
		return dn + (row.id && dn !== row.id ? ' (' + row.id + ')' : '');
	}

	/**
	 * @param {HTMLElement} mount
	 * @param {object} opts
	 * @param {string} opts.name
	 * @param {string} opts.idBase
	 * @param {boolean} [opts.required]
	 * @param {string} [opts.placeholder]
	 * @param {string} [opts.ariaDescribedBy]
	 * @param {boolean} [opts.allowClear]
	 * @param {(row: {id:string,displayName?:string,roles?:string[]}) => boolean} [opts.filterRow]
	 * @param {(row: {id:string,displayName?:string}) => string} [opts.formatLabel]
	 * @param {(q: string) => Promise<Array<{id:string,displayName?:string,roles?:string[]}>>} [opts.fetchUsers]
	 * @param {(uid: string) => void} [opts.onChange]
	 */
	function attachUserCombobox(mount, opts) {
		const idBase = opts.idBase;
		const comboId = idBase + '-combo';
		const listId = idBase + '-listbox';
		const hidden = C.h('input', { type: 'hidden', name: opts.name, id: idBase + '-val' });
		if (opts.required) hidden.setAttribute('required', 'required');
		const combo = C.h('input', {
			type: 'search',
			id: comboId,
			class: 'mc-user-picker__input',
			autocomplete: 'off',
			role: 'combobox',
			'aria-autocomplete': 'list',
			'aria-expanded': 'false',
			'aria-controls': listId,
			'aria-haspopup': 'listbox',
			placeholder: opts.placeholder || t('Start typing a name or user ID…'),
		});
		if (opts.ariaDescribedBy) combo.setAttribute('aria-describedby', opts.ariaDescribedBy);
		const list = C.h('ul', { id: listId, class: 'mc-user-picker__list', role: 'listbox', hidden: true });
		const wrap = C.h('div', { class: 'mc-user-picker' }, [hidden, combo, list]);
		if (opts.allowClear) {
			const clr = C.h('button', { type: 'button', class: 'button mc-user-picker__clear' }, t('Clear'));
			clr.addEventListener('click', () => {
				hidden.value = '';
				combo.value = '';
				delete combo.dataset.mcDn;
				list.hidden = true;
				combo.setAttribute('aria-expanded', 'false');
				hidden.dispatchEvent(new Event('input', { bubbles: true }));
				if (opts.onChange) opts.onChange('');
			});
			wrap.insertBefore(clr, list);
		}
		mount.innerHTML = '';
		mount.appendChild(wrap);

		const fetchFn = opts.fetchUsers || (async (q) => API.get('/api/admin/users', { search: q || '' }));
		const filter = opts.filterRow || (() => true);
		const format = opts.formatLabel || defaultUserLabel;
		let rows = [];
		let activeIdx = -1;

		function filteredRows() {
			return rows.filter(filter);
		}

		function closeList() {
			list.hidden = true;
			combo.setAttribute('aria-expanded', 'false');
			activeIdx = -1;
		}

		function openList() {
			if (!list.children.length) return;
			list.hidden = false;
			combo.setAttribute('aria-expanded', 'true');
		}

		function renderOptions() {
			list.innerHTML = '';
			const fr = filteredRows();
			if (!fr.length) {
				list.appendChild(C.h('li', { class: 'mc-user-picker__empty', role: 'presentation' }, t('No users match your search.')));
				return;
			}
			fr.forEach((row, i) => {
				list.appendChild(C.h('li', {
					class: 'mc-user-picker__option',
					role: 'option',
					id: listId + '-opt-' + i,
					tabIndex: -1,
					onMouseDown(ev) {
						ev.preventDefault();
						applySelection(row);
					},
				}, format(row)));
			});
		}

		function applySelection(row) {
			hidden.value = row.id;
			combo.dataset.mcDn = row.displayName || '';
			combo.value = format(row);
			closeList();
			hidden.dispatchEvent(new Event('input', { bubbles: true }));
			hidden.dispatchEvent(new Event('change', { bubbles: true }));
			if (opts.onChange) opts.onChange(row.id);
		}

		const runSearch = debounce(async () => {
			const q = combo.value.trim();
			try {
				rows = API.asArray(await fetchFn(q));
				renderOptions();
				openList();
			} catch (e) {
				rows = [];
				list.innerHTML = '';
				list.appendChild(C.h('li', { class: 'mc-user-picker__empty', role: 'presentation' }, t('Could not load users.')));
				openList();
				M.reportError(e);
			}
		}, 220);

		combo.addEventListener('input', () => {
			if (hidden.value) {
				const keep = format({ id: hidden.value, displayName: combo.dataset.mcDn || '' });
				if (combo.value !== keep) {
					hidden.value = '';
					delete combo.dataset.mcDn;
				}
			}
			runSearch();
		});

		combo.addEventListener('focus', () => {
			runSearch();
		});

		combo.addEventListener('keydown', (ev) => {
			const fr = filteredRows();
			const optsEls = Array.from(list.querySelectorAll('[role="option"]'));
			if (ev.key === 'Escape') {
				closeList();
				return;
			}
			if (ev.key === 'ArrowDown') {
				ev.preventDefault();
				if (list.hidden) openList();
				if (!optsEls.length) return;
				activeIdx = Math.min(activeIdx + 1, optsEls.length - 1);
				optsEls.forEach((n, j) => n.setAttribute('aria-selected', j === activeIdx ? 'true' : 'false'));
				optsEls[activeIdx].scrollIntoView({ block: 'nearest' });
			}
			if (ev.key === 'ArrowUp') {
				ev.preventDefault();
				activeIdx = Math.max(activeIdx - 1, 0);
				optsEls.forEach((n, j) => n.setAttribute('aria-selected', j === activeIdx ? 'true' : 'false'));
			}
			if (ev.key === 'Enter' && activeIdx >= 0 && fr[activeIdx]) {
				ev.preventDefault();
				applySelection(fr[activeIdx]);
			}
		});

		document.addEventListener('click', (ev) => {
			if (!wrap.contains(ev.target)) closeList();
		});

		return {
			get value() {
				return hidden.value;
			},
			set value(v) {
				hidden.value = v || '';
				combo.value = v || '';
				delete combo.dataset.mcDn;
			},
			clear() {
				hidden.value = '';
				combo.value = '';
				delete combo.dataset.mcDn;
				closeList();
			},
			focus() {
				combo.focus();
			},
		};
	}

	/** @param {HTMLElement} mount @param {HTMLTextAreaElement} textarea @param {{ kind: 'user'|'group', inputId: string }} opts */
	function attachMultiIdPicker(mount, textarea, opts) {
		const chips = C.h('div', { class: 'mc-chip-row', role: 'list' });
		const search = C.h('input', {
			type: 'search',
			id: opts.inputId,
			class: 'mc-user-picker__input',
			autocomplete: 'off',
			placeholder: opts.kind === 'group' ? t('Search groups…') : t('Search users…'),
		});
		const list = C.h('ul', { class: 'mc-user-picker__list mc-user-picker__list--inline', role: 'listbox', hidden: true });
		const box = C.h('div', { class: 'mc-user-picker' }, [search, list]);
		mount.innerHTML = '';
		mount.appendChild(chips);
		mount.appendChild(box);

		function idsFromTextarea() {
			return String(textarea.value || '')
				.split(/[\s,;\n]+/)
				.map((s) => s.trim())
				.filter(Boolean);
		}

		function syncTextarea(ids) {
			textarea.value = ids.join('\n');
			textarea.dispatchEvent(new Event('input', { bubbles: true }));
			textarea.dispatchEvent(new Event('change', { bubbles: true }));
		}

		function renderChips(ids) {
			chips.innerHTML = '';
			ids.forEach((id) => {
				chips.appendChild(C.h('div', { class: 'mc-chip', role: 'listitem' }, [
					C.h('span', { class: 'mc-chip__label' }, id),
					C.h('button', {
						type: 'button',
						class: 'mc-chip__remove',
						'aria-label': t('Remove {id}', { id }),
						onClick() {
							const next = idsFromTextarea().filter((x) => x !== id);
							syncTextarea(next);
							renderChips(next);
						},
					}, '×'),
				]));
			});
		}

		function syncFromTextarea() {
			renderChips(idsFromTextarea());
		}

		const fetchRows = debounce(async () => {
			const q = search.value.trim();
			try {
				const raw = opts.kind === 'group'
					? await API.get('/api/admin/groups', { search: q || '' })
					: await API.get('/api/admin/users', { search: q || '' });
				list.innerHTML = '';
				const taken = new Set(idsFromTextarea());
				API.asArray(raw).forEach((row) => {
					const id = row.id || row.gid;
					if (!id || taken.has(id)) return;
					const label = opts.kind === 'group'
						? ((row.displayName || id) + (row.displayName && row.displayName !== id ? ' (' + id + ')' : ''))
						: defaultUserLabel(row);
					list.appendChild(C.h('li', {
						class: 'mc-user-picker__option',
						role: 'option',
						tabIndex: -1,
						onMouseDown(ev) {
							ev.preventDefault();
							const next = idsFromTextarea().concat([id]);
							syncTextarea(next);
							renderChips(next);
							search.value = '';
							list.hidden = true;
						},
					}, label));
				});
				if (!list.children.length) {
					list.appendChild(C.h('li', { class: 'mc-user-picker__empty', role: 'presentation' }, t('No matches.')));
				}
				list.hidden = false;
			} catch (e) {
				M.reportError(e);
			}
		}, 220);

		search.addEventListener('input', fetchRows);
		search.addEventListener('focus', fetchRows);
		document.addEventListener('click', (ev) => {
			if (!box.contains(ev.target)) list.hidden = true;
		});

		syncFromTextarea();
		return { syncFromTextarea };
	}

	global.MobilityCheckUserPicker = {
		attachUserCombobox,
		attachMultiIdPicker,
		defaultUserLabel,
	};
})(window);
