<?php
/**
 * New reimbursement claim — driver flow. Statutory rate is applied
 * server-side based on jurisdiction, engine type and trip date.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
?>
<section class="mc-card mc-section" aria-labelledby="mc-exn-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-exn-h"><?php p($l->t('New reimbursement claim')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('File a business trip you made in your own private car. The statutory rate per kilometre is applied automatically; your manager reviews the claim before payment.')); ?></p>
		</div>
		<div class="mc-section__controls">
			<a class="button" href="<?php p((string)($_['urls']['expenses'] ?? '#')); ?>"><?php p($l->t('Back to list')); ?></a>
		</div>
	</header>

	<form id="mc-exn-form" class="mc-form" novalidate>
		<div class="mc-grid-2">
			<div class="mc-form-row">
				<label for="mc-exn-pv"><?php p($l->t('Private vehicle')); ?> <span class="mc-required" aria-label="<?php p($l->t('required')); ?>">*</span></label>
				<select id="mc-exn-pv" name="privateVehicleId" required></select>
				<p class="mc-form-row__hint"><?php p($l->t('Only your active private vehicles. Add a vehicle on the Expenses page if the list is empty.')); ?></p>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-exn-jc"><?php p($l->t('Jurisdiction code')); ?></label>
				<input id="mc-exn-jc" name="jurisdictionCode" type="text" value="DE" maxlength="10" autocomplete="off">
				<p class="mc-form-row__hint"><?php p($l->t('Two-letter country code. Determines which rate catalogue applies.')); ?></p>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-exn-date"><?php p($l->t('Trip date')); ?> <span class="mc-required">*</span></label>
				<input id="mc-exn-date" name="tripDate" type="date" required>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-exn-dist"><?php p($l->t('Distance (km)')); ?> <span class="mc-required">*</span></label>
				<input id="mc-exn-dist" name="distanceKm" type="number" min="1" max="99999" required inputmode="numeric">
				<p class="mc-form-row__hint" id="mc-exn-preview" aria-live="polite"></p>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-exn-dep"><?php p($l->t('Departure time')); ?></label>
				<input id="mc-exn-dep" name="departureTime" type="time">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-exn-arr"><?php p($l->t('Arrival time')); ?></label>
				<input id="mc-exn-arr" name="arrivalTime" type="time">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-exn-start"><?php p($l->t('Start address')); ?> <span class="mc-required">*</span></label>
				<input id="mc-exn-start" name="startAddress" type="text" maxlength="500" required autocomplete="off">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-exn-end"><?php p($l->t('End address')); ?> <span class="mc-required">*</span></label>
				<input id="mc-exn-end" name="endAddress" type="text" maxlength="500" required autocomplete="off">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-exn-client"><?php p($l->t('Client or contact')); ?></label>
				<input id="mc-exn-client" name="clientOrContact" type="text" maxlength="250" autocomplete="off">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-exn-proj"><?php p($l->t('Project / reference')); ?></label>
				<input id="mc-exn-proj" name="projectReference" type="text" maxlength="120" autocomplete="off">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
		</div>
		<div class="mc-form-row">
			<label for="mc-exn-purpose"><?php p($l->t('Purpose')); ?> <span class="mc-required">*</span></label>
			<textarea id="mc-exn-purpose" name="purpose" rows="3" maxlength="4000" required></textarea>
			<p class="mc-form-row__hint"><?php p($l->t('Describe the business reason for the trip in a short, audit-friendly sentence.')); ?></p>
			<p class="mc-form-row__error" role="alert"></p>
		</div>
		<div class="mc-form-row">
			<label for="mc-exn-pass"><?php p($l->t('Passengers (optional)')); ?></label>
			<input id="mc-exn-pass" name="passengers" type="text" maxlength="500" autocomplete="off">
			<p class="mc-form-row__hint"><?php p($l->t('Comma-separated names or initials, if applicable.')); ?></p>
		</div>
		<div class="mc-form-actions">
			<button type="submit" class="button button-primary"><?php p($l->t('Create draft')); ?></button>
			<a class="button" href="<?php p((string)($_['urls']['expenses'] ?? '#')); ?>"><?php p($l->t('Cancel')); ?></a>
		</div>
		<p class="mc-form-row__hint"><?php p($l->t('You can review and then submit the draft from the Expenses list.')); ?></p>
	</form>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>
