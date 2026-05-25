<?php

declare(strict_types=1);

/**
 * MobilityCheck routes — core (§7) + Appendix A JSON/pages.
 */
return [
	'routes' => [
		// ── Page routes (§7.1) ───────────────────────────────────────────────
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#dashboard', 'url' => '/dashboard', 'verb' => 'GET'],
		['name' => 'page#vehicles', 'url' => '/vehicles', 'verb' => 'GET'],
		['name' => 'page#vehicleDetail', 'url' => '/vehicles/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'page#drivers', 'url' => '/drivers', 'verb' => 'GET'],
		['name' => 'page#driverDetail', 'url' => '/drivers/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'page#bookings', 'url' => '/bookings', 'verb' => 'GET'],
		['name' => 'page#reassignmentSuggestions', 'url' => '/reassignment-suggestions', 'verb' => 'GET'],
		['name' => 'page#bookingNew', 'url' => '/bookings/new', 'verb' => 'GET'],
		['name' => 'page#bookingDetail', 'url' => '/bookings/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'page#damage', 'url' => '/damage', 'verb' => 'GET'],
		['name' => 'page#damageDetail', 'url' => '/damage/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'page#costs', 'url' => '/costs', 'verb' => 'GET'],
		['name' => 'page#maintenance', 'url' => '/maintenance', 'verb' => 'GET'],
		['name' => 'page#compliance', 'url' => '/compliance', 'verb' => 'GET'],
		['name' => 'page#reports', 'url' => '/reports', 'verb' => 'GET'],
		['name' => 'page#settings', 'url' => '/settings', 'verb' => 'GET'],
		['name' => 'page#logbook', 'url' => '/logbook', 'verb' => 'GET'],
		['name' => 'page#logbookNew', 'url' => '/logbook/new', 'verb' => 'GET'],
		['name' => 'page#logbookDetail', 'url' => '/logbook/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'page#expenses', 'url' => '/expenses', 'verb' => 'GET'],
		['name' => 'page#expensesNew', 'url' => '/expenses/new', 'verb' => 'GET'],
		['name' => 'page#exports', 'url' => '/exports', 'verb' => 'GET'],
		['name' => 'page#taxBenefit', 'url' => '/tax-benefit', 'verb' => 'GET'],

		// ── Stations (§4.2a) ───────────────────────────────────────────────
		['name' => 'station#list', 'url' => '/api/stations', 'verb' => 'GET'],
		['name' => 'station#create', 'url' => '/api/stations', 'verb' => 'POST'],
		['name' => 'station#update', 'url' => '/api/stations/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'station#deactivate', 'url' => '/api/stations/{id}/deactivate', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// ── Relocation tasks (§4.2a.4) ─────────────────────────────────────
		['name' => 'relocation#list', 'url' => '/api/relocations', 'verb' => 'GET'],
		['name' => 'relocation#complete', 'url' => '/api/relocations/{id}/complete', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		['name' => 'reassignment#listOpen', 'url' => '/api/reassignment-suggestions', 'verb' => 'GET'],
		['name' => 'reassignment#accept', 'url' => '/api/reassignment-suggestions/{id}/accept', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'reassignment#dismiss', 'url' => '/api/reassignment-suggestions/{id}/dismiss', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// ── QR check-in (§4.6.9) ────────────────────────────────────────────
		['name' => 'qrCheckin#scan', 'url' => '/qr/{vehicleId}', 'verb' => 'GET', 'requirements' => ['vehicleId' => '\d+']],
		['name' => 'qrCheckin#rotate', 'url' => '/api/vehicles/{vehicleId}/qr/rotate', 'verb' => 'POST', 'requirements' => ['vehicleId' => '\d+']],

		// ── Chargebacks (§4.6.8 + §4.7.5) ──────────────────────────────────
		['name' => 'chargeback#list', 'url' => '/api/chargebacks', 'verb' => 'GET'],
		['name' => 'chargeback#acknowledge', 'url' => '/api/chargebacks/{id}/acknowledge', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'chargeback#dispute', 'url' => '/api/chargebacks/{id}/dispute', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'chargeback#resolve', 'url' => '/api/chargebacks/{id}/resolve', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'chargeback#createDamageChargeback', 'url' => '/api/damage-reports/{id}/chargeback', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// ── GDPR / retention (§8.10 / §8.11) ───────────────────────────────
		['name' => 'admin#eraseUser', 'url' => '/api/admin/gdpr/erase', 'verb' => 'POST'],
		['name' => 'admin#retentionPurge', 'url' => '/api/admin/retention/purge', 'verb' => 'POST'],

		// ── Handover photo evidence (§4.6.4) ───────────────────────────────
		['name' => 'damage#uploadHandoverPhoto', 'url' => '/api/bookings/{id}/handover-photos', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'damage#handoverPhotos', 'url' => '/api/bookings/{id}/handover-photos', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],

		// ── Catalog (timezone / currency pickers) ─────────────────────────
		['name' => 'catalogApi#timezones', 'url' => '/api/catalog/timezones', 'verb' => 'GET'],
		['name' => 'catalogApi#currencies', 'url' => '/api/catalog/currencies', 'verb' => 'GET'],

		// ── Bootstrap + dashboard (current user, urls, role) ────────────────
		['name' => 'api#bootstrap', 'url' => '/api/bootstrap', 'verb' => 'GET'],
		['name' => 'api#dashboard', 'url' => '/api/dashboard', 'verb' => 'GET'],

		// ── Vehicles (§7.2) ─────────────────────────────────────────────────
		['name' => 'vehicle#list', 'url' => '/api/vehicles', 'verb' => 'GET'],
		['name' => 'vehicle#create', 'url' => '/api/vehicles', 'verb' => 'POST'],
		['name' => 'vehicle#show', 'url' => '/api/vehicles/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'vehicle#update', 'url' => '/api/vehicles/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'vehicle#decommission', 'url' => '/api/vehicles/{id}/decommission', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'vehicle#availability', 'url' => '/api/vehicles/{id}/availability', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'vehicle#lastReturnInfo', 'url' => '/api/vehicles/{id}/last-return-info', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],

		// ── Drivers (§7.2) ──────────────────────────────────────────────────
		['name' => 'driver#list', 'url' => '/api/drivers', 'verb' => 'GET'],
		['name' => 'driver#create', 'url' => '/api/drivers', 'verb' => 'POST'],
		['name' => 'driver#show', 'url' => '/api/drivers/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'driver#update', 'url' => '/api/drivers/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'driver#verifyLicence', 'url' => '/api/drivers/{id}/verify-licence', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'driver#rejectLicence', 'url' => '/api/drivers/{id}/reject-licence', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'driver#uploadLicence', 'url' => '/api/drivers/{id}/upload-licence', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'driver#compliance', 'url' => '/api/drivers/{id}/compliance', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],

		// ── Bookings (§7.2) ─────────────────────────────────────────────────
		['name' => 'booking#list', 'url' => '/api/bookings', 'verb' => 'GET'],
		['name' => 'booking#create', 'url' => '/api/bookings', 'verb' => 'POST'],
		['name' => 'booking#show', 'url' => '/api/bookings/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'booking#update', 'url' => '/api/bookings/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'booking#approve', 'url' => '/api/bookings/{id}/approve', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'booking#reject', 'url' => '/api/bookings/{id}/reject', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'booking#cancel', 'url' => '/api/bookings/{id}/cancel', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'booking#checkout', 'url' => '/api/bookings/{id}/checkout', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'booking#checkin', 'url' => '/api/bookings/{id}/checkin', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'booking#rescheduleOptions', 'url' => '/api/bookings/{id}/reschedule-options', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'booking#extend', 'url' => '/api/bookings/{id}/extend', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'booking#overrideLineManager', 'url' => '/api/bookings/{id}/override-line-manager', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'booking#reassignLineManager', 'url' => '/api/bookings/{id}/reassign-line-manager', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'booking#approvals', 'url' => '/api/bookings/{id}/approvals', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'booking#myApprovals', 'url' => '/api/my-approvals', 'verb' => 'GET'],

		// ── Damage (§7.2) ───────────────────────────────────────────────────
		['name' => 'damage#list', 'url' => '/api/damage-reports', 'verb' => 'GET'],
		['name' => 'damage#create', 'url' => '/api/damage-reports', 'verb' => 'POST'],
		['name' => 'damage#show', 'url' => '/api/damage-reports/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'damage#uploadPhoto', 'url' => '/api/damage-reports/{id}/upload-photo', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'damage#updateStatus', 'url' => '/api/damage-reports/{id}/status', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'damage#amend', 'url' => '/api/damage-reports/{id}/amend', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// ── Repairs (§7.2) ──────────────────────────────────────────────────
		['name' => 'repair#list', 'url' => '/api/repair-jobs', 'verb' => 'GET'],
		['name' => 'repair#create', 'url' => '/api/repair-jobs', 'verb' => 'POST'],
		['name' => 'repair#show', 'url' => '/api/repair-jobs/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'repair#update', 'url' => '/api/repair-jobs/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'repair#uploadInvoice', 'url' => '/api/repair-jobs/{id}/upload-invoice', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// ── Costs (§7.2) ────────────────────────────────────────────────────
		['name' => 'cost#list', 'url' => '/api/cost-entries', 'verb' => 'GET'],
		['name' => 'cost#create', 'url' => '/api/cost-entries', 'verb' => 'POST'],
		['name' => 'cost#update', 'url' => '/api/cost-entries/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'cost#delete', 'url' => '/api/cost-entries/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'costCategory#list', 'url' => '/api/cost-categories', 'verb' => 'GET'],
		['name' => 'costCategory#create', 'url' => '/api/cost-categories', 'verb' => 'POST'],
		['name' => 'costCategory#update', 'url' => '/api/cost-categories/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],

		// ── Maintenance (§7.2) ──────────────────────────────────────────────
		['name' => 'maintenance#list', 'url' => '/api/maintenance-schedules', 'verb' => 'GET'],
		['name' => 'maintenance#create', 'url' => '/api/maintenance-schedules', 'verb' => 'POST'],
		['name' => 'maintenance#update', 'url' => '/api/maintenance-schedules/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'maintenance#complete', 'url' => '/api/maintenance-schedules/{id}/complete', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// ── Compliance (§7.2) ───────────────────────────────────────────────
		['name' => 'compliance#instructions', 'url' => '/api/compliance/instructions', 'verb' => 'GET'],
		['name' => 'compliance#completeInstruction', 'url' => '/api/compliance/instructions/{driverProfileId}/complete', 'verb' => 'POST', 'requirements' => ['driverProfileId' => '\d+']],
		['name' => 'compliance#licences', 'url' => '/api/compliance/licences', 'verb' => 'GET'],

		// ── Reports (§7.2) ──────────────────────────────────────────────────
		['name' => 'report#driverCompliance', 'url' => '/api/reports/driver-compliance', 'verb' => 'GET'],
		['name' => 'report#vehicleUtilisation', 'url' => '/api/reports/vehicle-utilisation', 'verb' => 'GET'],
		['name' => 'report#costs', 'url' => '/api/reports/costs', 'verb' => 'GET'],
		['name' => 'report#damage', 'url' => '/api/reports/damage', 'verb' => 'GET'],
		['name' => 'report#bookings', 'url' => '/api/reports/bookings', 'verb' => 'GET'],
		['name' => 'report#notifications', 'url' => '/api/reports/notifications', 'verb' => 'GET'],
		['name' => 'report#exportPdf', 'url' => '/api/reports/export-pdf', 'verb' => 'POST'],

		// ── Admin (§2.4, §7.2) ──────────────────────────────────────────────
		['name' => 'admin#users', 'url' => '/api/admin/users', 'verb' => 'GET'],
		['name' => 'admin#groups', 'url' => '/api/admin/groups', 'verb' => 'GET'],
		['name' => 'admin#policy', 'url' => '/api/admin/policy', 'verb' => 'GET'],
		['name' => 'admin#savePolicy', 'url' => '/api/admin/policy', 'verb' => 'POST'],
		['name' => 'admin#auditLog', 'url' => '/api/admin/audit-log', 'verb' => 'GET'],
		['name' => 'admin#listRoles', 'url' => '/api/admin/roles', 'verb' => 'GET'],
		['name' => 'admin#setRoles', 'url' => '/api/admin/roles', 'verb' => 'POST'],
		['name' => 'admin#settings', 'url' => '/api/admin/settings', 'verb' => 'GET'],
		['name' => 'admin#saveSettings', 'url' => '/api/admin/settings', 'verb' => 'POST'],
		['name' => 'admin#approvalConfig', 'url' => '/api/admin/approval-config', 'verb' => 'GET'],
		['name' => 'admin#saveApprovalConfig', 'url' => '/api/admin/approval-config', 'verb' => 'POST'],
		['name' => 'admin#lineManagerAssignments', 'url' => '/api/admin/line-manager-assignments', 'verb' => 'GET'],
		['name' => 'admin#lineManagerAssignmentsCreate', 'url' => '/api/admin/line-manager-assignments', 'verb' => 'POST'],
		['name' => 'admin#lineManagerAssignmentsClose', 'url' => '/api/admin/line-manager-assignments/{id}/close', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// ── Appendix A ───────────────────────────────────────────────────────
		['name' => 'vehicleAssignment#list', 'url' => '/api/vehicle-assignments', 'verb' => 'GET'],
		['name' => 'vehicleAssignment#create', 'url' => '/api/vehicle-assignments', 'verb' => 'POST'],
		['name' => 'vehicleAssignment#close', 'url' => '/api/vehicle-assignments/{id}/close', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'appendixApi#vehicleFeaturesList', 'url' => '/api/vehicle-features', 'verb' => 'GET'],
		['name' => 'appendixApi#vehicleFeaturesCreate', 'url' => '/api/vehicle-features', 'verb' => 'POST'],
		['name' => 'appendixApi#vehicleFeaturesDelete', 'url' => '/api/vehicle-features/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'appendixApi#featureKeys', 'url' => '/api/vehicle-feature-keys', 'verb' => 'GET'],
		['name' => 'appendixApi#vehicleSearch', 'url' => '/api/vehicles/search', 'verb' => 'POST'],
		['name' => 'appendixApi#searchProfilesList', 'url' => '/api/search-profiles', 'verb' => 'GET'],
		['name' => 'appendixApi#searchProfilesCreate', 'url' => '/api/search-profiles', 'verb' => 'POST'],
		['name' => 'appendixApi#searchProfilesUpdate', 'url' => '/api/search-profiles/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'appendixApi#searchProfilesDelete', 'url' => '/api/search-profiles/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'logbook#list', 'url' => '/api/logbook', 'verb' => 'GET'],
		['name' => 'logbook#create', 'url' => '/api/logbook', 'verb' => 'POST'],
		['name' => 'logbook#gaps', 'url' => '/api/logbook/gaps', 'verb' => 'GET'],
		['name' => 'logbook#show', 'url' => '/api/logbook/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'logbook#update', 'url' => '/api/logbook/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'logbook#confirm', 'url' => '/api/logbook/{id}/confirm', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'logbook#amend', 'url' => '/api/logbook/{id}/amend', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'appendixApi#odometerCreate', 'url' => '/api/odometer-readings', 'verb' => 'POST'],
		['name' => 'reimbursement#rates', 'url' => '/api/reimbursement-rates', 'verb' => 'GET'],
		['name' => 'reimbursement#ratesCreate', 'url' => '/api/reimbursement-rates', 'verb' => 'POST'],
		['name' => 'reimbursement#privateList', 'url' => '/api/private-vehicles', 'verb' => 'GET'],
		['name' => 'reimbursement#privateCreate', 'url' => '/api/private-vehicles', 'verb' => 'POST'],
		['name' => 'reimbursement#privateUpdate', 'url' => '/api/private-vehicles/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'reimbursement#privateDeactivate', 'url' => '/api/private-vehicles/{id}/deactivate', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'reimbursement#claimsList', 'url' => '/api/reimbursement-claims', 'verb' => 'GET'],
		['name' => 'reimbursement#claimsCreate', 'url' => '/api/reimbursement-claims', 'verb' => 'POST'],
		['name' => 'reimbursement#claimsShow', 'url' => '/api/reimbursement-claims/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'reimbursement#claimsUpdate', 'url' => '/api/reimbursement-claims/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'reimbursement#claimsSubmit', 'url' => '/api/reimbursement-claims/{id}/submit', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'reimbursement#claimsApprove', 'url' => '/api/reimbursement-claims/{id}/approve', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'reimbursement#claimsReject', 'url' => '/api/reimbursement-claims/{id}/reject', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'reimbursement#claimsMarkPaid', 'url' => '/api/reimbursement-claims/{id}/mark-paid', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'appendixApi#exportRequest', 'url' => '/api/exports/request', 'verb' => 'POST'],
		['name' => 'appendixApi#exportHistory', 'url' => '/api/exports/history', 'verb' => 'GET'],
		['name' => 'appendixApi#exportDownload', 'url' => '/api/exports/download/{token}', 'verb' => 'GET'],

		['name' => 'taxBenefit#monthly', 'url' => '/api/tax-benefit/monthly', 'verb' => 'GET'],
		['name' => 'taxBenefit#payrollExport', 'url' => '/api/tax-benefit/payroll-export', 'verb' => 'GET'],

		// ── User preferences (onboarding dismiss, notification prefs) ───────
		['name' => 'preference#get', 'url' => '/api/me/preferences', 'verb' => 'GET'],
		['name' => 'preference#set', 'url' => '/api/me/preferences', 'verb' => 'POST'],
	],
];
