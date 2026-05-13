<?php
/**
 * Maintenance schedules.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$isManager = !empty($_['isManager']);
?>
<section class="mc-card mc-section" aria-labelledby="mc-mnt-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-mnt-h"><?php p($l->t('Maintenance schedules')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Recurring inspections and services. Mark a schedule as “blocking” to prevent new bookings until it is completed.')); ?></p>
		</div>
		<?php if ($isManager): ?>
		<div class="mc-section__controls">
			<button type="button" class="button button-primary" id="mc-mnt-new"><?php p($l->t('Add schedule')); ?></button>
		</div>
		<?php endif; ?>
	</header>
	<form class="mc-toolbar" id="mc-mnt-filters" role="search" aria-label="<?php p($l->t('Maintenance filters')); ?>">
		<div class="mc-form-row">
			<label for="mc-mnt-veh"><?php p($l->t('Vehicle')); ?></label>
			<select id="mc-mnt-veh" name="vehicleId">
				<option value=""><?php p($l->t('All vehicles')); ?></option>
			</select>
		</div>
		<div class="mc-form-row">
			<label for="mc-mnt-blocking"><?php p($l->t('Blocking only')); ?></label>
			<select id="mc-mnt-blocking" name="blockingOnly">
				<option value="">—</option>
				<option value="1"><?php p($l->t('Yes')); ?></option>
			</select>
		</div>
	</form>
	<div id="mc-mnt-list" aria-live="polite"></div>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>
