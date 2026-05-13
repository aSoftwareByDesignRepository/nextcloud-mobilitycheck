<?php
/**
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
?>
<section class="mc-card mc-section" aria-labelledby="mc-rs-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-rs-h"><?php p($l->t('Open reassignment suggestions')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Each row proposes moving a future booking to another vehicle because the current vehicle is unavailable. Accept only after verifying the driver and time window.')); ?></p>
		</div>
		<div class="mc-section__controls">
			<button type="button" class="button" id="mc-rs-refresh" aria-label="<?php p($l->t('Refresh list')); ?>"><?php p($l->t('Refresh')); ?></button>
		</div>
	</header>
	<div id="mc-rs-error" class="mc-page-error" role="alert" hidden></div>
	<div id="mc-rs-list" class="mc-table-host" role="region" aria-labelledby="mc-rs-h"></div>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>
