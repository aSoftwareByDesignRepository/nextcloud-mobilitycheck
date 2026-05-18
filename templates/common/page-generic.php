<?php
/**
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\MobilityCheck\Service\IconCatalog;

$endpoint = (string)($_['apiEndpoint'] ?? '');
$emptyHeading = (string)($_['emptyHeading'] ?? $l->t('Nothing to show yet'));
$emptyCopy = (string)($_['emptyCopy'] ?? $l->t('When records exist, they appear here.'));
$emptyAction = (string)($_['emptyAction'] ?? '');
$emptyActionUrl = (string)($_['emptyActionUrl'] ?? '#');
?>
<section class="mc-card mc-section" aria-labelledby="mc-list-title">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-list-title"><?php p($l->t('Live data')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('This section loads server-side data with your current permissions.')); ?></p>
		</div>
	</header>
	<div class="mc-table-wrap">
		<table class="mc-table" aria-describedby="mc-list-title">
			<thead><tr><th><?php p($l->t('Record')); ?></th><th><?php p($l->t('Details')); ?></th></tr></thead>
			<tbody id="mc-data-table-body"></tbody>
		</table>
		<div class="mc-card-list" id="mc-data-card-list" aria-hidden="true"></div>
	</div>
	<div class="mc-empty-state" id="mc-empty-state" hidden>
		<div class="mc-empty-state__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('inbox')); ?></div>
		<div class="mc-empty-state__main">
			<h3><?php p($emptyHeading); ?></h3>
			<p><?php p($emptyCopy); ?></p>
		</div>
		<?php if ($emptyAction !== ''): ?>
			<div class="mc-empty-state__action">
				<a class="button button-primary" href="<?php p($emptyActionUrl); ?>"><?php p($emptyAction); ?></a>
			</div>
		<?php endif; ?>
	</div>
	<div id="mc-loading" class="mc-loading" role="status" aria-live="polite" aria-busy="true"><?php p($l->t('Loading…')); ?></div>
	<div class="mc-sr-only" data-mc-api-endpoint="<?php p($endpoint); ?>"></div>
</section>
