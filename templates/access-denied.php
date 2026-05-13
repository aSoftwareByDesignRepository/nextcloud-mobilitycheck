<?php
/**
 * @var array $_
 * @var \OCP\IL10N $l
 */
$message = (string)($_['message'] ?? $l->t('Access denied.'));
$hint = (string)($_['hint'] ?? '');
$homeUrl = (string)($_['homeUrl'] ?? '/');
?>
<div class="mc-access-denied">
	<h1><?php p($l->t('MobilityCheck access denied')); ?></h1>
	<p><?php p($message); ?></p>
	<?php if ($hint !== ''): ?><p><?php p($hint); ?></p><?php endif; ?>
	<p><a class="button button-primary" href="<?php p($homeUrl); ?>"><?php p($l->t('Back to home')); ?></a></p>
</div>
