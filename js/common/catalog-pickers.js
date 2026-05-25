(function () {
	'use strict';

	const API = window.MobilityCheckApi;
	const M = window.MobilityCheckMessaging;

	const TZ_MAX_VISIBLE = 80;

	function t(key, params) {
		if (M && typeof M.t === 'function') {
			return M.t(key, params);
		}
		if (window.t) {
			return window.t(key, params || {});
		}
		return key;
	}

	function setStatus(el, message, isError) {
		if (!el) {
			return;
		}
		const text = message || '';
		el.textContent = text;
		el.hidden = text === '';
		el.classList.toggle('mc-catalog-picker__status--error', Boolean(isError));
	}

	function normalizeQuery(q) {
		return String(q || '').trim().toLowerCase();
	}

	function wireCombobox(root, config) {
		if (root._mcPickerApi) {
			return root._mcPickerApi;
		}
		const select = root.querySelector('.mc-catalog-picker__native');
		const input = root.querySelector('.mc-catalog-picker__input');
		const results = root.querySelector('.mc-catalog-picker__results');
		const status = root.querySelector('.mc-catalog-picker__status');
		const clearBtn = root.querySelector('.mc-catalog-picker__clear');
		if (!select || !input || !results) {
			return null;
		}

		const isRequired = select.hasAttribute('required');
		const readOnly = Boolean(config.readOnly) || input.disabled || select.disabled;

		let activeIndex = -1;
		let visibleItems = [];

		function closeResults() {
			results.hidden = true;
			results.replaceChildren();
			visibleItems = [];
			activeIndex = -1;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
		}

		function updateClearButton() {
			if (!clearBtn) {
				return;
			}
			clearBtn.hidden = readOnly || isRequired || select.value === '';
		}

		function applySelection(item, dispatchChange) {
			if (!item) {
				select.value = '';
				input.value = '';
			} else {
				config.ensureNativeOption(select, item);
				select.value = item.value;
				input.value = item.label;
			}
			closeResults();
			setStatus(status, '', false);
			updateClearButton();
			if (dispatchChange !== false) {
				select.dispatchEvent(new Event('change', { bubbles: true }));
			}
		}

		function renderResults(query) {
			visibleItems = config.collectOptions(query);
			results.replaceChildren();
			activeIndex = -1;
			if (!visibleItems.length) {
				const empty = document.createElement('li');
				empty.className = 'mc-catalog-picker__empty';
				empty.setAttribute('role', 'presentation');
				empty.textContent = config.emptyMessage(query);
				results.appendChild(empty);
				results.hidden = false;
				input.setAttribute('aria-expanded', 'true');
				return;
			}

			let lastGroup = null;
			visibleItems.forEach((item, index) => {
				if (item.group && item.group !== lastGroup) {
					lastGroup = item.group;
					const heading = document.createElement('li');
					heading.className = 'mc-catalog-picker__group';
					heading.setAttribute('role', 'presentation');
					heading.textContent = item.group;
					results.appendChild(heading);
				}
				const li = document.createElement('li');
				li.className = 'mc-catalog-picker__option';
				li.id = `${config.optionIdPrefix}-${index}`;
				li.setAttribute('role', 'option');
				li.setAttribute('aria-selected', 'false');
				li.tabIndex = -1;
				config.renderOption(li, item);
				li.addEventListener('mousedown', (event) => {
					event.preventDefault();
					applySelection(item);
				});
				results.appendChild(li);
			});

			if (config.truncatedMessage && config.isTruncated(query, visibleItems.length)) {
				setStatus(status, config.truncatedMessage(visibleItems.length), false);
			} else {
				setStatus(status, '', false);
			}

			results.hidden = false;
			input.setAttribute('aria-expanded', 'true');
		}

		function optionElements() {
			return Array.from(results.querySelectorAll('.mc-catalog-picker__option'));
		}

		function highlightIndex(next) {
			const opts = optionElements();
			if (!opts.length) {
				return;
			}
			activeIndex = Math.max(0, Math.min(next, opts.length - 1));
			opts.forEach((el, i) => {
				const on = i === activeIndex;
				el.setAttribute('aria-selected', on ? 'true' : 'false');
				if (on) {
					input.setAttribute('aria-activedescendant', el.id);
					el.scrollIntoView({ block: 'nearest' });
				}
			});
		}

		function setValue(value) {
			const trimmed = String(value || '').trim();
			if (!trimmed) {
				applySelection(null, false);
				return;
			}
			const indexed = config.index.get(config.normalizeValue(trimmed));
			if (indexed) {
				applySelection(indexed, false);
				return;
			}
			applySelection(config.fallbackItem(trimmed), false);
		}

		function reset() {
			setValue(config.defaultValue);
		}

		input.addEventListener('focus', () => renderResults(input.value));
		input.addEventListener('input', () => renderResults(input.value));
		input.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') {
				closeResults();
				setStatus(status, '', false);
				return;
			}
			if (event.key === 'ArrowDown') {
				event.preventDefault();
				if (results.hidden) {
					renderResults(input.value);
				}
				highlightIndex(activeIndex + 1);
				return;
			}
			if (event.key === 'ArrowUp') {
				event.preventDefault();
				if (results.hidden) {
					renderResults(input.value);
				}
				highlightIndex(activeIndex <= 0 ? 0 : activeIndex - 1);
				return;
			}
			if (event.key === 'Enter' && !results.hidden && activeIndex >= 0) {
				event.preventDefault();
				const item = visibleItems[activeIndex];
				if (item) {
					applySelection(item);
				}
			}
		});
		input.addEventListener('blur', () => {
			window.setTimeout(() => {
				if (!root.contains(document.activeElement)) {
					closeResults();
					if (select.value) {
						const item = config.index.get(config.normalizeValue(select.value));
						if (item) {
							input.value = item.label;
						}
					}
				}
			}, 160);
		});
		clearBtn?.addEventListener('click', () => {
			applySelection(null);
			input.focus();
		});
		document.addEventListener('click', (event) => {
			if (!root.contains(event.target)) {
				closeResults();
			}
		});

		reset();

		const api = {
			setValue,
			getValue: () => String(select.value || '').trim(),
			reset,
			select,
			input,
		};
		root._mcPickerApi = api;
		return api;
	}

	function formatTimezoneOffset(tz, when) {
		try {
			const parts = new Intl.DateTimeFormat('en', {
				timeZone: tz,
				timeZoneName: 'shortOffset',
			}).formatToParts(when || new Date());
			const hit = parts.find((p) => p.type === 'timeZoneName');
			return hit ? hit.value : '';
		} catch (_) {
			return '';
		}
	}

	function buildTimezoneIndex(catalog) {
		const index = new Map();
		const seen = new Set();
		const pinnedLabel = t('Common choices');
		const add = (value, group) => {
			if (!value || seen.has(value)) {
				return;
			}
			seen.add(value);
			const offset = formatTimezoneOffset(value);
			index.set(value, {
				value,
				label: offset ? `${value} (${offset})` : value,
				group,
			});
		};
		(catalog.pinned || []).forEach((tz) => add(String(tz), pinnedLabel));
		(catalog.groups || []).forEach((g) => {
			const group = String(g.label || '');
			(g.items || []).forEach((tz) => add(String(tz), group));
		});
		return { index, pinnedLabel, catalog };
	}

	function timezoneOrderedEntries(state) {
		const out = [];
		(state.catalog.pinned || []).forEach((tz) => {
			const item = state.index.get(String(tz));
			if (item) {
				out.push(item);
			}
		});
		(state.catalog.groups || []).forEach((g) => {
			(g.items || []).forEach((tz) => {
				const item = state.index.get(String(tz));
				if (item && item.group !== state.pinnedLabel) {
					out.push(item);
				}
			});
		});
		return out;
	}

	/**
	 * @param {HTMLElement} root
	 * @param {{ pinned?: string[], groups?: {label:string,items:string[]}[] }} catalog
	 * @param {{ defaultTimezone?: string }} [config]
	 */
	function attachTimezone(root, catalog, config) {
		if (!root || !catalog) {
			return null;
		}
		if (root._mcPickerApi) {
			if (config?.defaultTimezone) {
				root._mcPickerApi.setValue(config.defaultTimezone);
			}
			return root._mcPickerApi;
		}
		const state = buildTimezoneIndex(catalog);
		const defaultValue = String(
			config?.defaultTimezone
			|| root.getAttribute('data-default-timezone')
			|| 'UTC',
		).trim() || 'UTC';

		return wireCombobox(root, {
			index: state.index,
			defaultValue,
			readOnly: root.querySelector('.mc-catalog-picker__input')?.disabled === true,
			optionIdPrefix: 'mc-tz-opt',
			normalizeValue: (v) => v,
			ensureNativeOption(select, item) {
				let opt = Array.from(select.options).find((o) => o.value === item.value);
				if (!opt) {
					opt = document.createElement('option');
					opt.value = item.value;
					opt.textContent = item.label;
					select.appendChild(opt);
				}
				return opt;
			},
			collectOptions(query) {
				const q = normalizeQuery(query);
				if (!q) {
					return timezoneOrderedEntries(state).filter((item) => item.group === state.pinnedLabel);
				}
				const out = [];
				for (const item of timezoneOrderedEntries(state)) {
					const hay = `${item.value} ${item.label} ${item.group}`.toLowerCase();
					if (hay.includes(q)) {
						out.push(item);
					}
					if (out.length >= TZ_MAX_VISIBLE) {
						break;
					}
				}
				return out;
			},
			emptyMessage(query) {
				return normalizeQuery(query)
					? t('No matching timezones. Try a city or region name.')
					: t('Type to search all IANA timezones.');
			},
			isTruncated(query, count) {
				return normalizeQuery(query) && count >= TZ_MAX_VISIBLE;
			},
			truncatedMessage(count) {
				return t('Showing the first {count} matches. Keep typing to narrow the list.').replace('{count}', String(count));
			},
			fallbackItem(value) {
				const offset = formatTimezoneOffset(value);
				return { value, label: offset ? `${value} (${offset})` : value, group: '' };
			},
			renderOption(li, item) {
				const primary = document.createElement('span');
				primary.className = 'mc-catalog-picker__option-primary';
				primary.textContent = item.value;
				li.appendChild(primary);
				const offset = formatTimezoneOffset(item.value);
				if (offset) {
					const secondary = document.createElement('span');
					secondary.className = 'mc-catalog-picker__option-secondary';
					secondary.textContent = offset;
					li.appendChild(secondary);
				}
			},
		});
	}

	function buildCurrencyIndex(catalog) {
		const index = new Map();
		const seen = new Set();
		const pinnedLabel = t('Common choices');
		const add = (entry, group) => {
			const code = String(entry.code || '').toUpperCase();
			if (!code || seen.has(code)) {
				return;
			}
			seen.add(code);
			const decimals = Number(entry.decimals);
			const hint = Number.isFinite(decimals) && decimals !== 2
				? t('{decimals} decimal places').replace('{decimals}', String(decimals))
				: '';
			index.set(code, {
				value: code,
				label: code,
				group,
				decimals,
				hint,
			});
		};
		(catalog.pinned || []).forEach((code) => {
			const entry = { code: String(code), decimals: 2 };
			const fromGroup = findCurrencyEntry(catalog, code);
			if (fromGroup) {
				add(fromGroup, pinnedLabel);
			} else {
				add(entry, pinnedLabel);
			}
		});
		(catalog.groups || []).forEach((g) => {
			const group = String(g.label || '');
			(g.items || []).forEach((entry) => add(entry, group));
		});
		return { index, pinnedLabel, catalog };
	}

	function findCurrencyEntry(catalog, code) {
		const want = String(code).toUpperCase();
		for (const g of catalog.groups || []) {
			for (const entry of g.items || []) {
				if (String(entry.code).toUpperCase() === want) {
					return entry;
				}
			}
		}
		return null;
	}

	function currencyOrderedEntries(state) {
		const out = [];
		(state.catalog.pinned || []).forEach((code) => {
			const item = state.index.get(String(code).toUpperCase());
			if (item) {
				out.push(item);
			}
		});
		(state.catalog.groups || []).forEach((g) => {
			(g.items || []).forEach((entry) => {
				const item = state.index.get(String(entry.code).toUpperCase());
				if (item && item.group !== state.pinnedLabel) {
					out.push(item);
				}
			});
		});
		return out;
	}

	/**
	 * @param {HTMLElement} root
	 * @param {{ pinned?: string[], groups?: {label:string,items:{code:string,decimals:number}[]}[] }} catalog
	 * @param {{ defaultCurrency?: string }} [config]
	 */
	function attachCurrency(root, catalog, config) {
		if (!root || !catalog) {
			return null;
		}
		if (root._mcPickerApi) {
			if (config?.defaultCurrency) {
				root._mcPickerApi.setValue(config.defaultCurrency);
			}
			return root._mcPickerApi;
		}
		const state = buildCurrencyIndex(catalog);
		const defaultValue = String(
			config?.defaultCurrency
			|| root.getAttribute('data-default-currency')
			|| 'EUR',
		).trim().toUpperCase() || 'EUR';

		return wireCombobox(root, {
			index: state.index,
			defaultValue,
			readOnly: root.querySelector('.mc-catalog-picker__input')?.disabled === true,
			optionIdPrefix: 'mc-cur-opt',
			normalizeValue: (v) => String(v).toUpperCase(),
			ensureNativeOption(select, item) {
				let opt = Array.from(select.options).find((o) => o.value === item.value);
				if (!opt) {
					opt = document.createElement('option');
					opt.value = item.value;
					opt.textContent = item.value;
					select.appendChild(opt);
				}
				return opt;
			},
			collectOptions(query) {
				const q = normalizeQuery(query);
				if (!q) {
					return currencyOrderedEntries(state).filter((item) => item.group === state.pinnedLabel);
				}
				const out = [];
				for (const item of currencyOrderedEntries(state)) {
					const hay = `${item.value} ${item.hint || ''} ${item.group}`.toLowerCase();
					if (hay.includes(q)) {
						out.push(item);
					}
				}
				return out;
			},
			emptyMessage(query) {
				return normalizeQuery(query)
					? t('No matching currencies. Try another ISO code.')
					: t('Type to search supported currencies.');
			},
			isTruncated() {
				return false;
			},
			truncatedMessage() {
				return '';
			},
			fallbackItem(value) {
				const code = String(value).toUpperCase();
				return { value: code, label: code, group: '', hint: '' };
			},
			renderOption(li, item) {
				const primary = document.createElement('span');
				primary.className = 'mc-catalog-picker__option-primary';
				primary.textContent = item.value;
				li.appendChild(primary);
				if (item.hint) {
					const secondary = document.createElement('span');
					secondary.className = 'mc-catalog-picker__option-secondary';
					secondary.textContent = item.hint;
					li.appendChild(secondary);
				}
			},
		});
	}

	let timezoneCatalogCache = null;
	let currencyCatalogCache = null;

	async function loadTimezoneCatalog() {
		if (timezoneCatalogCache) {
			return timezoneCatalogCache;
		}
		timezoneCatalogCache = await API.get('/api/catalog/timezones');
		return timezoneCatalogCache;
	}

	async function loadCurrencyCatalog() {
		if (currencyCatalogCache) {
			return currencyCatalogCache;
		}
		currencyCatalogCache = await API.get('/api/catalog/currencies');
		return currencyCatalogCache;
	}

	window.MobilityCheckCatalogPickers = {
		attachTimezone,
		attachCurrency,
		loadTimezoneCatalog,
		loadCurrencyCatalog,
	};
})();
