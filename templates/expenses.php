<?php
/**
 * Reimbursement page (Appendix A4) — driver view + manager queue.
 *
 *  - Drivers see their own claims and can manage their registered private
 *    vehicles.
 *  - Managers / fleet admins see the company-wide queue with status filter
 *    and inline actions (approve / reject / mark paid).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$isManager = !empty($_['isManager']);
$isFleetAdmin = !empty($_['isFleetAdmin']);
$isAuditor = !empty($_['isAuditor']);
$isDriver = !empty($_['isDriver']);
$canReview = $isManager || $isFleetAdmin;
?>
<section class="mc-card mc-section" aria-labelledby="mc-ex-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-ex-h"><?php p($l->t('Reimbursement claims')); ?></h2>
			<p class="mc-section__sub">
				<?php if ($canReview): ?>
					<?php p($l->t('Submitted claims await your decision. Approval triggers payroll; mark “paid” once the transfer is completed.')); ?>
				<?php else: ?>
					<?php p($l->t('Claims for business trips you made in your own private car. The statutory rate per kilometre is applied automatically; managers review before payment.')); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php if ($isDriver): ?>
		<div class="mc-section__controls">
			<a class="button button-primary" href="<?php p((string)($_['urls']['expensesNew'] ?? '#')); ?>"><?php p($l->t('New claim')); ?></a>
		</div>
		<?php endif; ?>
	</header>
	<form class="mc-toolbar" id="mc-ex-filters" role="search" aria-label="<?php p($l->t('Claim filters')); ?>">
		<div class="mc-form-row">
			<label for="mc-ex-status"><?php p($l->t('Status')); ?></label>
			<select id="mc-ex-status" name="status">
				<option value=""><?php p($l->t('All')); ?></option>
				<option value="draft"><?php p($l->t('Draft')); ?></option>
				<option value="submitted"><?php p($l->t('Submitted')); ?></option>
				<option value="approved"><?php p($l->t('Approved')); ?></option>
				<option value="rejected"><?php p($l->t('Rejected')); ?></option>
				<option value="paid"><?php p($l->t('Paid')); ?></option>
			</select>
		</div>
		<?php if ($canReview): ?>
		<div class="mc-form-row">
			<label for="mc-ex-driver"><?php p($l->t('Driver')); ?></label>
			<select id="mc-ex-driver" name="driverUserId">
				<option value=""><?php p($l->t('All drivers')); ?></option>
			</select>
		</div>
		<?php endif; ?>
	</form>
	<div id="mc-ex-list" aria-live="polite"></div>
</section>

<?php if ($isDriver): ?>
<section class="mc-card mc-section" aria-labelledby="mc-ex-pv-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-ex-pv-h"><?php p($l->t('My private vehicles')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Only registered private vehicles can be used in a reimbursement claim. The engine type determines the statutory rate (electric vehicles often receive a higher rate).')); ?></p>
		</div>
		<div class="mc-section__controls">
			<button type="button" class="button" id="mc-ex-pv-add"><?php p($l->t('Add private vehicle')); ?></button>
		</div>
	</header>
	<div id="mc-ex-pv-list" aria-live="polite"></div>
</section>
<?php endif; ?>

<section class="mc-card mc-section" aria-labelledby="mc-ex-rates-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-ex-rates-h"><?php p($l->t('Current reimbursement rates')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('The rate that applies to a claim is the one valid on the trip date. Past trips keep their original rate even if the catalogue changes later.')); ?></p>
		</div>
	</header>
	<div id="mc-ex-rates" aria-live="polite"></div>
</section>

<?php include __DIR__ . '/common/page-end.php'; ?>
