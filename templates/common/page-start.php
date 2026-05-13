<?php
/**
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\MobilityCheck\Service\IconCatalog;

$pageId = (string)($_['pageId'] ?? '');
$pageTitle = (string)($_['pageTitle'] ?? $l->t('MobilityCheck'));
$pageHelp = (string)($_['pageHelp'] ?? '');
$roleLabel = (string)($_['roleLabel'] ?? $l->t('MobilityCheck user'));
$clientHints = (array)($_['clientHints'] ?? []);
$htmlLang = (string)($clientHints['htmlLang'] ?? 'en-US');
$timezone = (string)($clientHints['timezone'] ?? 'UTC');
$urls = (array)($_['urls'] ?? []);
$urlsJson = htmlspecialchars(json_encode($urls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$glossary = (array)($_['glossary'] ?? []);
$glossaryJson = htmlspecialchars(json_encode($glossary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$currency = (string)($_['currency'] ?? 'EUR');
$currentUserId = (string)($_['currentUserId'] ?? '');
$isAppAdmin = !empty($_['isAppAdmin']);
$isFleetAdmin = !empty($_['isFleetAdmin']);
$isManager = !empty($_['isManager']);
$isDriver = !empty($_['isDriver']);
$isAuditor = !empty($_['isAuditor']);
$isWorkshop = !empty($_['isWorkshop']);
$lineManagerScopedReader = !empty($_['lineManagerScopedReader']);

$icons = [
	'dashboard' => 'layout-grid',
	'vehicles' => 'car',
	'vehicle-detail' => 'car',
	'drivers' => 'users',
	'driver-detail' => 'user',
	'bookings' => 'calendar',
	'booking-new' => 'plus',
	'booking-detail' => 'clipboard-list',
	'damage' => 'alert-triangle',
	'damage-detail' => 'alert-triangle',
	'costs' => 'coins',
	'maintenance' => 'tool',
	'compliance' => 'shield-check',
	'reports' => 'file-analytics',
	'settings' => 'settings',
	'logbook' => 'clipboard-list',
	'logbook-new' => 'plus',
	'logbook-detail' => 'clipboard-list',
	'expenses' => 'coins',
	'expenses-new' => 'plus',
	'exports' => 'file-analytics',
	'tax-benefit' => 'percent',
];
?>
<?php include __DIR__ . '/navigation.php'; ?>
<div id="app-content" class="mc-app mc-app--<?php p($pageId); ?>"
	lang="<?php p($htmlLang); ?>"
	data-mc-page="<?php p($pageId); ?>"
	data-mc-timezone="<?php p($timezone); ?>"
	data-mc-currency="<?php p($currency); ?>"
	data-mc-current-user="<?php p($currentUserId); ?>"
	data-mc-is-app-admin="<?php p($isAppAdmin ? '1' : '0'); ?>"
	data-mc-is-fleet-admin="<?php p($isFleetAdmin ? '1' : '0'); ?>"
	data-mc-is-manager="<?php p($isManager ? '1' : '0'); ?>"
	data-mc-is-line-manager="<?php p(!empty($_['isLineManager']) ? '1' : '0'); ?>"
	data-mc-is-driver="<?php p($isDriver ? '1' : '0'); ?>"
	data-mc-is-auditor="<?php p($isAuditor ? '1' : '0'); ?>"
	data-mc-line-manager-scoped-reader="<?php p($lineManagerScopedReader ? '1' : '0'); ?>"
	data-mc-is-workshop="<?php p($isWorkshop ? '1' : '0'); ?>"
	data-mc-urls="<?php print_unescaped($urlsJson); ?>"
	data-mc-glossary="<?php print_unescaped($glossaryJson); ?>">
	<a class="mc-skip-link" href="#mc-main-content"><?php p($l->t('Skip to main content')); ?></a>
	<div id="mc-live-region" class="mc-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="mc-alert-region" class="mc-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="mc-shell">
		<header class="mc-page-header" aria-labelledby="mc-page-title">
			<nav class="mc-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
				<ol>
					<li><a href="<?php p((string)($urls['dashboard'] ?? '#')); ?>"><?php p($l->t('MobilityCheck')); ?></a></li>
					<li aria-hidden="true">/</li>
					<li aria-current="page"><?php p($pageTitle); ?></li>
				</ol>
			</nav>
			<div class="mc-page-header__main">
				<div class="mc-page-header__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render($icons[$pageId] ?? 'car', 'mc-page-header__icon-svg')); ?></div>
				<div class="mc-page-header__text">
					<h1 id="mc-page-title"><?php p($pageTitle); ?></h1>
					<p class="mc-page-lead"><?php p($pageHelp); ?></p>
				</div>
				<div id="mc-page-actions" class="mc-page-header__actions" aria-live="polite"></div>
			</div>
			<div class="mc-scope-strip" aria-label="<?php p($l->t('Active session context')); ?>">
				<span class="mc-scope-strip__label"><?php p($l->t('Role')); ?></span>
				<span class="mc-badge"><?php p($roleLabel); ?></span>
				<span aria-hidden="true">·</span>
				<span class="mc-scope-strip__label"><?php p($l->t('Timezone')); ?></span>
				<span class="mc-scope-strip__value"><?php p($timezone); ?></span>
			</div>
		</header>
		<main id="mc-main-content" class="mc-main" tabindex="-1" aria-labelledby="mc-page-title">
