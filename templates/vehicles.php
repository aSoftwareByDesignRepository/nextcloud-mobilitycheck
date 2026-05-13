<?php
/**
 * Vehicles list with filters and quick create modal.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$isManager = !empty($_['isManager']);
$lineManagerScopedReader = !empty($_['lineManagerScopedReader']);
?>
<section class="mc-card mc-section" aria-labelledby="mc-veh-list-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-veh-list-h"><?php p($l->t('Fleet inventory')); ?></h2>
			<p class="mc-section__sub"><?php p($lineManagerScopedReader
				? $l->t('Fleet inventory (read-only). You cannot add vehicles.')
				: $l->t('All vehicles currently on the books. Decommissioned vehicles are hidden by default.')); ?></p>
		</div>
		<?php if ($isManager): ?>
		<div class="mc-section__controls">
			<button type="button" class="button button-primary" id="mc-veh-new"><?php p($l->t('Add vehicle')); ?></button>
		</div>
		<?php endif; ?>
	</header>

	<form class="mc-toolbar" id="mc-veh-filters" role="search" aria-label="<?php p($l->t('Vehicle filters')); ?>">
		<div class="mc-form-row">
			<label for="mc-veh-status"><?php p($l->t('Status')); ?></label>
			<select id="mc-veh-status" name="status">
				<option value=""><?php p($l->t('All active')); ?></option>
				<option value="available"><?php p($l->t('Available')); ?></option>
				<option value="booked"><?php p($l->t('Booked')); ?></option>
				<option value="in_use"><?php p($l->t('In use')); ?></option>
				<option value="in_maintenance"><?php p($l->t('In maintenance')); ?></option>
			</select>
		</div>
		<div class="mc-form-row">
			<label for="mc-veh-active"><?php p($l->t('Include decommissioned')); ?></label>
			<select id="mc-veh-active" name="activeOnly">
				<option value="1"><?php p($l->t('Hide')); ?></option>
				<option value="0"><?php p($l->t('Show all')); ?></option>
			</select>
		</div>
	</form>

	<div id="mc-veh-list" aria-live="polite"></div>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>
