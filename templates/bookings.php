<?php
/**
 * Bookings list.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$isManager = !empty($_['isManager']);
$isDriver = !empty($_['isDriver']);
$isLineManager = !empty($_['isLineManager']);
$canApprove = $isManager || $isLineManager;
?>
<section class="mc-card mc-section" aria-labelledby="mc-bks-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-bks-h"><?php p($l->t('Bookings')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Drivers see their own bookings here. Managers and line managers see every booking in scope. Use the view switch below to focus on items waiting for your decision.')); ?></p>
		</div>
		<?php if ($isDriver): ?>
		<div class="mc-section__controls">
			<a class="button button-primary" href="<?php p((string)($_['urls']['bookingNew'] ?? '#')); ?>"><?php p($l->t('New booking')); ?></a>
		</div>
		<?php endif; ?>
	</header>

	<?php if ($canApprove): ?>
	<div class="mc-view-tabs" role="tablist" aria-label="<?php p($l->t('Booking view')); ?>" id="mc-bks-view-tabs">
		<button type="button" class="mc-view-tab is-active" id="mc-bks-tab-all" role="tab" aria-selected="true" aria-controls="mc-bks-list" tabindex="0" data-mc-view="all"><?php p($l->t('All bookings')); ?></button>
		<button type="button" class="mc-view-tab" id="mc-bks-tab-approvals" role="tab" aria-selected="false" aria-controls="mc-bks-list" tabindex="-1" data-mc-view="approvals">
			<?php p($l->t('Awaiting my approval')); ?>
			<span class="mc-view-tab__count" id="mc-bks-approvals-count" aria-hidden="true" hidden></span>
		</button>
	</div>
	<?php endif; ?>

	<form class="mc-toolbar" id="mc-bks-filters" role="search" aria-label="<?php p($l->t('Booking filters')); ?>">
		<div class="mc-form-row">
			<label for="mc-bks-status"><?php p($l->t('Status')); ?></label>
			<select id="mc-bks-status" name="status">
				<option value=""><?php p($l->t('All statuses')); ?></option>
				<option value="pending_fleet"><?php p($l->t('Pending fleet approval')); ?></option>
				<option value="pending_line_manager"><?php p($l->t('Pending line manager approval')); ?></option>
				<option value="pending_approval"><?php p($l->t('Pending approval (legacy)')); ?></option>
				<option value="approved"><?php p($l->t('Approved')); ?></option>
				<option value="active"><?php p($l->t('Active')); ?></option>
				<option value="completed"><?php p($l->t('Completed')); ?></option>
				<option value="rejected"><?php p($l->t('Rejected')); ?></option>
				<option value="cancelled"><?php p($l->t('Cancelled')); ?></option>
			</select>
		</div>
		<div class="mc-form-row">
			<label for="mc-bks-vehicle"><?php p($l->t('Vehicle')); ?></label>
			<select id="mc-bks-vehicle" name="vehicleId">
				<option value=""><?php p($l->t('All vehicles')); ?></option>
			</select>
		</div>
		<div class="mc-form-row">
			<label for="mc-bks-driver"><?php p($l->t('Driver')); ?></label>
			<select id="mc-bks-driver" name="driverUserId">
				<option value=""><?php p($l->t('All drivers')); ?></option>
			</select>
		</div>
		<div class="mc-form-row">
			<label for="mc-bks-from"><?php p($l->t('From')); ?></label>
			<input id="mc-bks-from" name="from" type="date">
		</div>
		<div class="mc-form-row">
			<label for="mc-bks-to"><?php p($l->t('To')); ?></label>
			<input id="mc-bks-to" name="to" type="date">
		</div>
	</form>
	<?php if ($canApprove): ?>
	<div id="mc-bks-list" role="tabpanel" aria-labelledby="mc-bks-tab-all" aria-live="polite"></div>
	<?php else: ?>
	<div id="mc-bks-list" aria-live="polite"></div>
	<?php endif; ?>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>
