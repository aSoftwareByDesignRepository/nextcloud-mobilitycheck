<?php
/**
 * Exports (Appendix A7). Authorised users can generate a Fahrtenbuch CSV
 * export per vehicle + date range. Tokens expire after one hour.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
?>
<section class="mc-card mc-section" aria-labelledby="mc-xp-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-xp-h"><?php p($l->t('Generate an export')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Pick a vehicle and date range. The server builds a UTF-8 CSV with all confirmed Fahrtenbuch entries; you receive a one-hour download link.')); ?></p>
		</div>
	</header>
	<form id="mc-xp-form" class="mc-form" novalidate>
		<div class="mc-grid-2">
			<div class="mc-form-row">
				<label for="mc-xp-vehicle"><?php p($l->t('Vehicle')); ?> <span class="mc-required" aria-label="<?php p($l->t('required')); ?>">*</span></label>
				<select id="mc-xp-vehicle" name="vehicleId" required>
					<option value=""><?php p($l->t('Choose a vehicle…')); ?></option>
				</select>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-xp-from"><?php p($l->t('From')); ?> <span class="mc-required" aria-label="<?php p($l->t('required')); ?>">*</span></label>
				<input id="mc-xp-from" name="from" type="date" required>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-xp-to"><?php p($l->t('To')); ?> <span class="mc-required" aria-label="<?php p($l->t('required')); ?>">*</span></label>
				<input id="mc-xp-to" name="to" type="date" required>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
		</div>
		<div class="mc-form-actions">
			<button type="submit" class="button button-primary"><?php p($l->t('Generate CSV')); ?></button>
		</div>
		<p class="mc-form-row__hint"><?php p($l->t('Only confirmed entries are exported. Drafts and superseded entries are excluded so payroll never sees draft data.')); ?></p>
	</form>
	<div id="mc-xp-current" aria-live="polite"></div>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-xp-hist-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-xp-hist-h"><?php p($l->t('Recent exports')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Your last 50 export requests. Download links expire after one hour for security.')); ?></p>
		</div>
	</header>
	<div id="mc-xp-hist" aria-live="polite"></div>
</section>

<?php include __DIR__ . '/common/page-end.php'; ?>
