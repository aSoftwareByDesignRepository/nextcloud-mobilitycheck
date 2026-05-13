<?php
/**
 * New booking form.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
?>
<section class="mc-card mc-section" aria-labelledby="mc-bnew-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-bnew-h"><?php p($l->t('New booking')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Reserve a vehicle for a specific time window. Conflicts and eligibility are checked automatically before submission.')); ?></p>
		</div>
		<div class="mc-section__controls">
			<a class="button" href="<?php p((string)($_['urls']['bookings'] ?? '#')); ?>"><?php p($l->t('Back to list')); ?></a>
		</div>
	</header>
	<div class="mc-callout mc-callout--neutral mc-bnew-guide" role="region" aria-labelledby="mc-bnew-how-h">
		<h2 id="mc-bnew-how-h" class="mc-callout__title"><?php p($l->t('How reservations work')); ?></h2>
		<p><?php p($l->t('You choose vehicle, start and end time, then submit. If your organisation uses approvals, a line manager and/or fleet manager confirms the request; otherwise it becomes approved immediately. When the booking is approved and you are ready to leave, open the booking from the list: check-out records the handover, check-in closes the trip. For pool and group cars you must note where you park at return so the next colleague can find the vehicle.')); ?></p>
	</div>
	<form id="mc-bnew-form" class="mc-form" novalidate>
		<div id="mc-bnew-proxy-section" class="mc-callout mc-callout--neutral mc-bnew-proxy" role="region" aria-labelledby="mc-bnew-proxy-h" hidden>
			<h2 id="mc-bnew-proxy-h" class="mc-callout__title"><?php p($l->t('Book for someone else')); ?></h2>
			<p class="mc-form-row__hint"><?php p($l->t('Fleet and app administrators can reserve a vehicle for a driver. The driver is notified and must acknowledge or cancel unwanted bookings.')); ?></p>
			<div class="mc-form-row">
				<label for="mc-bnew-behalf-combo"><?php p($l->t('Driver for this reservation')); ?></label>
				<div id="mc-bnew-behalf-mount"></div>
				<p id="mc-bnew-behalf-hint" class="mc-form-row__hint"><?php p($l->t('Leave empty to book for yourself. When you pick a colleague, only vehicles they may drive during your dates are listed.')); ?></p>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
		</div>
		<div class="mc-grid-2">
			<div class="mc-form-row">
				<label for="mc-bnew-vehicle"><?php p($l->t('Vehicle')); ?> <span class="mc-required" aria-label="<?php p($l->t('required')); ?>">*</span></label>
				<select id="mc-bnew-vehicle" name="vehicleId" required></select>
				<p class="mc-form-row__hint"><?php p($l->t('Only vehicles you are eligible to drive are listed.')); ?></p>
				<p id="mc-bnew-alloc-hint" class="mc-form-row__hint mc-callout mc-callout--neutral" hidden aria-live="polite"></p>
				<p id="mc-bnew-stand" class="mc-form-row__hint mc-bnew-stand" hidden aria-live="polite"></p>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-bnew-start"><?php p($l->t('Start')); ?> <span class="mc-required" aria-label="<?php p($l->t('required')); ?>">*</span></label>
				<input id="mc-bnew-start" name="startDatetime" type="datetime-local" required>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-bnew-end"><?php p($l->t('End')); ?> <span class="mc-required" aria-label="<?php p($l->t('required')); ?>">*</span></label>
				<input id="mc-bnew-end" name="endDatetime" type="datetime-local" required>
				<p class="mc-form-row__hint"><?php p($l->t('Minimum 15 minutes, maximum 90 days.')); ?></p>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-bnew-dest"><?php p($l->t('Destination')); ?></label>
				<input id="mc-bnew-dest" name="destination" type="text" maxlength="250" autocomplete="off">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-bnew-cc"><?php p($l->t('Cost centre')); ?></label>
				<input id="mc-bnew-cc" name="costCentre" type="text" maxlength="80" autocomplete="off">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-bnew-km"><?php p($l->t('Expected distance (km)')); ?></label>
				<input id="mc-bnew-km" name="expectedDistanceKm" type="number" min="0" max="1000000">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
		</div>
		<div class="mc-form-row">
			<label for="mc-bnew-purpose"><?php p($l->t('Purpose')); ?> <span class="mc-required" aria-label="<?php p($l->t('required')); ?>">*</span></label>
			<textarea id="mc-bnew-purpose" name="purpose" required minlength="4" maxlength="250" rows="3"></textarea>
			<p class="mc-form-row__hint"><?php p($l->t('Briefly describe the business purpose. Required for audit purposes (Fahrtenbuch).')); ?></p>
			<p class="mc-form-row__error" role="alert"></p>
		</div>
		<fieldset id="mc-bnew-stations-fieldset" class="mc-fieldset" hidden>
			<legend><?php p($l->t('Stations (optional)')); ?></legend>
			<p class="mc-form-row__hint"><?php p($l->t('Pick different sites only when your organisation uses depots and allows one-way bookings between them.')); ?></p>
			<div class="mc-grid-2">
				<div class="mc-form-row">
					<label for="mc-bnew-pickup-st"><?php p($l->t('Pickup station')); ?></label>
					<select id="mc-bnew-pickup-st" name="pickupStationId" aria-describedby="mc-bnew-stations-hint">
						<option value=""><?php p($l->t('Use vehicle default')); ?></option>
					</select>
					<p class="mc-form-row__error" role="alert"></p>
				</div>
				<div class="mc-form-row">
					<label for="mc-bnew-return-st"><?php p($l->t('Return station')); ?></label>
					<select id="mc-bnew-return-st" name="returnStationId" aria-describedby="mc-bnew-stations-hint">
						<option value=""><?php p($l->t('Same as pickup')); ?></option>
					</select>
					<p class="mc-form-row__error" role="alert"></p>
				</div>
			</div>
			<p id="mc-bnew-stations-hint" class="mc-form-row__hint"><?php p($l->t('Leaving both on default follows each vehicle’s configured depot.')); ?></p>
		</fieldset>
		<div id="mc-bnew-cross-wrap" class="mc-form-row" hidden>
			<label for="mc-bnew-cross"><?php p($l->t('Cross-site booking justification')); ?></label>
			<textarea id="mc-bnew-cross" name="crossStationReason" maxlength="2000" rows="2" aria-describedby="mc-bnew-cross-hint"></textarea>
			<p id="mc-bnew-cross-hint" class="mc-form-row__hint"><?php p($l->t('Fleet managers: when strict station rules apply and this reservation uses a vehicle outside the driver’s home site, enter at least 10 characters.')); ?></p>
			<p class="mc-form-row__error" role="alert"></p>
		</div>
		<div class="mc-form-actions">
			<button type="submit" class="button button-primary"><?php p($l->t('Request booking')); ?></button>
			<a class="button" href="<?php p((string)($_['urls']['bookings'] ?? '#')); ?>"><?php p($l->t('Cancel')); ?></a>
		</div>
	</form>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>
