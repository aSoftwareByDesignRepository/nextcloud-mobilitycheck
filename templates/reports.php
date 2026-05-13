<?php
/**
 * Read-only reports — auditor/manager/admin.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
?>
<section class="mc-card mc-section" aria-labelledby="mc-rep-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-rep-h"><?php p($l->t('Reports')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Read-only reports for management, audits and tax purposes. Filter by date range and vehicle.')); ?></p>
		</div>
	</header>
	<form class="mc-toolbar" id="mc-rep-filters" role="search" aria-label="<?php p($l->t('Report filters')); ?>">
		<div class="mc-form-row">
			<label for="mc-rep-from"><?php p($l->t('From')); ?></label>
			<input id="mc-rep-from" name="from" type="date">
		</div>
		<div class="mc-form-row">
			<label for="mc-rep-to"><?php p($l->t('To')); ?></label>
			<input id="mc-rep-to" name="to" type="date">
		</div>
		<div class="mc-form-row">
			<label for="mc-rep-veh"><?php p($l->t('Vehicle')); ?></label>
			<select id="mc-rep-veh" name="vehicleId">
				<option value=""><?php p($l->t('All vehicles')); ?></option>
			</select>
		</div>
		<div class="mc-form-row">
			<label for="mc-rep-tab"><?php p($l->t('Report')); ?></label>
			<select id="mc-rep-tab" name="tab">
				<option value="costs"><?php p($l->t('Costs')); ?></option>
				<option value="utilisation"><?php p($l->t('Vehicle utilisation')); ?></option>
				<option value="bookings"><?php p($l->t('Bookings')); ?></option>
				<option value="damage"><?php p($l->t('Damage')); ?></option>
				<option value="compliance"><?php p($l->t('Driver compliance')); ?></option>
				<option value="notifications"><?php p($l->t('Notifications log')); ?></option>
			</select>
		</div>
		<div class="mc-form-row mc-toolbar__actions">
			<span id="mc-rep-pdf-hint" class="mc-sr-only"><?php p($l->t('Download the current report as a PDF using the selected filters.')); ?></span>
			<button type="button" id="mc-rep-pdf" class="button" aria-describedby="mc-rep-pdf-hint"><?php p($l->t('Download PDF')); ?></button>
		</div>
	</form>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-rep-out-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-rep-out-h" data-mc-rep-title><?php p($l->t('Cost report')); ?></h2>
			<p class="mc-section__sub" data-mc-rep-sub></p>
		</div>
	</header>
	<div id="mc-rep-content"></div>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>
