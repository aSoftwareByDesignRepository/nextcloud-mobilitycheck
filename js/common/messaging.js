/**
 * MobilityCheck user messaging.
 *
 *  - polite()  → updates #mc-live-region (aria-live="polite") for routine status
 *  - alert()   → updates #mc-alert-region (aria-live="assertive") AND shows
 *                an accessible toast for critical errors
 *  - toast()   → optional visual toast (5 s timeout, dismissable via Escape)
 *  - resolveError() → maps a thrown API error code to a human, translated string
 *
 * Translation is handled via the {@see window.t} helper exposed by Nextcloud
 * when `Util::addScript` is used. We register through `t('mobilitycheck', '…')`
 * so messages are translatable in `l10n/*.json`.
 */
(function () {
	'use strict';

	const t = (key, params) => {
		if (window.t) return window.t('mobilitycheck', key, params || {});
		return key;
	};

	let toastTimer = null;
	function toast(message, kind) {
		const root = document.body;
		if (!root) return;
		const existing = document.getElementById('mc-toast');
		if (existing) existing.remove();
		const el = document.createElement('div');
		el.id = 'mc-toast';
		el.className = 'mc-toast mc-toast--' + (kind || 'info');
		el.setAttribute('role', kind === 'critical' ? 'alert' : 'status');
		el.setAttribute('aria-live', kind === 'critical' ? 'assertive' : 'polite');
		el.textContent = message;
		root.appendChild(el);
		if (toastTimer) clearTimeout(toastTimer);
		toastTimer = setTimeout(() => el.remove(), kind === 'critical' ? 9000 : 5000);
	}

	function polite(message) {
		const node = document.getElementById('mc-live-region');
		if (node) {
			node.textContent = '';
			setTimeout(() => { node.textContent = message; }, 30);
		}
	}

	function alertMsg(message) {
		const node = document.getElementById('mc-alert-region');
		if (node) {
			node.textContent = '';
			setTimeout(() => { node.textContent = message; }, 30);
		}
		toast(message, 'critical');
	}

	function setPageError(message) {
		let el = document.getElementById('mc-page-error');
		if (!el) {
			el = document.createElement('div');
			el.id = 'mc-page-error';
			el.className = 'mc-page-error';
			el.setAttribute('role', 'alert');
			const main = document.getElementById('mc-main-content');
			if (main) main.insertBefore(el, main.firstChild);
		}
		if (!message) {
			el.hidden = true;
			el.textContent = '';
			return;
		}
		el.hidden = false;
		el.textContent = message;
	}

	const errorMap = {
		NOT_FOUND: () => t('The record you requested no longer exists.'),
		VEHICLE_NOT_FOUND: () => t('This vehicle no longer exists.'),
		DRIVER_NOT_FOUND: () => t('This driver profile no longer exists.'),
		BOOKING_NOT_FOUND: () => t('This booking no longer exists.'),
		DAMAGE_NOT_FOUND: () => t('This damage report no longer exists.'),
		DECOMMISSION_REASON_REQUIRED: () => t('A reason is required.'),
		DECOMMISSION_REASON_TOO_LONG: (ctx) => t('Decommission reason may be at most {max} characters.', { max: (ctx && ctx.max != null) ? ctx.max : 8000 }),
		COMPLETED_DATE_IN_FUTURE: () => t('Completion date cannot be in the future.'),
		DATE_INVALID: () => t('The date format is invalid.'),
		INSTRUCTION_ALREADY_RECORDED: () => t('An instruction record already exists for this driver and year.'),
		INSTRUCTION_REFERENCE_TOO_LONG: (ctx) => t('Reference may be at most {max} characters.', { max: (ctx && ctx.max != null) ? ctx.max : 120 }),
		LICENCE_EXPIRY_REQUIRED: () => t('A licence expiry date is required before verification.'),
		LICENCE_REJECT_REASON_REQUIRED: () => t('A reason is required.'),
		LICENCE_REJECT_REASON_TOO_LONG: (ctx) => t('Rejection reason may be at most {max} characters.', { max: (ctx && ctx.max != null) ? ctx.max : 8000 }),
		LICENCE_VERIFICATION_NOTE_TOO_LONG: (ctx) => t('Notes may be at most {max} characters.', { max: (ctx && ctx.max != null) ? ctx.max : 8000 }),
		YEAR_INVALID: () => t('The selected year is invalid.'),
		ACCESS_DENIED: () => t('You do not have permission to perform this action.'),
		FORBIDDEN: () => t('You do not have permission to perform this action.'),
		BOOKING_CONFLICT: (ctx) => t('Another booking overlaps the selected timeframe (booking #{id}).', { id: (ctx && ctx.competingBookingId) || '?' }),
		VALIDATION_FAILED: (ctx) => t('Please review the highlighted fields.') + (ctx && ctx.field ? ' (' + ctx.field + ')' : ''),
		MONEY_INVALID: () => t('Please enter a valid amount, for example 12,34.'),
		MONEY_NEGATIVE_NOT_ALLOWED: () => t('Negative amounts are not allowed.'),
		VAT_RATE_INVALID: () => t('Please select a valid VAT rate.'),
		NOT_ELIGIBLE: (ctx) => {
			const reasons = (ctx && Array.isArray(ctx.reasons) ? ctx.reasons : []).join(', ');
			return t('You are not eligible for this vehicle.') + (reasons ? ' (' + reasons + ')' : '');
		},
		NETWORK_ERROR: () => t('The server could not be reached. Please check your connection and try again.'),
		REQUEST_FAILED: () => t('The request could not be completed. Please try again.'),
		UNAUTHENTICATED: () => t('Your session has expired. Please sign in again.'),
		SAFETY_CRITICAL_ACK_REQUIRED: () => t('Safety-critical closure requires the acknowledgement checkbox and a detailed reason.'),
		BOOKING_READ_ONLY: () => t('This booking can no longer be edited.'),
		CANNOT_CHANGE_BOOKING_VEHICLE: () => t('Only a fleet manager may change the vehicle on an existing booking.'),
		CANNOT_EDIT_APPROVED_BOOKING: () => t('Only a fleet manager may change an approved booking.'),
		CANNOT_EDIT_BOOKING: () => t('You may not edit this booking.'),
		CANNOT_EXTEND_BOOKING: () => t('You may not extend this booking.'),
		BOOKING_IMMUTABLE_AFTER_CHECKOUT: () => t('This booking cannot be changed after check-out has been recorded.'),
		BOOKING_NOT_ACTIVE_FOR_EXTEND: () => t('Only an active booking (after check-out) can be extended.'),
		BOOKING_NOT_APPROVED_FOR_CHECKOUT: () => t('This booking is not approved for check-out. Refresh the page if it may have just changed.'),
		BOOKING_NOT_ACTIVE_FOR_CHECKIN: () => t('This booking is not active for check-in. Refresh the page if it may already be completed.'),
		CHECKOUT_MISSING: () => t('No check-out record exists for this booking. Ask a fleet administrator to repair the data.'),
		CHECKOUT_NOT_ALLOWED: () => t('You are not allowed to check out this booking.'),
		CHECKIN_NOT_ALLOWED: () => t('You are not allowed to check in this booking.'),
		ODOMETER_REQUIRED: () => t('Enter the odometer reading in whole kilometres.'),
		ODOMETER_REGRESSION: (ctx) => {
			if (ctx && ctx.checkout != null) {
				return t('The odometer must be at least {km} km (check-out reading).', { km: String(ctx.checkout) });
			}
			if (ctx && ctx.current != null) {
				return t('The odometer must be at least {km} km (vehicle record).', { km: String(ctx.current) });
			}
			return t('The odometer reading is lower than allowed.');
		},
		FUEL_LEVEL_INVALID: () => t('Choose a valid fuel level.'),
		VEHICLE_DECOMMISSIONED: () => t('This vehicle is decommissioned and cannot be checked out.'),
		VEHICLE_IN_MAINTENANCE: () => t('This vehicle is currently out of service and cannot be booked or checked out.'),
		BUSINESS_ONLY_DECLARATION_REQUIRED: () => t('You must confirm business-only use for this pool or group vehicle.'),
		BOOKING_NOT_PENDING_LINE_MANAGER: () => t('This action only applies while the booking is awaiting line manager approval.'),
		EXTEND_NOT_LATER: () => t('The new end time must be later than the current end.'),
		EXTEND_EXCEEDS_CAP: (ctx) => t('The extension exceeds the maximum of {minutes} minutes set by your fleet manager.', { minutes: (ctx && ctx.maxMinutes) || '?' }),
		LINE_MANAGER_SELF: () => t('A line manager cannot supervise themselves.'),
		LINE_MANAGER_REQUIRED: () => t('Please specify the new line manager.'),
		LINE_MANAGER_SELF_APPROVAL_FORBIDDEN: () => t('You cannot approve your own booking. A different approver must sign off.'),
		USE_OVERRIDE_LINE_MANAGER: () => t('Please use the “Override line manager” action so the line manager is notified.'),
		REASON_REQUIRED: () => t('A reason is required.'),
		OVERRIDE_REASON_TOO_SHORT: (ctx) => t('The bypass reason must be at least {count} characters. Please document why the line-manager step is being skipped.', { count: String((ctx && ctx.min) || 10) }),
		REASON_TOO_LONG: () => t('The reason is too long. Please shorten it to 500 characters or fewer.'),
		RETURN_LOCATION_REQUIRED: () => t('For pool and group vehicles you must enter where you left the vehicle (at least a few characters) so the next colleague can find it.'),

		// Logbook ───────────────────────────────────────────────────────
		LOGBOOK_MODULE_DISABLED: () => t('The Fahrtenbuch module is disabled. Ask your administrator to enable it in MobilityCheck settings.'),
		LOGBOOK_ENTRY_NOT_FOUND: () => t('This logbook entry no longer exists.'),
		LOGBOOK_CONFIRMED_READONLY: () => t('Confirmed logbook entries are immutable. Create an amendment to correct them.'),
		LOGBOOK_ALREADY_CONFIRMED: () => t('This entry has already been confirmed.'),
		LOGBOOK_ATTEST_REQUIRED: () => t('Please tick the box confirming that the entry is correct and complete before submitting.'),
		LOGBOOK_AMEND_ONLY_CONFIRMED: () => t('Only a confirmed entry can be amended. Edit the draft instead.'),
		LOGBOOK_MANUAL_REQUIRES_LOGBOOK_ASSIGNMENT: () => t('Manual entries are only allowed for vehicles assigned to you under the Fahrtenbuch tax method.'),
		AMENDMENT_REASON_REQUIRED: () => t('Please describe why the entry is being amended.'),
		ODOMETER_DISTANCE_INVALID: () => t('The end odometer reading must be greater than or equal to the start reading.'),
		ODOMETER_CHAIN_BROKEN: (ctx) => t('The odometer is lower than the last confirmed entry (previous end: {prev} km).', { prev: (ctx && ctx.previousEndKm) || '?' }),
		DISTANCE_MISMATCH: () => t('Distance does not match the start/end odometer.'),
		DATE_RANGE_INVALID: () => t('Please choose valid start and end dates.'),
		TRIP_TYPE_REQUIRED: () => t('Please choose business, commute or private.'),
		TRIP_TYPE_INVALID: () => t('Trip type is invalid.'),
		TRIP_DATE_REQUIRED: () => t('Please provide a trip date.'),
		TRIP_DATE_INVALID: () => t('Trip date is invalid.'),
		TIME_INVALID: () => t('Time must be in HH:MM format.'),
		BUSINESS_TIMES_REQUIRED: () => t('Business trips require both a departure and an arrival time.'),
		START_ADDRESS_REQUIRED: () => t('Please provide a starting location.'),
		END_ADDRESS_REQUIRED: () => t('Please provide a destination.'),
		PURPOSE_REQUIRED: () => t('A short purpose statement is mandatory.'),
		COMMUTE_PURPOSE_REQUIRED: () => t('For commute entries a short note is required (e.g. “Home → Office”).'),
		CLIENT_OR_CONTACT_REQUIRED: () => t('For business trips please name the client or contact you visited.'),
		CANNOT_EDIT_LOGBOOK_ENTRY: () => t('You may not edit this logbook entry.'),
		CANNOT_CONFIRM_LOGBOOK_ENTRY: () => t('You may not confirm this logbook entry.'),
		CANNOT_AMEND_LOGBOOK_ENTRY: () => t('You may not amend this logbook entry.'),
		CANNOT_VIEW_LOGBOOK_ENTRY: () => t('You may not view this logbook entry.'),
		VEHICLE_REQUIRED: () => t('Please choose a vehicle.'),

		// Reimbursement claims ──────────────────────────────────────────
		REIMBURSEMENT_MODULE_DISABLED: () => t('The reimbursement module is disabled. Ask your administrator to enable it in MobilityCheck settings.'),
		REIMBURSEMENT_CLAIM_NOT_FOUND: () => t('This claim no longer exists.'),
		CLAIM_NOT_DRAFT: () => t('Only draft claims can be edited.'),
		CLAIM_NOT_SUBMITTED: () => t('This claim has not been submitted yet, or has already been decided.'),
		CLAIM_NOT_APPROVED: () => t('This claim is not in the approved state.'),
		CANNOT_EDIT_CLAIM: () => t('You may not edit this claim.'),
		CANNOT_SUBMIT_CLAIM: () => t('You may not submit this claim.'),
		CANNOT_VIEW_CLAIM: () => t('You may not view this claim.'),
		ADDRESS_REQUIRED: () => t('Please enter the start and end address (at least three characters each).'),
		DISTANCE_REQUIRED: () => t('Please enter a positive distance in kilometres.'),
		PURPOSE_TOO_SHORT: () => t('The purpose statement is too short. Please be specific.'),
		PRIVATE_VEHICLE_REQUIRED: () => t('Please select one of your registered private vehicles.'),
		PRIVATE_VEHICLE_NOT_FOUND: () => t('This private vehicle no longer exists.'),
		PRIVATE_VEHICLE_FIELDS_REQUIRED: () => t('Please fill in make, model and licence plate.'),
		ENGINE_TYPE_INVALID: () => t('Please select a valid engine type.'),
		PASSENGER_USER_IDS_LIMIT: () => t('You can link at most four passenger accounts to one booking.'),
		PASSENGER_USER_IDS_MANAGERS_ONLY: () => t('Only fleet managers may link passenger accounts to a booking.'),
		PAYMENT_REFERENCE_REQUIRED: () => t('A payment reference is required.'),
		JURISDICTION_INVALID: () => t('Please enter a valid jurisdiction code (e.g. DE).'),
		REIMBURSEMENT_NO_RATE_FOR_DATE: () => t('No reimbursement rate is active for the selected date and jurisdiction. Ask your fleet admin to configure one.'),

		// Exports / vehicle assignments ─────────────────────────────────
		ASSIGNMENT_MODE_INVALID: () => t('Choose pool, group or dedicated as the allocation mode.'),
		TAX_TREATMENT_INVALID: () => t('Choose a valid tax / payroll method.'),
		ASSIGNMENT_VALID_FROM_CONFLICT: () => t('The start date conflicts with an open allocation that begins on or after that date. End the current period first, or pick a start date strictly after the open allocation began.'),
		GROUP_ID_REQUIRED: () => t('Enter the Nextcloud group ID when using group mode.'),
		GROUP_NOT_FOUND: () => t('No Nextcloud group exists with that ID. Check spelling and capitalisation.'),
		ASSIGNMENT_ALREADY_CLOSED: () => t('This allocation period was already closed.'),
		VALID_UNTIL_BEFORE_ASSIGNMENT_START: () => t('The end date cannot be earlier than the start date of this allocation.'),
		VEHICLE_ASSIGNMENT_NOT_FOUND: () => t('That vehicle allocation no longer exists.'),
		VEHICLE_NOT_BOOKABLE_FOR_USER: () => t('You may not book this vehicle — it is limited to other drivers under the current allocation.'),
		DEDICATED_USE_BOOKING_DISABLED: () => t('Dedicated company cars are not booked like pool vehicles. Follow your fleet process for long-term assignment (daily use and, if applicable, Fahrtenbuch).'),
		NOT_DEDICATED_DRIVER: () => t('Only the assigned driver or a fleet manager may perform this action for a dedicated vehicle.'),
		VEHICLE_NOT_DEDICATED: () => t('This vehicle is not in dedicated assignment mode for this action.'),
		INSUFFICIENT_ROLE: () => t('You do not have permission to view or change this.'),
		LIST_PRICE_REQUIRED_FOR_ONE_PERCENT: () => t('A monthly gross list price is required for 1 %-rule assignments.'),
		DEDICATED_USER_REQUIRED: () => t('A dedicated driver is required for this assignment.'),
		DEDICATED_USER_NOT_DRIVER: () => t('The selected user must have the driver role in MobilityCheck before they can receive a dedicated company car allocation.'),
		EXPORT_FILTER_REQUIRED: () => t('Please choose a vehicle and a date range before requesting an export.'),
		EXPORT_TOKEN_INVALID: () => t('This download link is invalid.'),
		EXPORT_TOKEN_EXPIRED: () => t('This download link has expired. Please request a new export.'),
		TOKEN_REQUIRED: () => t('Download token is missing.'),
		VEHICLE_ID_REQUIRED: () => t('Please choose a vehicle.'),
		YEAR_MONTH_INVALID: () => t('Please choose an accounting month.'),
		RATE_LIMITED: (ctx) => {
			const secs = (ctx && ctx.retryAfter) ? Number(ctx.retryAfter) : 0;
			if (secs > 0) {
				return t('Too many requests. Please wait {seconds} seconds and try again.', { seconds: String(secs) });
			}
			return t('Too many requests in a short time. Please wait a moment and try again.');
		},
	};

	function resolveError(error) {
		if (!error) return t('Something went wrong.');
		const code = error.code || (error.message || '').trim() || 'REQUEST_FAILED';
		const resolver = errorMap[code];
		if (resolver) return resolver(error.context || {});
		return t('Operation failed: {code}', { code });
	}

	function reportError(error) {
		const message = resolveError(error);
		alertMsg(message);
		return message;
	}

	window.MobilityCheckMessaging = {
		t,
		toast,
		polite,
		alert: alertMsg,
		setPageError,
		resolveError,
		reportError,
	};
})();
