<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCA\MobilityCheck\AppInfo\Application;
use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\BookingService;
use OCA\MobilityCheck\Service\DriverService;
use OCA\MobilityCheck\Service\GlossaryService;
use OCA\MobilityCheck\Service\LocaleFormatService;
use OCA\MobilityCheck\Service\LineManagerService;
use OCA\MobilityCheck\Service\SettingsService;
use OCA\MobilityCheck\Service\VehicleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Page controller for MobilityCheck. Every public method is a navigable page.
 *
 * CSRF tokens are not required on GET page requests (the JSON layer requires
 * them on every write), but the session cookie is mandatory and the
 * {@see \OCA\MobilityCheck\Middleware\AppAccessMiddleware} enforces the §2.4
 * app entry gate before any controller method body runs. Each method then
 * applies a §2.2 / §2.3 role check via {@see AccessControlService} so a
 * driver never receives a fleet-admin-only template even if they hand-craft
 * the URL.
 */
class PageController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private LocaleFormatService $localeFormat,
		private GlossaryService $glossary,
		private SettingsService $settings,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private BookingService $bookings,
		private VehicleService $vehicles,
		private DriverService $drivers,
		private LineManagerService $lineManagers,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Reject workshop-only users from the full app shell. Workshop accounts
	 * see a deliberately reduced damage / repair queue under {@see damage()}
	 * via the navigation; any other URL must throw 403.
	 */
	private function requireFullShellAccess(string $userId): void
	{
		$this->access->requireAnyAppRole($userId);
		$this->access->requireNotWorkshopOnly($userId);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): RedirectResponse
	{
		$userId = $this->access->currentUserId();
		$route = $this->access->isWorkshopOnly($userId)
			? 'mobilitycheck.page.damage'
			: 'mobilitycheck.page.dashboard';
		return new RedirectResponse($this->urlGenerator->linkToRoute($route));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse
	{
		$this->access->requireAnyAppRole($this->access->currentUserId());
		return $this->page(
			'dashboard',
			'dashboard',
			$this->l10n->t('Your fleet at a glance — what is happening today and what needs your attention.')
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function vehicles(): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireAnyAppRole($userId);
		$this->access->requireNotWorkshopOnly($userId);
		return $this->page(
			'vehicles',
			'vehicles',
			$this->l10n->t('Manage every car, van and truck in the fleet — model, status and requirements.')
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function vehicleDetail(int $id): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireAnyAppRole($userId);
		$this->access->requireNotWorkshopOnly($userId);
		return $this->page(
			'vehicle-detail',
			'vehicle-detail',
			$this->l10n->t('Key facts, who may use the vehicle, upcoming reservations and damage — with shortcuts to filtered lists.'),
			['vehicleId' => $id]
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function drivers(): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireNotWorkshopOnly($userId);
		$this->access->requireFleetOperationsRead($userId);
		return $this->page(
			'drivers',
			'drivers',
			$this->l10n->t('Driver records, licence verification, and annual instruction.')
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function driverDetail(int $id): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireNotWorkshopOnly($userId);
		$this->access->requireFleetOperationsRead($userId);
		$this->assertMayViewDriverProfilePage($id, $userId);
		return $this->page(
			'driver-detail',
			'driver-detail',
			$this->l10n->t('Licence, instruction, and compliance history for one driver.'),
			['driverProfileId' => $id]
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bookings(): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireAnyAppRole($userId);
		$this->access->requireNotWorkshopOnly($userId);
		return $this->page(
			'bookings',
			'bookings',
			$this->l10n->t('Browse, approve and manage vehicle bookings.')
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reassignmentSuggestions(): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireAnyAppRole($userId);
		$this->access->requireNotWorkshopOnly($userId);
		if (!$this->access->isFleetAdminOrManager($userId) && !$this->access->isAppAdmin($userId)) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
		return $this->page(
			'reassignment-suggestions',
			'reassignment-suggestions',
			$this->l10n->t('When a vehicle becomes unavailable, MobilityCheck can suggest or apply another eligible vehicle. Accept a suggestion here after reviewing the booking window and driver.')
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bookingNew(): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireAnyAppRole($userId);
		$this->access->requireNotWorkshopOnly($userId);
		if (!$this->access->isFleetAdminOrManagerOrAppAdmin($userId)) {
			$this->access->requireDriver($userId);
		}
		return $this->page(
			'booking-new',
			'booking-new',
			$this->l10n->t('Reserve a vehicle for a time window; conflicts and eligibility are checked on submit. Approvals follow your organisation settings — then you check out and check in on the booking page.')
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bookingDetail(int $id): TemplateResponse|NotFoundResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireAnyAppRole($userId);
		$this->access->requireNotWorkshopOnly($userId);
		try {
			$booking = $this->bookings->get($id);
		} catch (NotFoundException) {
			return new NotFoundResponse();
		}
		$this->bookings->assertUserMayViewBooking($booking, $userId);
		$this->bookings->clearProxyUnacknowledgedIfApplicable($id, $userId, true);
		$pickupHint = $this->buildPickupHintForBooking($booking);
		return $this->page(
			'booking-detail',
			'booking-detail',
			$this->l10n->t('Follow the steps from reservation to return. Check-out and check-in are recorded with odometer readings; pool and group vehicles require a clear note where the car was left for the next driver.'),
			['bookingId' => $id, 'pickupHint' => $pickupHint]
		);
	}

	/**
	 * §4.5 step 7 / §13.28 — Server-side pickup hint payload for pool/group bookings
	 * so the card is populated without JavaScript.
	 *
	 * @param array<string,mixed> $booking
	 * @return array{show:bool,baseLocation:?string,lastNote:?string,lastRecordedAtFormatted:?string,historyCount:int,hasPriorCheckin:bool}
	 */
	private function buildPickupHintForBooking(array $booking): array
	{
		$mode = (string)($booking['vehicle_assignment_mode'] ?? '');
		$status = (string)($booking['status'] ?? '');
		$isShared = $mode === 'pool' || $mode === 'group';
		// §4.5 step 7 — card is for the handover *before* check-out only (`approved`).
		$isPickupRelevant = $status === BookingService::STATUS_APPROVED;
		if (!$isShared || !$isPickupRelevant) {
			return ['show' => false, 'baseLocation' => null, 'lastNote' => null, 'lastRecordedAtFormatted' => null, 'historyCount' => 0, 'hasPriorCheckin' => false];
		}
		$vehicleId = (int)($booking['vehicle_id'] ?? 0);
		if ($vehicleId <= 0) {
			return ['show' => false, 'baseLocation' => null, 'lastNote' => null, 'lastRecordedAtFormatted' => null, 'historyCount' => 0, 'hasPriorCheckin' => false];
		}
		$info = $this->vehicles->lastReturnInfo($vehicleId, 5);
		$hasPriorCheckin = (bool)($info['hasPriorCheckin'] ?? false);
		$base = isset($booking['vehicle_base_location']) && $booking['vehicle_base_location'] !== null && $booking['vehicle_base_location'] !== ''
			? (string)$booking['vehicle_base_location']
			: null;
		$last = $info['lastReturn'] ?? null;
		$lastNote = null;
		$lastAt = null;
		if (is_array($last) && isset($last['note']) && trim((string)$last['note']) !== '') {
			$lastNote = (string)$last['note'];
			$recAt = (string)($last['recordedAt'] ?? '');
			if ($recAt !== '') {
				$lastAt = $this->localeFormat->formatUtcSqlDatetimeForUser($recAt);
			}
		}
		$history = is_array($info['history'] ?? null) ? $info['history'] : [];
		return [
			'show' => true,
			'baseLocation' => $base,
			'lastNote' => $lastNote,
			'lastRecordedAtFormatted' => $lastAt,
			'historyCount' => count($history),
			'hasPriorCheckin' => $hasPriorCheckin,
		];
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function damage(): TemplateResponse
	{
		$this->access->requireAnyAppRole($this->access->currentUserId());
		return $this->page(
			'damage',
			'damage',
			$this->l10n->t('Report and track vehicle damage — from discovery to repair.')
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function damageDetail(int $id): TemplateResponse
	{
		$this->access->requireAnyAppRole($this->access->currentUserId());
		return $this->page(
			'damage-detail',
			'damage-detail',
			$this->l10n->t('One damage report with photos, status history, and linked repair.'),
			['damageReportId' => $id]
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function costs(): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireNotWorkshopOnly($userId);
		$this->access->requireFleetOperationsRead($userId);
		return $this->page(
			'costs',
			'costs',
			$this->l10n->t('Record fuel, repairs, insurance and other vehicle costs. VAT and net values are calculated automatically.')
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function maintenance(): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireAnyAppRole($userId);
		$this->access->requireNotWorkshopOnly($userId);
		$this->access->requireFleetAdminOrManager($userId);
		return $this->page(
			'maintenance',
			'maintenance',
			$this->l10n->t('Plan recurring maintenance and inspections per vehicle.')
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function compliance(): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireNotWorkshopOnly($userId);
		$this->access->requireFleetOperationsRead($userId);
		return $this->page(
			'compliance',
			'compliance',
			$this->l10n->t('Annual instruction and licence status across all drivers.')
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reports(): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireNotWorkshopOnly($userId);
		$this->access->requireAuditorOrManagerOrAdmin($userId);
		return $this->page(
			'reports',
			'reports',
			$this->l10n->t('Read-only reports for management, audits and tax purposes.')
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): TemplateResponse
	{
		$this->access->requireFleetAdmin($this->access->currentUserId());
		return $this->page(
			'settings',
			'settings',
			$this->l10n->t('App configuration: access policy, defaults, role assignment and audit log.'),
			[],
			true
		);
	}

	// ── Appendix A pages ─────────────────────────────────────────────────

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function logbook(): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->requireFullShellAccess($userId);
		if (!$this->settings->logbookEnabled()) {
			$this->access->requireFleetAdmin($userId);
		}
		return $this->page(
			'logbook',
			'logbook',
			$this->l10n->t('Every business, commute and private trip is recorded here. Confirmed entries become legally binding (§ 6 EStG, GoBD) and can only be corrected by amendment.')
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function logbookNew(): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->requireFullShellAccess($userId);
		if (!$this->settings->logbookEnabled()) {
			$this->access->requireFleetAdmin($userId);
		}
		return $this->page(
			'logbook-new',
			'logbook-new',
			$this->l10n->t('Add a logbook entry for a dedicated company car. Pool and group trips create a draft automatically after check-in.')
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function logbookDetail(int $id): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->requireFullShellAccess($userId);
		if (!$this->settings->logbookEnabled()) {
			$this->access->requireFleetAdmin($userId);
		}
		return $this->page(
			'logbook-detail',
			'logbook-detail',
			$this->l10n->t('One Fahrtenbuch entry. Drafts can still be edited; confirmed entries are immutable and only changeable through an amendment with a documented reason.'),
			['logbookEntryId' => $id]
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function expenses(): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->requireFullShellAccess($userId);
		if (!$this->settings->reimbursementEnabled()) {
			$this->access->requireFleetAdmin($userId);
		}
		return $this->page(
			'expenses',
			'expenses',
			$this->l10n->t('Submit and track reimbursement claims for trips you made in your own private vehicle. Claims require manager approval before payment.')
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function expensesNew(): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireDriver($userId);
		if (!$this->settings->reimbursementEnabled()) {
			$this->access->requireFleetAdmin($userId);
		}
		return $this->page(
			'expenses-new',
			'expenses-new',
			$this->l10n->t('Submit a new reimbursement claim for a business trip you made in your private vehicle. The statutory rate per kilometre is applied automatically.')
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exports(): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireNotWorkshopOnly($userId);
		$this->access->requireAuditorOrManagerOrAdmin($userId);
		return $this->page(
			'exports',
			'exports',
			$this->l10n->t('Generate Fahrtenbuch and reimbursement exports for payroll, the tax adviser or the Finanzamt. Files are kept in your Nextcloud “MobilityCheck/Exports/” folder.')
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function taxBenefit(): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireNotWorkshopOnly($userId);
		$this->access->requireAuditorOrManagerOrAdmin($userId);
		return $this->page(
			'tax-benefit',
			'tax-benefit',
			$this->l10n->t('Monthly taxable benefit (geldwerter Vorteil) for company cars taxed under the 1 %% rule, including the 0.03 %% commute surcharge per § 8 Abs. 2 EStG.')
		);
	}

	/**
	 * Renders the MobilityCheck app shell and the named template.
	 *
	 * Side effects: registers the shared CSS/JS bundles required by every page.
	 *
	 * @param array<string,mixed> $extra Additional template variables.
	 */
	private function page(string $template, string $script, string $help, array $extra = [], bool $withCatalogPickers = false): TemplateResponse
	{
		$userId = $this->access->currentUserId();

		Util::addStyle(Application::APP_ID, 'common/tokens');
		Util::addStyle(Application::APP_ID, 'app');
		Util::addScript(Application::APP_ID, 'common/api');
		Util::addScript(Application::APP_ID, 'common/dates');
		Util::addScript(Application::APP_ID, 'common/money');
		Util::addScript(Application::APP_ID, 'common/messaging');
		Util::addScript(Application::APP_ID, 'common/components');
		Util::addScript(Application::APP_ID, 'common/glossary');
		Util::addScript(Application::APP_ID, 'common/user-picker');
		if ($withCatalogPickers) {
			Util::addScript(Application::APP_ID, 'common/catalog-pickers');
		}
		Util::addScript(Application::APP_ID, $script);

		$roles = $this->access->getRoles($userId);
		$isAppAdmin = $this->access->isAppAdmin($userId);
		$isFleetAdmin = $this->access->isFleetAdmin($userId);
		$isManager = $this->access->isFleetAdminOrManager($userId);
		$isDriver = $this->access->isDriver($userId);
		$isAuditor = $this->access->isAuditor($userId);
		$isWorkshop = in_array(AccessControlService::ROLE_WORKSHOP, $roles, true);
		$isWorkshopOnly = $this->access->isWorkshopOnly($userId);
		$isLineManager = $this->access->isLineManager($userId);
		$lineManagerScopedReader = $isLineManager && !$isManager && !$isAuditor;

		$payload = [
			'pageId' => $template,
			'pageTitle' => $this->titleForPage($template),
			'pageHelp' => $help,
			'currentUserId' => $userId,
			'roles' => $roles,
			'roleLabel' => $this->humanRoleLabel($isAppAdmin, $isFleetAdmin, $isManager, $isDriver, $isAuditor, $isWorkshop, $isLineManager),
			'isAppAdmin' => $isAppAdmin,
			'isFleetAdmin' => $isFleetAdmin,
			'isManager' => $isManager,
			'isLineManager' => $isLineManager,
			'lineManagerScopedReader' => $lineManagerScopedReader,
			'isDriver' => $isDriver,
			'isAuditor' => $isAuditor,
			'isWorkshop' => $isWorkshop,
			'isWorkshopOnly' => $isWorkshopOnly,
			'clientHints' => $this->localeFormat->clientHints(),
			'glossary' => $this->glossary->all(),
			'currency' => $this->settings->currency(),
			'currencyDecimals' => $this->settings->currencyDecimals(),
			'defaultTimezone' => $this->settings->defaultTimezone(),
			'urls' => $this->buildUrls(),
		];
		foreach ($extra as $k => $v) {
			$payload[$k] = $v;
		}
		return new TemplateResponse(Application::APP_ID, $template, $payload);
	}

	private function assertMayViewDriverProfilePage(int $driverProfileId, string $viewerId): void
	{
		$driver = $this->drivers->get($driverProfileId);
		$target = (string)($driver['user_id'] ?? '');
		if ($target === $viewerId) {
			return;
		}
		if ($this->access->isFleetAdminOrManager($viewerId) || $this->access->isAuditor($viewerId)) {
			return;
		}
		if ($this->access->isLineManager($viewerId) && $this->lineManagers->isActiveLineManagerForDriver($viewerId, $target)) {
			return;
		}
		throw new ForbiddenException('INSUFFICIENT_ROLE');
	}

	/** @return array<string,string> */
	private function buildUrls(): array
	{
		return [
			'home' => $this->urlGenerator->linkToDefaultPageUrl(),
			'dashboard' => $this->urlGenerator->linkToRoute('mobilitycheck.page.dashboard'),
			'vehicles' => $this->urlGenerator->linkToRoute('mobilitycheck.page.vehicles'),
			'drivers' => $this->urlGenerator->linkToRoute('mobilitycheck.page.drivers'),
			'bookings' => $this->urlGenerator->linkToRoute('mobilitycheck.page.bookings'),
			'reassignmentSuggestions' => $this->urlGenerator->linkToRoute('mobilitycheck.page.reassignmentSuggestions'),
			'bookingNew' => $this->urlGenerator->linkToRoute('mobilitycheck.page.bookingNew'),
			'damage' => $this->urlGenerator->linkToRoute('mobilitycheck.page.damage'),
			'costs' => $this->urlGenerator->linkToRoute('mobilitycheck.page.costs'),
			'maintenance' => $this->urlGenerator->linkToRoute('mobilitycheck.page.maintenance'),
			'compliance' => $this->urlGenerator->linkToRoute('mobilitycheck.page.compliance'),
			'reports' => $this->urlGenerator->linkToRoute('mobilitycheck.page.reports'),
			'settings' => $this->urlGenerator->linkToRoute('mobilitycheck.page.settings'),
			'logbook' => $this->urlGenerator->linkToRoute('mobilitycheck.page.logbook'),
			'logbookNew' => $this->urlGenerator->linkToRoute('mobilitycheck.page.logbookNew'),
			'expenses' => $this->urlGenerator->linkToRoute('mobilitycheck.page.expenses'),
			'expensesNew' => $this->urlGenerator->linkToRoute('mobilitycheck.page.expensesNew'),
			'exports' => $this->urlGenerator->linkToRoute('mobilitycheck.page.exports'),
			'taxBenefit' => $this->urlGenerator->linkToRoute('mobilitycheck.page.taxBenefit'),
		];
	}

	private function titleForPage(string $pageId): string
	{
		return match ($pageId) {
			'dashboard' => $this->l10n->t('Dashboard'),
			'vehicles' => $this->l10n->t('Vehicles'),
			'vehicle-detail' => $this->l10n->t('Vehicle'),
			'drivers' => $this->l10n->t('Drivers'),
			'driver-detail' => $this->l10n->t('Driver'),
			'bookings' => $this->l10n->t('Bookings'),
			'reassignment-suggestions' => $this->l10n->t('Reassignment suggestions'),
			'booking-new' => $this->l10n->t('New booking'),
			'booking-detail' => $this->l10n->t('Booking'),
			'damage' => $this->l10n->t('Damage'),
			'damage-detail' => $this->l10n->t('Damage report'),
			'costs' => $this->l10n->t('Costs'),
			'maintenance' => $this->l10n->t('Maintenance'),
			'compliance' => $this->l10n->t('Compliance'),
			'reports' => $this->l10n->t('Reports'),
			'settings' => $this->l10n->t('Settings'),
			'logbook' => $this->l10n->t('Logbook'),
			'logbook-new' => $this->l10n->t('New logbook entry'),
			'logbook-detail' => $this->l10n->t('Logbook entry'),
			'expenses' => $this->l10n->t('Expenses'),
			'expenses-new' => $this->l10n->t('New reimbursement claim'),
			'exports' => $this->l10n->t('Exports'),
			'tax-benefit' => $this->l10n->t('Tax benefit'),
			default => $this->l10n->t('MobilityCheck'),
		};
	}

	private function humanRoleLabel(
		bool $isAppAdmin,
		bool $isFleetAdmin,
		bool $isManager,
		bool $isDriver,
		bool $isAuditor,
		bool $isWorkshop,
		bool $isLineManager,
	): string {
		if ($isAppAdmin && $isFleetAdmin) {
			return $this->l10n->t('Administrator');
		}
		if ($isFleetAdmin) {
			return $this->l10n->t('Fleet administrator');
		}
		if ($isManager && $isDriver) {
			return $this->l10n->t('Fleet manager & driver');
		}
		if ($isManager) {
			return $this->l10n->t('Fleet manager');
		}
		if ($isLineManager && $isDriver) {
			return $this->l10n->t('Line manager & driver');
		}
		if ($isLineManager) {
			return $this->l10n->t('Line manager');
		}
		if ($isAuditor && $isDriver) {
			return $this->l10n->t('Auditor & driver');
		}
		if ($isAuditor) {
			return $this->l10n->t('Auditor');
		}
		if ($isWorkshop && $isDriver) {
			return $this->l10n->t('Workshop & driver');
		}
		if ($isWorkshop) {
			return $this->l10n->t('Workshop');
		}
		if ($isDriver) {
			return $this->l10n->t('Driver');
		}
		return $this->l10n->t('MobilityCheck user');
	}
}
