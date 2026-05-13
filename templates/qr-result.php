<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $_
 * @var \OCP\IL10N $l
 */
script('mobilitycheck', 'qr-result');
style('mobilitycheck', 'common/tokens');
style('mobilitycheck', 'app');

$kind = (string)($_['kind'] ?? 'unknown_vehicle');
$vehicleName = (string)($_['vehicleName'] ?? '');
$vehicleId = (int)($_['vehicleId'] ?? 0);
$retryAfter = (int)($_['retryAfter'] ?? 0);
?>
<div id="app-content" class="mc-app">
	<div id="app-content-wrapper">
		<main class="mc-page" role="main">
			<header class="mc-page-header">
				<h1 class="mc-page-title">
					<?php p($l->t('QR scan result')); ?>
				</h1>
				<p class="mc-page-lead">
					<?php
					if ($kind === 'unknown_vehicle') {
						p($l->t('We could not find a vehicle for this QR sticker. Please ask your fleet administrator to reprint the QR.'));
					} elseif ($kind === 'token_invalid') {
						p($l->t('This QR sticker has been rotated and is no longer valid. Please ask your fleet administrator for the current sticker.'));
					} elseif ($kind === 'no_booking') {
						p($l->t('You do not have an approved booking that is starting soon, and you are not currently driving this vehicle. Please contact a fleet manager if you believe this is wrong.'));
					} elseif ($kind === 'rate_limited') {
						p($l->t('Too many scans in a short time. Please wait a moment and try again. If you keep seeing this, contact your fleet administrator.'));
					}
					?>
				</p>
			</header>
			<section class="mc-card mc-card--padded" aria-labelledby="qr-info-heading">
				<h2 id="qr-info-heading" class="mc-card__title">
					<?php p($vehicleName !== '' ? $vehicleName : $l->t('Vehicle')); ?>
				</h2>
				<dl class="mc-detail-grid">
					<dt><?php p($l->t('Vehicle id')); ?></dt>
					<dd><?php p((string)$vehicleId); ?></dd>
				</dl>
				<div class="mc-card__actions">
					<a class="mc-button mc-button--primary"
					   href="<?php p(\OC::$server->getURLGenerator()->linkToRoute('mobilitycheck.page.dashboard')); ?>">
						<?php p($l->t('Back to dashboard')); ?>
					</a>
				</div>
			</section>
		</main>
	</div>
</div>
