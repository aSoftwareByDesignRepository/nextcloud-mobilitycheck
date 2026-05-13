<?php
/**
 * Dashboard. Role-scoped KPI summary + quick actions.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$isDriver = !empty($_['isDriver']);
$isManager = !empty($_['isManager']);
$isLineManager = !empty($_['isLineManager']);
$isFleetAdmin = !empty($_['isFleetAdmin']);
$isAuditor = !empty($_['isAuditor']);
$isWorkshop = !empty($_['isWorkshop']);
$isWorkshopOnly = !empty($_['isWorkshopOnly']);
?>
<section class="mc-card mc-section" aria-labelledby="mc-dash-overview-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-dash-overview-h"><?php p($l->t('Today’s overview')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('A live, role-aware snapshot. Numbers update when you reload this page.')); ?></p>
		</div>
		<div class="mc-section__controls">
			<button type="button" class="button" id="mc-dash-refresh" aria-label="<?php p($l->t('Refresh dashboard')); ?>">
				<?php p($l->t('Refresh')); ?>
			</button>
		</div>
	</header>
	<div id="mc-dash-error" class="mc-page-error" role="alert" hidden></div>
	<div id="mc-dash-kpis" class="mc-kpis" aria-live="polite"></div>
</section>

<?php if ($isDriver): ?>
<section class="mc-card mc-section" aria-labelledby="mc-dash-mine-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-dash-mine-h"><?php p($l->t('Your upcoming bookings')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Approved and active bookings starting today or later.')); ?></p>
		</div>
		<div class="mc-section__controls">
			<a class="button button-primary" href="<?php p((string)($_['urls']['bookingNew'] ?? '#')); ?>">
				<?php p($l->t('New booking')); ?>
			</a>
		</div>
	</header>
	<div id="mc-dash-mine-list"></div>
</section>
<?php endif; ?>

<?php if ($isLineManager && !$isManager): ?>
<section class="mc-card mc-section" aria-labelledby="mc-dash-lm-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-dash-lm-h"><?php p($l->t('Approvals for your drivers')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Bookings waiting for you as line manager.')); ?></p>
		</div>
	</header>
	<div id="mc-dash-lm-pending-list"></div>
</section>
<section class="mc-card mc-section" aria-labelledby="mc-dash-lm-damage-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-dash-lm-damage-h"><?php p($l->t('Open damage in your scope')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Incidents reported by you or your supervised drivers, or on their bookings.')); ?></p>
		</div>
	</header>
	<div id="mc-dash-lm-damage-list"></div>
</section>
<?php endif; ?>

<?php if ($isManager): ?>
<section class="mc-card mc-section" aria-labelledby="mc-dash-pending-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-dash-pending-h"><?php p($l->t('Pending approvals')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Drivers waiting for your decision before they can pick up their vehicle.')); ?></p>
		</div>
	</header>
	<div id="mc-dash-pending-list"></div>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-dash-damage-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-dash-damage-h"><?php p($l->t('Open damage and repairs')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Damage reports that still need attention, ordered by discovery date.')); ?></p>
		</div>
	</header>
	<div id="mc-dash-damage-list"></div>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-dash-reloc-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-dash-reloc-h"><?php p($l->t('Relocation queue')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Vehicles returned at a different station than pickup (one-way bookings).')); ?></p>
		</div>
	</header>
	<div id="mc-dash-relocations-list"></div>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-dash-maint-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-dash-maint-h"><?php p($l->t('Maintenance attention')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Schedules that are due or overdue across the fleet.')); ?></p>
		</div>
	</header>
	<div id="mc-dash-maint-list"></div>
</section>
<?php endif; ?>

<?php if (!$isDriver && !$isManager && !$isFleetAdmin && !$isAuditor && $isWorkshopOnly): ?>
<section class="mc-card mc-section" aria-labelledby="mc-dash-workshop-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-dash-workshop-h"><?php p($l->t('Workshop queue')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Open and in-progress repairs assigned to you.')); ?></p>
		</div>
		<div class="mc-section__controls">
			<a class="button" href="<?php p((string)($_['urls']['damage'] ?? '#')); ?>"><?php p($l->t('Open damage list')); ?></a>
		</div>
	</header>
</section>
<?php endif; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>
