<?php
/**
 * Vehicle detail.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$vehicleId = (int)($_['vehicleId'] ?? 0);
$isManager = !empty($_['isManager']);
$isDriver = !empty($_['isDriver']);
$isAuditor = !empty($_['isAuditor']);
$isLineManager = !empty($_['isLineManager']);
$lineManagerScopedReader = !empty($_['lineManagerScopedReader']);
$urls = (array)($_['urls'] ?? []);
$fleetCosts = $isManager || $isAuditor || $isLineManager;
$reportsExports = $fleetCosts;
/** @param array<string, int|float|string|bool|null> $query */
$mcVehicleListUrl = static function (string $base, array $query): string {
	if ($base === '' || $base === '#') {
		return '#';
	}
	return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
};
$mcReportFrom = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('-90 days')->format('Y-m-d');
$mcReportTo = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d');
?>
<input type="hidden" id="mc-vehicle-id" value="<?php p($vehicleId); ?>">

<div class="mc-vehicle-detail">
	<div class="mc-vehicle-detail__main">
		<section class="mc-card mc-section mc-vehicle-detail__overview" aria-labelledby="mc-veh-detail-h">
			<header class="mc-section__header">
				<div>
					<h2 id="mc-veh-detail-h" data-mc-bind="internal_name"><?php p($l->t('Vehicle')); ?></h2>
					<p class="mc-section__sub" data-mc-bind="model"></p>
				</div>
				<div class="mc-section__controls">
					<a class="button" href="<?php p((string)($urls['vehicles'] ?? '#')); ?>"><?php p($l->t('Back to list')); ?></a>
					<?php if ($isManager): ?>
						<button type="button" class="button" id="mc-veh-edit"><?php p($l->t('Edit')); ?></button>
						<button type="button" class="button mc-danger-button" id="mc-veh-decom"><?php p($l->t('Decommission')); ?></button>
					<?php endif; ?>
				</div>
			</header>
			<dl class="mc-dl mc-vehicle-detail__facts" id="mc-veh-dl"></dl>
		</section>

		<section class="mc-card mc-section" aria-labelledby="mc-veh-assign-h">
			<header class="mc-section__header">
				<div>
					<h2 id="mc-veh-assign-h"><?php p($l->t('Who may use this vehicle (allocation)')); ?></h2>
					<p class="mc-section__sub"><?php p($l->t('Sharing mode and tax workflow — how bookings and payroll-related steps apply.')); ?></p>
				</div>
				<?php if ($isManager): ?>
					<div class="mc-section__controls">
						<button type="button" class="button button-primary" id="mc-veh-assign-new"><?php p($l->t('New allocation period')); ?></button>
					</div>
				<?php endif; ?>
			</header>
			<div class="mc-callout mc-callout--neutral mc-vehicle-detail__alloc-help" role="note">
				<p><?php p($l->t('Pool and group vehicles stay in the shared booking pool. A dedicated allocation is a long-term company car (Dienstwagen) for one driver — others cannot book it. Tax treatment records how private use is handled in payroll: business-only pool trips, 1%% rule with list price, or Fahrtenbuch (logbook) where the driver enters each trip. Payroll remains authoritative; MobilityCheck enforces the workflow your fleet chooses.')); ?></p>
			</div>
			<div id="mc-veh-assignments" class="mc-assignment-host"></div>
		</section>

		<div class="mc-vehicle-detail__activity" role="presentation">
			<section class="mc-card mc-section" aria-labelledby="mc-veh-bookings-h">
				<header class="mc-section__header">
					<div>
						<h2 id="mc-veh-bookings-h"><?php p($l->t('Upcoming reserved windows')); ?></h2>
						<p class="mc-section__sub"><?php p($lineManagerScopedReader
							? ($isDriver
								? $l->t('You also drive: every reservation on this vehicle is shown. Same calendar as other drivers who book it.')
								: $l->t('Only reservations by drivers you supervise (and yourself, if you book) are shown — line-manager privacy view.'))
							: $l->t('Pending, approved and active bookings within the next 90 days.')); ?></p>
					</div>
				</header>
				<div id="mc-veh-bookings"></div>
			</section>

			<section class="mc-card mc-section" aria-labelledby="mc-veh-damage-h">
				<header class="mc-section__header">
					<div>
						<h2 id="mc-veh-damage-h"><?php p($l->t('Damage history')); ?></h2>
						<p class="mc-section__sub"><?php p($lineManagerScopedReader
							? $l->t('Damage reports you are allowed to see for this vehicle (your scope).')
							: $l->t('All open and closed damage reports for this vehicle.')); ?></p>
					</div>
				</header>
				<div id="mc-veh-damage"></div>
			</section>
		</div>
	</div>

	<aside class="mc-vehicle-detail__aside" aria-labelledby="mc-veh-related-h">
		<section class="mc-card mc-section mc-vehicle-detail__related-card">
			<header class="mc-section__header">
				<div>
					<h2 id="mc-veh-related-h"><?php p($l->t('More for this vehicle')); ?></h2>
					<p class="mc-section__sub"><?php p($l->t('Jump to list pages with this vehicle already selected in the filter.')); ?></p>
				</div>
			</header>
			<nav class="mc-vehicle-detail__related-nav" aria-labelledby="mc-veh-related-h">
				<ul class="mc-related-list mc-related-list--stack" id="mc-veh-related">
					<?php if ($vehicleId > 0): ?>
						<li><a class="mc-button-link" href="<?php p($mcVehicleListUrl((string)($urls['bookings'] ?? '#'), ['vehicleId' => $vehicleId])); ?>" aria-label="<?php p($l->t('Bookings filtered to this vehicle')); ?>"><?php p($l->t('Bookings')); ?></a></li>
						<li><a class="mc-button-link" href="<?php p($mcVehicleListUrl((string)($urls['damage'] ?? '#'), ['vehicleId' => $vehicleId])); ?>" aria-label="<?php p($l->t('Damage filtered to this vehicle')); ?>"><?php p($l->t('Damage')); ?></a></li>
						<li><a class="mc-button-link" href="<?php p($mcVehicleListUrl((string)($urls['logbook'] ?? '#'), ['vehicleId' => $vehicleId])); ?>" aria-label="<?php p($l->t('Logbook filtered to this vehicle')); ?>"><?php p($l->t('Logbook (Fahrtenbuch)')); ?></a></li>
						<?php if ($fleetCosts): ?>
							<li><a class="mc-button-link" href="<?php p($mcVehicleListUrl((string)($urls['costs'] ?? '#'), ['vehicleId' => $vehicleId])); ?>" aria-label="<?php p($l->t('Costs filtered to this vehicle')); ?>"><?php p($l->t('Costs')); ?></a></li>
						<?php endif; ?>
						<?php if ($isManager): ?>
							<li><a class="mc-button-link" href="<?php p($mcVehicleListUrl((string)($urls['maintenance'] ?? '#'), ['vehicleId' => $vehicleId])); ?>" aria-label="<?php p($l->t('Maintenance filtered to this vehicle')); ?>"><?php p($l->t('Maintenance')); ?></a></li>
						<?php endif; ?>
						<?php if ($reportsExports): ?>
							<li><a class="mc-button-link" href="<?php p($mcVehicleListUrl((string)($urls['reports'] ?? '#'), ['tab' => 'costs', 'vehicleId' => $vehicleId, 'from' => $mcReportFrom, 'to' => $mcReportTo])); ?>" aria-label="<?php p($l->t('Reports filtered to this vehicle (last 90 days)')); ?>"><?php p($l->t('Reports')); ?></a></li>
							<li><a class="mc-button-link" href="<?php p($mcVehicleListUrl((string)($urls['exports'] ?? '#'), ['vehicleId' => $vehicleId, 'from' => $mcReportFrom, 'to' => $mcReportTo])); ?>" aria-label="<?php p($l->t('Exports filtered to this vehicle (last 90 days)')); ?>"><?php p($l->t('Exports')); ?></a></li>
						<?php endif; ?>
						<?php if ($isDriver): ?>
							<li><a class="mc-button-link" href="<?php p($mcVehicleListUrl((string)($urls['bookingNew'] ?? '#'), ['vehicleId' => $vehicleId])); ?>" aria-label="<?php p($l->t('New booking for this vehicle')); ?>"><?php p($l->t('New booking')); ?></a></li>
							<li><a class="mc-button-link" href="<?php p($mcVehicleListUrl((string)($urls['logbookNew'] ?? '#'), ['vehicleId' => $vehicleId])); ?>" aria-label="<?php p($l->t('New logbook entry for this vehicle')); ?>"><?php p($l->t('New logbook entry')); ?></a></li>
						<?php endif; ?>
					<?php endif; ?>
				</ul>
			</nav>
		</section>
	</aside>
</div>

<?php include __DIR__ . '/common/page-end.php'; ?>
