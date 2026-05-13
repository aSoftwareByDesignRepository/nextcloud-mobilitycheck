<?php
/**
 * One Fahrtenbuch entry — draft → confirm → immutable / amend (Appendix A3).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$entryId = (int)($_['logbookEntryId'] ?? 0);
?>
<section class="mc-card mc-section" aria-labelledby="mc-lbd-h" data-entry-id="<?php p((string)$entryId); ?>">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-lbd-h"><?php p($l->t('Logbook entry')); ?> #<?php p((string)$entryId); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Drafts can still be edited. Confirmed entries are immutable and only changeable through an amendment with a documented reason.')); ?></p>
		</div>
		<div class="mc-section__controls">
			<a class="button" href="<?php p((string)($_['urls']['logbook'] ?? '#')); ?>"><?php p($l->t('Back to list')); ?></a>
		</div>
	</header>
	<div id="mc-lbd-host" aria-live="polite"></div>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>
