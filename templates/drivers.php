<?php
/**
 * Drivers list — fleet admin / manager only.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
?>
<section class="mc-card mc-section" aria-labelledby="mc-driv-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-driv-h"><?php p($l->t('Driver register')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Onboarded drivers with their licence and instruction status. Add a Nextcloud user to register a new driver.')); ?></p>
		</div>
		<div class="mc-section__controls">
			<button type="button" class="button button-primary" id="mc-driv-new"><?php p($l->t('Add driver')); ?></button>
		</div>
	</header>
	<div id="mc-driv-list" aria-live="polite"></div>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>
