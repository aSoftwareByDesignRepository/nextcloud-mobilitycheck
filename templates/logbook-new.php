<?php
/**
 * New Fahrtenbuch entry. Manual entries are only allowed for vehicles
 * the user is dedicated-assigned to with the “Fahrtenbuch” tax method.
 * Pool / group bookings produce a draft automatically after check-in.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
?>
<section class="mc-card mc-section" aria-labelledby="mc-lbn-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-lbn-h"><?php p($l->t('Add a logbook entry')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Use this form only for trips with a vehicle that is dedicated-assigned to you and taxed under the Fahrtenbuch method. Pool and group trips become drafts automatically after check-in.')); ?></p>
		</div>
		<div class="mc-section__controls">
			<a class="button" href="<?php p((string)($_['urls']['logbook'] ?? '#')); ?>"><?php p($l->t('Back to list')); ?></a>
		</div>
	</header>

	<aside class="mc-callout mc-callout--neutral" role="note" aria-labelledby="mc-lbn-info-h">
		<h3 id="mc-lbn-info-h" class="mc-callout__title"><?php p($l->t('What a complete entry needs')); ?></h3>
		<ul class="mc-checklist">
			<li><?php p($l->t('Trip date and (for business) the departure and arrival time')); ?></li>
			<li><?php p($l->t('Start and end address — at least the city or recognisable description')); ?></li>
			<li><?php p($l->t('Start and end odometer reading in whole kilometres')); ?></li>
			<li><?php p($l->t('Purpose of the trip and, for business, the client or contact')); ?></li>
		</ul>
	</aside>

	<form id="mc-lbn-form" class="mc-form" novalidate>
		<div class="mc-grid-2">
			<div class="mc-form-row">
				<label for="mc-lbn-vehicle"><?php p($l->t('Vehicle')); ?> <span class="mc-required" aria-label="<?php p($l->t('required')); ?>">*</span></label>
				<select id="mc-lbn-vehicle" name="vehicleId" required></select>
				<p class="mc-form-row__hint"><?php p($l->t('Only vehicles you are assigned to under the Fahrtenbuch method are listed.')); ?></p>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-lbn-type"><?php p($l->t('Trip type')); ?> <span class="mc-required">*</span></label>
				<select id="mc-lbn-type" name="tripType" required>
					<option value="business"><?php p($l->t('Business')); ?></option>
					<option value="commute"><?php p($l->t('Commute')); ?></option>
					<option value="private"><?php p($l->t('Private')); ?></option>
				</select>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-lbn-date"><?php p($l->t('Trip date')); ?> <span class="mc-required">*</span></label>
				<input id="mc-lbn-date" name="tripDate" type="date" required>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row mc-form-row--checkbox">
				<label class="mc-checkbox-row" for="mc-lbn-round">
					<input id="mc-lbn-round" name="isRoundTrip" type="checkbox" value="1">
					<span><?php p($l->t('Round trip?')); ?> — <?php p($l->t('Departure and destination are the same place (start ↔ end)')); ?></span>
				</label>
			</div>
			<div class="mc-form-row">
				<label for="mc-lbn-dep"><?php p($l->t('Departure time')); ?></label>
				<input id="mc-lbn-dep" name="departureTime" type="time">
				<p class="mc-form-row__hint"><?php p($l->t('Required for business trips.')); ?></p>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-lbn-arr"><?php p($l->t('Arrival time')); ?></label>
				<input id="mc-lbn-arr" name="arrivalTime" type="time">
				<p class="mc-form-row__hint"><?php p($l->t('Required for business trips.')); ?></p>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-lbn-start-addr"><?php p($l->t('Start address')); ?> <span class="mc-required">*</span></label>
				<input id="mc-lbn-start-addr" name="startAddress" type="text" maxlength="500" required autocomplete="off">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-lbn-end-addr"><?php p($l->t('End address')); ?> <span class="mc-required">*</span></label>
				<input id="mc-lbn-end-addr" name="endAddress" type="text" maxlength="500" required autocomplete="off">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-lbn-okm"><?php p($l->t('Start odometer (km)')); ?> <span class="mc-required">*</span></label>
				<input id="mc-lbn-okm" name="odometerStartKm" type="number" min="0" max="9999999" required inputmode="numeric">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-lbn-ekm"><?php p($l->t('End odometer (km)')); ?> <span class="mc-required">*</span></label>
				<input id="mc-lbn-ekm" name="odometerEndKm" type="number" min="0" max="9999999" required inputmode="numeric">
				<p class="mc-form-row__hint" id="mc-lbn-distance-hint"></p>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-lbn-client"><?php p($l->t('Client or contact')); ?></label>
				<input id="mc-lbn-client" name="clientOrContact" type="text" maxlength="250" autocomplete="off">
				<p class="mc-form-row__hint"><?php p($l->t('Required for business trips.')); ?></p>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-lbn-proj"><?php p($l->t('Project / reference')); ?></label>
				<input id="mc-lbn-proj" name="projectReference" type="text" maxlength="120" autocomplete="off">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
		</div>
		<div class="mc-form-row">
			<label for="mc-lbn-purpose"><?php p($l->t('Purpose')); ?> <span class="mc-required">*</span></label>
			<textarea id="mc-lbn-purpose" name="purpose" rows="3" maxlength="4000" required></textarea>
			<p class="mc-form-row__hint"><?php p($l->t('Briefly describe why you drove (e.g. “Customer visit ACME, project XY”). For private trips a short note like “Errand” is fine; the address fields still need real values.')); ?></p>
			<p class="mc-form-row__error" role="alert"></p>
		</div>
		<div class="mc-form-actions">
			<button type="submit" class="button button-primary"><?php p($l->t('Save as draft')); ?></button>
			<a class="button" href="<?php p((string)($_['urls']['logbook'] ?? '#')); ?>"><?php p($l->t('Cancel')); ?></a>
		</div>
		<p class="mc-form-row__hint"><?php p($l->t('You can review the draft and confirm it on the entry page. Once confirmed, the entry is locked.')); ?></p>
	</form>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>
