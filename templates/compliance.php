<?php
/**
 * Compliance overview — fleet manager / admin view.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$year = (int)gmdate('Y');
?>
<section class="mc-card mc-section" aria-labelledby="mc-cmp-inst-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-cmp-inst-h"><?php p($l->t('Yearly instructions (UVV)')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Each driver must complete the yearly safety briefing during the calendar year.')); ?></p>
		</div>
		<div class="mc-section__controls">
			<label for="mc-cmp-year"><?php p($l->t('Year')); ?></label>
			<input id="mc-cmp-year" type="number" min="2020" max="2099" value="<?php p($year); ?>" style="width: 7rem;">
		</div>
	</header>
	<div id="mc-cmp-inst" aria-live="polite"></div>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-cmp-lic-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-cmp-lic-h"><?php p($l->t('Licence overview')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Status of every driver licence on file.')); ?></p>
		</div>
	</header>
	<div id="mc-cmp-lic"></div>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>
