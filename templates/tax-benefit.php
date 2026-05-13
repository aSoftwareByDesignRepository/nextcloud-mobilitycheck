<?php
/**
 * §A10 — Tax benefit (1 %-Regelung) preview for payroll plausibility checks.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
?>
<section class="mc-card mc-section" aria-labelledby="mc-tax-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-tax-h"><?php p($l->t('Tax benefit')); ?></h2>
			<p id="mc-tax-intro" class="mc-section__sub"><?php p($l->t('Calculates the taxable benefit for company cars taxed under the 1 %% rule (§ 8 Abs. 2 EStG): 1 %% of the gross list price plus 0.03 %% per kilometre of one-way commute. The figure is informational — payroll receives the export from the Exports page.')); ?></p>
		</div>
	</header>
	<form id="mc-tax-form" class="mc-form" novalidate aria-describedby="mc-tax-intro">
		<div class="mc-grid-2">
			<div class="mc-form-row">
				<label for="mc-tax-vehicle"><?php p($l->t('Vehicle')); ?> <span class="mc-required" aria-label="<?php p($l->t('required')); ?>">*</span></label>
				<select id="mc-tax-vehicle" name="vehicleId" required aria-describedby="mc-tax-vehicle-hint">
					<option value=""><?php p($l->t('Choose a vehicle…')); ?></option>
				</select>
				<p id="mc-tax-vehicle-hint" class="mc-form-row__hint"><?php p($l->t('Use this view to sanity-check a single assignment. Payroll and the tax adviser should always rely on the dedicated export and the underlying contract data, not on this screen alone.')); ?></p>
				<p class="mc-form-row__error" role="alert" id="mc-tax-vehicle-err"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-tax-month"><?php p($l->t('Accounting month (YYYY-MM)')); ?> <span class="mc-required" aria-label="<?php p($l->t('required')); ?>">*</span></label>
				<input id="mc-tax-month" name="yearMonth" type="month" required aria-describedby="mc-tax-month-err">
				<p class="mc-form-row__error" role="alert" id="mc-tax-month-err"></p>
			</div>
		</div>
		<div class="mc-form-actions">
			<button type="submit" class="button button-primary"><?php p($l->t('Calculate preview')); ?></button>
			<a id="mc-tax-csv" class="button" href="#" hidden><?php p($l->t('Download payroll row (CSV)')); ?></a>
		</div>
	</form>
	<div id="mc-tax-result" class="mc-callout" role="region" aria-live="polite" hidden></div>
</section>

<?php include __DIR__ . '/common/page-end.php'; ?>
