<?php
/**
 * Cost entries — manager + admin only.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
?>
<section class="mc-card mc-section" aria-labelledby="mc-cost-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-cost-h"><?php p($l->t('Cost entries')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Fuel, repairs, insurance, road tax — all monetary movements per vehicle. Net and VAT are computed from the gross you enter.')); ?></p>
		</div>
		<div class="mc-section__controls">
			<button type="button" class="button button-primary" id="mc-cost-new"><?php p($l->t('Record cost')); ?></button>
		</div>
	</header>
	<form class="mc-toolbar" id="mc-cost-filters" role="search" aria-label="<?php p($l->t('Cost filters')); ?>">
		<div class="mc-form-row">
			<label for="mc-cost-veh"><?php p($l->t('Vehicle')); ?></label>
			<select id="mc-cost-veh" name="vehicleId">
				<option value=""><?php p($l->t('All vehicles')); ?></option>
			</select>
		</div>
		<div class="mc-form-row">
			<label for="mc-cost-from"><?php p($l->t('From')); ?></label>
			<input id="mc-cost-from" name="from" type="date">
		</div>
		<div class="mc-form-row">
			<label for="mc-cost-to"><?php p($l->t('To')); ?></label>
			<input id="mc-cost-to" name="to" type="date">
		</div>
	</form>
	<div id="mc-cost-list" aria-live="polite"></div>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>
