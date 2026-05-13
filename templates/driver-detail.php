<?php
/**
 * Driver detail — managers see full data, drivers see their own profile only.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$driverProfileId = (int)($_['driverProfileId'] ?? 0);
$isManager = !empty($_['isManager']);
?>
<input type="hidden" id="mc-driver-id" value="<?php p($driverProfileId); ?>">

<section class="mc-card mc-section" aria-labelledby="mc-drv-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-drv-h" data-mc-bind="user_id"><?php p($l->t('Driver')); ?></h2>
			<p class="mc-section__sub" data-mc-bind="licence_status"></p>
		</div>
		<div class="mc-section__controls">
			<a class="button" href="<?php p((string)($_['urls']['drivers'] ?? '#')); ?>"><?php p($l->t('Back to list')); ?></a>
			<?php if ($isManager): ?>
				<button type="button" class="button" id="mc-drv-verify"><?php p($l->t('Verify licence')); ?></button>
				<button type="button" class="button mc-danger-button" id="mc-drv-reject"><?php p($l->t('Reject licence')); ?></button>
			<?php endif; ?>
		</div>
	</header>
	<dl class="mc-dl" id="mc-drv-dl"></dl>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-drv-related-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-drv-related-h"><?php p($l->t('More for this driver')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Open list pages with this driver pre-selected in the filter where supported.')); ?></p>
		</div>
	</header>
	<ul class="mc-related-list" id="mc-drv-related"></ul>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-drv-edit-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-drv-edit-h"><?php p($l->t('Profile and licence')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Update licence details. Status changes require a manager.')); ?></p>
		</div>
	</header>
	<form id="mc-drv-form" class="mc-form" novalidate>
		<div class="mc-grid-2">
			<div class="mc-form-row">
				<label for="mc-drv-num"><?php p($l->t('Licence number')); ?></label>
				<input id="mc-drv-num" name="licence_number" type="text" maxlength="64" autocomplete="off">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-drv-class"><?php p($l->t('Classes (e.g. B BE C1)')); ?></label>
				<input id="mc-drv-class" name="licence_classes" type="text" placeholder="<?php p($l->t('e.g. B, BE')); ?>" autocomplete="off">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-drv-issue"><?php p($l->t('Issue date')); ?></label>
				<input id="mc-drv-issue" name="licence_issue_date" type="date">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-drv-exp"><?php p($l->t('Expiry date')); ?></label>
				<input id="mc-drv-exp" name="licence_expiry_date" type="date">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-drv-auth"><?php p($l->t('Issuing authority')); ?></label>
				<input id="mc-drv-auth" name="licence_authority" type="text" maxlength="120" autocomplete="off">
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<?php if ($isManager): ?>
			<div class="mc-form-row">
				<label for="mc-drv-commute"><?php p($l->t('Commute distance (km, one way)')); ?></label>
				<input id="mc-drv-commute" name="commute_distance_km" type="number" min="0" max="1000">
				<p class="mc-form-row__hint"><?php p($l->t('Used for the one-percent rule tax computation if enabled.')); ?></p>
				<p class="mc-form-row__error" role="alert"></p>
			</div>
			<?php endif; ?>
		</div>
		<div class="mc-form-row">
			<label for="mc-drv-notes"><?php p($l->t('Notes')); ?></label>
			<textarea id="mc-drv-notes" name="notes" rows="3"></textarea>
		</div>
		<div class="mc-form-actions">
			<button type="submit" class="button button-primary"><?php p($l->t('Save changes')); ?></button>
		</div>
	</form>
	<hr aria-hidden="true">
	<div class="mc-form-row">
		<label for="mc-drv-upload"><?php p($l->t('Upload licence scan (PDF or photo)')); ?></label>
		<input type="file" id="mc-drv-upload" accept="image/*,application/pdf">
		<p class="mc-form-row__hint"><?php p($l->t('Files are stored in your Nextcloud “MobilityCheck/Licences” folder.')); ?></p>
	</div>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-drv-compl-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-drv-compl-h"><?php p($l->t('Compliance history')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Annual instruction records and licence verification log.')); ?></p>
		</div>
	</header>
	<h3 class="mc-section__sub" id="mc-drv-instr-h"><?php p($l->t('Yearly instructions (UVV)')); ?></h3>
	<div id="mc-drv-instr"></div>
	<h3 class="mc-section__sub" id="mc-drv-ver-h"><?php p($l->t('Licence verifications')); ?></h3>
	<div id="mc-drv-ver"></div>
</section>

<?php include __DIR__ . '/common/page-end.php'; ?>
