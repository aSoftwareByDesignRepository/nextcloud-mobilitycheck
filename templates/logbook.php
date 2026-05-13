<?php
/**
 * Fahrtenbuch list (Appendix A3).
 *
 * Drivers see their own entries; managers, fleet admins and auditors see
 * the fleet-wide log. The server (LogbookService::list) enforces the
 * filter — the template never trusts client-side scope.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$isManager = !empty($_['isManager']);
$isFleetAdmin = !empty($_['isFleetAdmin']);
$isAuditor = !empty($_['isAuditor']);
$isDriver = !empty($_['isDriver']);
$canSeeAll = $isManager || $isFleetAdmin || $isAuditor;
?>
<section class="mc-card mc-section" aria-labelledby="mc-lb-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-lb-h"><?php p($l->t('Logbook (Fahrtenbuch)')); ?></h2>
			<p class="mc-section__sub">
				<?php if ($canSeeAll): ?>
					<?php p($l->t('All confirmed and draft trips across the fleet. Confirmed entries are immutable; corrections require an amendment with a documented reason (§ 6 EStG, GoBD).')); ?>
				<?php else: ?>
					<?php p($l->t('Your business, commute and private trips. Confirm an entry once it is complete — confirmed entries are legally binding and can only be corrected by amendment.')); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php if ($isDriver): ?>
		<div class="mc-section__controls">
			<a class="button button-primary" href="<?php p((string)($_['urls']['logbookNew'] ?? '#')); ?>"><?php p($l->t('New entry')); ?></a>
		</div>
		<?php endif; ?>
	</header>

	<aside class="mc-callout mc-callout--neutral" role="note" aria-labelledby="mc-lb-howto-h">
		<h3 id="mc-lb-howto-h" class="mc-callout__title"><?php p($l->t('How the Fahrtenbuch works')); ?></h3>
		<p>
			<?php p($l->t('Trip type — Business: customer visit, errand. Commute: home ↔ office. Private: any other personal use.')); ?>
		</p>
		<p>
			<?php p($l->t('Draft → Confirm: once you tick “entry is correct and complete”, the trip is locked. A wrong entry stays in the record; only an Amendment with a reason replaces it.')); ?>
		</p>
	</aside>

	<form class="mc-toolbar" id="mc-lb-filters" role="search" aria-label="<?php p($l->t('Logbook filters')); ?>">
		<div class="mc-form-row">
			<label for="mc-lb-vehicle"><?php p($l->t('Vehicle')); ?></label>
			<select id="mc-lb-vehicle" name="vehicleId">
				<option value=""><?php p($l->t('All vehicles')); ?></option>
			</select>
		</div>
		<div class="mc-form-row">
			<label for="mc-lb-from"><?php p($l->t('From')); ?></label>
			<input id="mc-lb-from" name="from" type="date">
		</div>
		<div class="mc-form-row">
			<label for="mc-lb-to"><?php p($l->t('To')); ?></label>
			<input id="mc-lb-to" name="to" type="date">
		</div>
		<?php if ($canSeeAll): ?>
		<div class="mc-form-row">
			<label for="mc-lb-driver"><?php p($l->t('Driver')); ?></label>
			<select id="mc-lb-driver" name="driverUserId">
				<option value=""><?php p($l->t('All drivers')); ?></option>
			</select>
		</div>
		<?php endif; ?>
		<div class="mc-form-row mc-form-row--checkbox">
			<label class="mc-checkbox-row" for="mc-lb-conf">
				<input id="mc-lb-conf" name="confirmedOnly" type="checkbox" value="1">
				<span><?php p($l->t('Show only confirmed entries')); ?></span>
			</label>
		</div>
	</form>
	<div id="mc-lb-list" aria-live="polite"></div>
</section>

<?php if ($canSeeAll): ?>
<section class="mc-card mc-section" aria-labelledby="mc-lb-gap-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-lb-gap-h"><?php p($l->t('Gap check')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Compare confirmed kilometres against odometer readings. A non-zero gap means trips are missing from the Fahrtenbuch — a finding any tax audit will flag.')); ?></p>
		</div>
	</header>
	<form class="mc-toolbar" id="mc-lb-gap-form" role="search" aria-label="<?php p($l->t('Gap check filters')); ?>">
		<div class="mc-form-row">
			<label for="mc-lb-gap-vehicle"><?php p($l->t('Vehicle')); ?><span class="mc-required" aria-label="<?php p($l->t('required')); ?>">*</span></label>
			<select id="mc-lb-gap-vehicle" name="vehicleId" required>
				<option value=""><?php p($l->t('Choose a vehicle…')); ?></option>
			</select>
		</div>
		<div class="mc-form-row">
			<label for="mc-lb-gap-from"><?php p($l->t('From')); ?><span class="mc-required" aria-label="<?php p($l->t('required')); ?>">*</span></label>
			<input id="mc-lb-gap-from" name="from" type="date" required>
		</div>
		<div class="mc-form-row">
			<label for="mc-lb-gap-to"><?php p($l->t('To')); ?><span class="mc-required" aria-label="<?php p($l->t('required')); ?>">*</span></label>
			<input id="mc-lb-gap-to" name="to" type="date" required>
		</div>
		<div class="mc-form-row">
			<button type="submit" class="button"><?php p($l->t('Check gap')); ?></button>
		</div>
	</form>
	<div id="mc-lb-gap-result" aria-live="polite"></div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>
