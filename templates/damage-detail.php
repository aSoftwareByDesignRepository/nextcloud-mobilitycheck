<?php
/**
 * Damage report detail with photo upload and status transitions.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$damageReportId = (int)($_['damageReportId'] ?? 0);
?>
<input type="hidden" id="mc-damage-id" value="<?php p($damageReportId); ?>">

<section class="mc-card mc-section" aria-labelledby="mc-dmg-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-dmg-h"><?php p($l->t('Damage report')); ?> #<?php p($damageReportId); ?></h2>
			<p class="mc-section__sub" data-mc-bind="status-line"></p>
		</div>
		<div class="mc-section__controls">
			<a class="button" href="<?php p((string)($_['urls']['damage'] ?? '#')); ?>"><?php p($l->t('Back to list')); ?></a>
			<div id="mc-dmg-actions"></div>
		</div>
	</header>
	<dl class="mc-dl" id="mc-dmg-dl"></dl>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-dmg-photos-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-dmg-photos-h"><?php p($l->t('Photos')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Upload one or more photos to support the report.')); ?></p>
		</div>
		<div class="mc-section__controls">
			<label class="button button-primary" for="mc-dmg-photo">
				<?php p($l->t('Add photo')); ?>
				<input id="mc-dmg-photo" type="file" accept="image/*" class="mc-sr-only">
			</label>
		</div>
	</header>
	<div id="mc-dmg-photos"></div>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-dmg-amend-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-dmg-amend-h"><?php p($l->t('Amendments')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Corrections create a new amendment row — original reports are immutable.')); ?></p>
		</div>
	</header>
	<div id="mc-dmg-amend"></div>
</section>

<?php include __DIR__ . '/common/page-end.php'; ?>
