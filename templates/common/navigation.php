<?php
/**
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\MobilityCheck\Service\IconCatalog;

$urls = (array) ($_['urls'] ?? []);
$pageId = (string) ($_['pageId'] ?? '');
$isDriver = !empty($_['isDriver']);
$isManager = !empty($_['isManager']);
$isFleetAdmin = !empty($_['isFleetAdmin']);
$isAppAdmin = !empty($_['isAppAdmin']);
$isAuditor = !empty($_['isAuditor']);
$isLineManager = !empty($_['isLineManager']);
$isWorkshop = !empty($_['isWorkshop']);
$roleLabel = (string) ($_['roleLabel'] ?? $l->t('MobilityCheck user'));

$groups = [];
if ($isDriver || $isManager || $isFleetAdmin || $isAuditor || $isLineManager) {
	$overviewItems = [
		['id' => 'dashboard', 'label' => $l->t('Dashboard'), 'hint' => $l->t('Current status and priorities'), 'icon' => 'layout-grid', 'url' => $urls['dashboard'] ?? '#'],
		['id' => 'bookings', 'label' => $l->t('Bookings'), 'hint' => $l->t('Reserve and manage vehicle usage'), 'icon' => 'calendar', 'url' => $urls['bookings'] ?? '#'],
	];
	if ($isManager || $isFleetAdmin || $isAppAdmin) {
		$overviewItems[] = ['id' => 'reassignment-suggestions', 'label' => $l->t('Reassignment suggestions'), 'hint' => $l->t('Review automatic vehicle replacement proposals'), 'icon' => 'route', 'url' => $urls['reassignmentSuggestions'] ?? '#'];
	}
	if ($isDriver || $isManager || $isAppAdmin) {
		$overviewItems[] = ['id' => 'booking-new', 'label' => $l->t('New booking'), 'hint' => $l->t('Start a fresh reservation'), 'icon' => 'plus', 'url' => $urls['bookingNew'] ?? '#'];
	}
	$overviewItems[] = ['id' => 'damage', 'label' => $l->t('Damage'), 'hint' => $l->t('Report and track incidents'), 'icon' => 'alert-triangle', 'url' => $urls['damage'] ?? '#'];
	$groups[] = [
		'title' => $l->t('Overview'),
		'items' => $overviewItems,
	];
}
// ── Personal mobility (driver, manager, admin) — logbook + reimbursement.
if ($isDriver || $isManager || $isFleetAdmin || $isAuditor) {
	$mobilityItems = [
		['id' => 'logbook', 'label' => $l->t('Logbook'), 'hint' => $l->t('Fahrtenbuch — business, commute and private trips'), 'icon' => 'clipboard-list', 'url' => $urls['logbook'] ?? '#'],
	];
	if ($isDriver) {
		$mobilityItems[] = ['id' => 'expenses', 'label' => $l->t('Expenses'), 'hint' => $l->t('Reimbursement for private vehicle trips'), 'icon' => 'coins', 'url' => $urls['expenses'] ?? '#'];
	} elseif ($isManager || $isFleetAdmin || $isAuditor) {
		$mobilityItems[] = ['id' => 'expenses', 'label' => $l->t('Expenses'), 'hint' => $l->t('Review and approve reimbursement claims'), 'icon' => 'coins', 'url' => $urls['expenses'] ?? '#'];
	}
	$groups[] = [
		'title' => $l->t('Personal mobility'),
		'items' => $mobilityItems,
	];
}
if ($isManager || $isFleetAdmin) {
	$groups[] = [
		'title' => $l->t('Operations'),
		'items' => [
			['id' => 'vehicles', 'label' => $l->t('Vehicles'), 'hint' => $l->t('Inventory and status lifecycle'), 'icon' => 'car', 'url' => $urls['vehicles'] ?? '#'],
			['id' => 'drivers', 'label' => $l->t('Drivers'), 'hint' => $l->t('Profiles and licence checks'), 'icon' => 'users', 'url' => $urls['drivers'] ?? '#'],
			['id' => 'maintenance', 'label' => $l->t('Maintenance'), 'hint' => $l->t('Schedules and blocking checks'), 'icon' => 'tool', 'url' => $urls['maintenance'] ?? '#'],
			['id' => 'costs', 'label' => $l->t('Costs'), 'hint' => $l->t('Fuel, repairs and VAT records'), 'icon' => 'coins', 'url' => $urls['costs'] ?? '#'],
			['id' => 'compliance', 'label' => $l->t('Compliance'), 'hint' => $l->t('Instruction and licence status'), 'icon' => 'shield-check', 'url' => $urls['compliance'] ?? '#'],
		],
	];
} elseif ($isLineManager && !$isManager && !$isFleetAdmin) {
	$groups[] = [
		'title' => $l->t('Operations'),
		'items' => [
			['id' => 'vehicles', 'label' => $l->t('Vehicles'), 'hint' => $l->t('Inventory and vehicle requirements (read-only).'), 'icon' => 'car', 'url' => $urls['vehicles'] ?? '#'],
			['id' => 'drivers', 'label' => $l->t('Drivers'), 'hint' => $l->t('Profiles and licence checks'), 'icon' => 'users', 'url' => $urls['drivers'] ?? '#'],
			['id' => 'costs', 'label' => $l->t('Costs'), 'hint' => $l->t('Fuel, repairs and VAT records'), 'icon' => 'coins', 'url' => $urls['costs'] ?? '#'],
			['id' => 'compliance', 'label' => $l->t('Compliance'), 'hint' => $l->t('Instruction and licence status'), 'icon' => 'shield-check', 'url' => $urls['compliance'] ?? '#'],
		],
	];
}
if ($isAuditor || $isManager || $isFleetAdmin || $isLineManager) {
	$auditItems = [
		['id' => 'reports', 'label' => $l->t('Reports'), 'hint' => $l->t('Read-only compliance exports'), 'icon' => 'file-analytics', 'url' => $urls['reports'] ?? '#'],
		['id' => 'exports', 'label' => $l->t('Exports'), 'hint' => $l->t('Generate CSV exports for payroll and tax'), 'icon' => 'download', 'url' => $urls['exports'] ?? '#'],
		['id' => 'tax-benefit', 'label' => $l->t('Tax benefit'), 'hint' => $l->t('1 %% rule monthly geldwerter Vorteil'), 'icon' => 'percent', 'url' => $urls['taxBenefit'] ?? '#'],
	];
	$groups[] = [
		'title' => $l->t('Audit'),
		'items' => $auditItems,
	];
}
if ($isFleetAdmin) {
	$groups[] = [
		'title' => $l->t('Governance'),
		'items' => [
			['id' => 'settings', 'label' => $l->t('Settings'), 'hint' => $l->t('Access, policy and defaults'), 'icon' => 'settings', 'url' => $urls['settings'] ?? '#'],
		],
	];
}
if ($isWorkshop && !$isDriver && !$isManager && !$isFleetAdmin && !$isAuditor) {
	$groups = [[
		'title' => $l->t('Workshop'),
		'items' => [
			['id' => 'damage', 'label' => $l->t('Damage'), 'hint' => $l->t('Assigned incidents and repairs'), 'icon' => 'tool', 'url' => $urls['damage'] ?? '#'],
		],
	]];
}
?>
<div id="app-navigation" class="mc-nav" role="navigation" aria-label="<?php p($l->t('MobilityCheck navigation')); ?>">
	<div class="mc-nav__brand">
		<span class="mc-nav__brand-icon" aria-hidden="true">
			<?php print_unescaped(IconCatalog::render('car', 'mc-icon mc-icon--lg')); ?>
		</span>
		<div>
			<h2 class="mc-nav__title"><?php p($l->t('MobilityCheck')); ?></h2>
			<p class="mc-nav__subtitle"><?php p($l->t('Fleet and carsharing')); ?></p>
			<span class="mc-badge"><?php p($roleLabel); ?></span>
		</div>
	</div>
	<?php foreach ($groups as $group): ?>
		<div class="mc-nav__group">
			<p class="mc-nav__group-title"><?php p((string)$group['title']); ?></p>
			<ul class="mc-nav__list">
				<?php foreach ((array)$group['items'] as $item): $active = $pageId === $item['id']; ?>
					<li class="mc-nav__item <?php p($active ? 'is-active active' : ''); ?>">
						<a class="mc-nav__link" href="<?php p((string)$item['url']); ?>" <?php if ($active): ?>aria-current="page"<?php endif; ?>>
							<span class="mc-nav__icon" aria-hidden="true">
								<?php print_unescaped(IconCatalog::render((string)$item['icon'], 'mc-icon')); ?>
							</span>
							<span class="mc-nav__label">
								<span class="mc-nav__name"><?php p((string)$item['label']); ?></span>
								<span class="mc-nav__hint"><?php p((string)$item['hint']); ?></span>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endforeach; ?>
</div>
