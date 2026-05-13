/**
 * MobilityCheck API client.
 *
 * Wraps `fetch` with:
 *  - Same-origin credentials + automatic CSRF header on writes
 *  - JSON serialisation of the request body
 *  - Stable error contract: throws a {code, type, context, status} Error
 *  - Optional `signal` (AbortController) propagation for cancellable loads
 *  - Centralised redirect to login when the session is gone (401)
 *
 * The server contract is `{ ok: true, data }` for success and
 * `{ ok: false, error: { code, type, context } }` for failure
 * (see `ApiJsonErrorResponse`).
 */
(function () {
	'use strict';

	function requestToken() {
		if (window.OC && typeof OC.requestToken === 'string') {
			return OC.requestToken;
		}
		const el = document.querySelector('meta[name="requesttoken"]');
		return el ? el.getAttribute('content') || '' : '';
	}

	function makeError(code, type, context, status) {
		const err = new Error(code || 'REQUEST_FAILED');
		err.code = code || 'REQUEST_FAILED';
		err.type = type || 'unknown';
		err.context = context || {};
		err.status = status || 0;
		return err;
	}

	async function parseJson(response) {
		try {
			return await response.json();
		} catch (_) {
			return null;
		}
	}

	/**
	 * If JSON decoded a sequential list as an object with numeric keys (some
	 * stacks do this), normalise to a real Array. Leaves other objects unchanged.
	 *
	 * @param {unknown} payload
	 * @returns {unknown}
	 */
	function coerceNumericKeyedList(payload) {
		if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
			return payload;
		}
		const keys = Object.keys(payload);
		if (keys.length === 0 || !keys.every((k) => /^\d+$/.test(k))) {
			return payload;
		}
		return keys.sort((a, b) => Number(a) - Number(b)).map((k) => payload[k]);
	}

	/** Safe for list endpoints after unwrap — non-arrays become `[]`. */
	function asArray(maybe) {
		const v = coerceNumericKeyedList(maybe);
		return Array.isArray(v) ? v : [];
	}

	async function request(url, options) {
		const opts = Object.assign({ credentials: 'same-origin' }, options || {});
		opts.headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});
		if (opts.body && !opts.headers['Content-Type']) {
			opts.headers['Content-Type'] = 'application/json';
		}
		const method = (opts.method || 'GET').toUpperCase();
		if (method !== 'GET' && method !== 'HEAD') {
			opts.headers.requesttoken = requestToken();
		}
		let response;
		try {
			response = await fetch(url, opts);
		} catch (e) {
			throw makeError('NETWORK_ERROR', 'network', { message: String(e && e.message || e) }, 0);
		}
		if (response.status === 401) {
			window.location.href = (window.OC && OC.generateUrl) ? OC.generateUrl('/login') : '/index.php/login';
			throw makeError('UNAUTHENTICATED', 'auth', {}, 401);
		}
		const data = await parseJson(response);
		if (!response.ok || (data && data.ok === false)) {
			const err = data && data.error ? data.error : {};
			throw makeError(err.code, err.type, err.context, response.status);
		}
		// 2xx: unwrap `{ ok: true, data: … }` when present; never return null (avoids
		// TypeError in callers like `data.manager` on dashboard).
		let payload = data;
		if (payload && typeof payload === 'object' && payload.ok === true && Object.prototype.hasOwnProperty.call(payload, 'data')) {
			payload = payload.data;
		}
		payload = coerceNumericKeyedList(payload);
		if (payload === undefined || payload === null) {
			return {};
		}
		return payload;
	}

	function buildQuery(params) {
		if (!params) return '';
		const usp = new URLSearchParams();
		Object.keys(params).forEach((k) => {
			const v = params[k];
			if (v === undefined || v === null || v === '') return;
			if (Array.isArray(v)) {
				v.forEach((item) => usp.append(k + '[]', String(item)));
			} else {
				usp.append(k, String(v));
			}
		});
		const s = usp.toString();
		return s ? '?' + s : '';
	}

	const api = {
		asArray,
		appBase: '/index.php/apps/mobilitycheck',
		url(path) {
			if (/^https?:/.test(path)) return path;
			if (path.startsWith('/')) return this.appBase + path;
			return this.appBase + '/' + path;
		},
		request,
		get(path, params, init) {
			const url = this.url(path) + buildQuery(params);
			return request(url, Object.assign({ method: 'GET' }, init || {}));
		},
		post(path, body, init) {
			return request(this.url(path), Object.assign({
				method: 'POST',
				body: JSON.stringify(body || {}),
			}, init || {}));
		},
		put(path, body, init) {
			return request(this.url(path), Object.assign({
				method: 'PUT',
				body: JSON.stringify(body || {}),
			}, init || {}));
		},
		delete(path, init) {
			return request(this.url(path), Object.assign({ method: 'DELETE' }, init || {}));
		},
		upload(path, file, extra) {
			const fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
			}
			return request(this.url(path), {
				method: 'POST',
				body: fd,
				headers: { Accept: 'application/json', requesttoken: requestToken() },
			});
		},
	};

	window.MobilityCheckApi = api;
})();
