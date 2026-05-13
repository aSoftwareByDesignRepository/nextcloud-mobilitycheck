<?php
/**
 * Booking detail with check-out / check-in workflow + approval actions.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$bookingId = (int)($_['bookingId'] ?? 0);
$isManager = !empty($_['isManager']);
/** @var array{show:bool,baseLocation:?string,lastNote:?string,lastRecordedAtFormatted:?string,historyCount:int,hasPriorCheckin:bool} $pickupHint */
$pickupHint = $_['pickupHint'] ?? ['show' => false, 'baseLocation' => null, 'lastNote' => null, 'lastRecordedAtFormatted' => null, 'historyCount' => 0, 'hasPriorCheckin' => false];
$pickupHintShow = !empty($pickupHint['show']);
$pickupHasPrior = !empty($pickupHint['hasPriorCheckin']);
?>
<input type="hidden" id="mc-booking-id" value="<?php p($bookingId); ?>">

<section class="mc-card mc-section" aria-labelledby="mc-bk-flow-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-bk-flow-h"><?php p($l->t('Your booking path')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('From reservation to return — including manager approval when your organisation requires it.')); ?></p>
		</div>
		<div class="mc-section__controls">
			<a class="button" href="<?php p((string)($_['urls']['bookings'] ?? '#')); ?>"><?php p($l->t('Back to list')); ?></a>
		</div>
	</header>
	<div id="mc-bk-workflow" class="mc-workflow-host" role="region" aria-label="<?php p($l->t('Booking progress')); ?>"></div>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-bk-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-bk-h">#<?php p($bookingId); ?></h2>
			<p class="mc-section__sub" data-mc-bind="status-line"></p>
		</div>
		<div class="mc-section__controls" id="mc-bk-actions"></div>
	</header>
	<div id="mc-bk-proxy-banner" class="mc-callout mc-callout--info mc-bk-proxy-banner" role="status" hidden></div>
	<dl class="mc-dl" id="mc-bk-dl"></dl>
	<div id="mc-bk-edit-host"></div>
	<div id="mc-bk-return-insights" class="mc-return-insights" role="region" aria-labelledby="mc-bk-return-insights-h" hidden>
		<h3 id="mc-bk-return-insights-h" class="mc-return-insights__heading"><?php p($l->t('Return vs booking end')); ?></h3>
		<div class="mc-return-insights__body" data-mc-bind="return-insights-body"></div>
	</div>
</section>

<?php /* §4.5 step 7 / §13.28 — pickup-location hint: shell + server-rendered body for pool/group before handover. */ ?>
<section class="mc-card mc-section mc-hint" id="mc-bk-pickup-hint"
	 aria-labelledby="mc-bk-pickup-h"
	 data-mc-pickup-hint
	 data-mc-pickup-ssr="<?php p($pickupHintShow ? '1' : '0'); ?>"
	 data-mc-pickup-history-count="<?php p((string)(int)($pickupHint['historyCount'] ?? 0)); ?>"
	 data-mc-pickup-has-prior="<?php p($pickupHasPrior ? '1' : '0'); ?>"
	 <?php if (!$pickupHintShow) { ?>hidden<?php } ?>>
	<header class="mc-section__header">
		<div>
			<h2 id="mc-bk-pickup-h"><?php p($l->t('Where to pick up the vehicle')); ?></h2>
			<p class="mc-section__sub">
				<?php p($l->t('The previous driver tells you where they left the car. Read this carefully before you go to the parking spot.')); ?>
			</p>
		</div>
	</header>
	<div class="mc-hint__body" data-mc-bind="pickup-hint-body">
		<?php if ($pickupHintShow) { ?>
			<?php if (!empty($pickupHint['baseLocation'])) { ?>
				<p class="mc-hint__base"><strong><?php p($l->t('Default base location:')); ?></strong> <?php p($pickupHint['baseLocation']); ?></p>
			<?php } ?>
			<?php if (!$pickupHasPrior) { ?>
				<div class="mc-callout mc-callout--success" role="status">
					<p><strong><?php p($l->t('First trip')); ?></strong> — <?php p($l->t('No previous check-in was recorded for this vehicle. It should be at its default stand unless your fleet manager says otherwise.')); ?></p>
				</div>
			<?php } elseif (!empty($pickupHint['lastNote'])) { ?>
				<div class="mc-hint__primary" role="region" aria-labelledby="mc-bk-pickup-primary-h">
					<h3 id="mc-bk-pickup-primary-h" class="mc-hint__primary-title"><?php p($l->t('Most recent return note')); ?></h3>
					<p class="mc-hint__primary-note"><?php p($pickupHint['lastNote']); ?></p>
					<?php if (!empty($pickupHint['lastRecordedAtFormatted'])) { ?>
						<p class="mc-hint__primary-meta"><?php p($l->t('Recorded {when}.', ['when' => $pickupHint['lastRecordedAtFormatted']])); ?></p>
					<?php } ?>
				</div>
			<?php } else { ?>
				<div class="mc-callout mc-callout--neutral" role="status">
					<p><?php p($l->t('A colleague checked the vehicle in before, but left no return location note. Use the default base location or ask your fleet manager.')); ?></p>
				</div>
			<?php } ?>
		<?php } else { ?>
			<p class="mc-empty"><?php p($l->t('Loading the latest pickup location…')); ?></p>
		<?php } ?>
	</div>
	<noscript>
		<?php if ($pickupHintShow) { ?>
			<p class="mc-field-hint"><?php p($l->t('The pickup information above was prepared on the server when this page loaded.')); ?></p>
		<?php } else { ?>
			<p class="mc-alert mc-alert--warn">
				<?php p($l->t('JavaScript is required to display the latest pickup location. Please ask your fleet manager where to find the vehicle if you cannot enable JavaScript.')); ?>
			</p>
		<?php } ?>
	</noscript>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-bk-logs-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-bk-logs-h"><?php p($l->t('Check-out and check-in')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Mandatory at handover. Odometer and zone checklist build the immutable trip log.')); ?></p>
		</div>
	</header>
	<div id="mc-bk-logs"></div>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-bk-approvals-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-bk-approvals-h"><?php p($l->t('Approval trail')); ?></h2>
			<p class="mc-section__sub">
				<?php p($l->t('Append-only history of every approval decision for this booking. Useful when an auditor asks who signed off and when.')); ?>
			</p>
		</div>
	</header>
	<div id="mc-bk-approvals" data-mc-bind="approvals-body">
		<p class="mc-empty"><?php p($l->t('Loading approval history…')); ?></p>
	</div>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>
