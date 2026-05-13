<?php
/**
 * Damage list — accessible to any role with the app.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
?>
<section class="mc-card mc-section" aria-labelledby="mc-dmg-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-dmg-h"><?php p($l->t('Damage reports')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Most recent first. Reports cannot be deleted — only amended.')); ?></p>
		</div>
		<div class="mc-section__controls">
			<button type="button" class="button button-primary" id="mc-dmg-new"><?php p($l->t('Report damage')); ?></button>
		</div>
	</header>
	<form class="mc-toolbar" id="mc-dmg-filters" role="search" aria-label="<?php p($l->t('Damage filters')); ?>">
		<div class="mc-form-row">
			<label for="mc-dmg-status"><?php p($l->t('Status')); ?></label>
			<select id="mc-dmg-status" name="status">
				<option value=""><?php p($l->t('All')); ?></option>
				<option value="reported"><?php p($l->t('Reported')); ?></option>
				<option value="under_assessment"><?php p($l->t('Under assessment')); ?></option>
				<option value="repair_scheduled"><?php p($l->t('Repair scheduled')); ?></option>
				<option value="in_repair"><?php p($l->t('In repair')); ?></option>
				<option value="repaired"><?php p($l->t('Repaired')); ?></option>
				<option value="closed_no_action"><?php p($l->t('Closed (no action)')); ?></option>
			</select>
		</div>
		<div class="mc-form-row">
			<label for="mc-dmg-vehicle"><?php p($l->t('Vehicle')); ?></label>
			<select id="mc-dmg-vehicle" name="vehicleId">
				<option value=""><?php p($l->t('All vehicles')); ?></option>
			</select>
		</div>
	</form>
	<div id="mc-dmg-list" aria-live="polite"></div>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>
