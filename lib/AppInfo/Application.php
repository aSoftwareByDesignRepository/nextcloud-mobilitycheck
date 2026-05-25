<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\AppInfo;

use OCA\MobilityCheck\BackgroundJob\BookingApprovalEscalationJob;
use OCA\MobilityCheck\BackgroundJob\BookingNoShowJob;
use OCA\MobilityCheck\BackgroundJob\BookingOverdueJob;
use OCA\MobilityCheck\Listener\UserDeletedListener;
use OCA\MobilityCheck\Middleware\AppAccessMiddleware;
use OCA\MobilityCheck\Notification\Notifier;
use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\ApprovalChainService;
use OCA\MobilityCheck\Service\AuditLogService;
use OCA\MobilityCheck\Service\BookingService;
use OCA\MobilityCheck\Service\ChargebackService;
use OCA\MobilityCheck\Service\ComplianceService;
use OCA\MobilityCheck\Service\CostService;
use OCA\MobilityCheck\Service\DamageService;
use OCA\MobilityCheck\Service\DriverService;
use OCA\MobilityCheck\Service\FileService;
use OCA\MobilityCheck\Service\GlossaryService;
use OCA\MobilityCheck\Service\IconCatalog;
use OCA\MobilityCheck\Service\LineManagerService;
use OCA\MobilityCheck\Service\LocaleFormatService;
use OCA\MobilityCheck\Service\MaintenanceService;
use OCA\MobilityCheck\Service\BookingIcalService;
use OCA\MobilityCheck\Service\NotificationService;
use OCA\MobilityCheck\Service\RelocationService;
use OCA\MobilityCheck\Service\ReportService;
use OCA\MobilityCheck\Service\SettingsService;
use OCA\MobilityCheck\Service\StationService;
use OCA\MobilityCheck\Service\TaxBenefitService;
use OCA\MobilityCheck\Service\CurrencyCatalog;
use OCA\MobilityCheck\Service\TimezoneCatalog;
use OCA\MobilityCheck\Service\VehicleService;
use OCA\MobilityCheck\Service\ExportService;
use OCA\MobilityCheck\Service\LogbookService;
use OCA\MobilityCheck\Service\OdometerReadingService;
use OCA\MobilityCheck\Service\PrivateVehicleService;
use OCA\MobilityCheck\Service\ReimbursementClaimService;
use OCA\MobilityCheck\Service\ReimbursementRateService;
use OCA\MobilityCheck\Service\SearchProfileService;
use OCA\MobilityCheck\Service\VehicleAssignmentService;
use OCA\MobilityCheck\Service\VehicleFeatureService;
use OCA\MobilityCheck\Service\VehicleSearchService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\User\Events\UserDeletedEvent;

/**
 * MobilityCheck DI container, middleware registration, navigation entry,
 * and event listener bindings.
 *
 * The container is wired explicitly (rather than relying on Nextcloud's
 * autowiring) so the service graph is auditable from one file.
 */
class Application extends App implements IBootstrap
{
	public const APP_ID = 'mobilitycheck';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		$context->registerService(AccessControlService::class, function ($c): AccessControlService {
			return new AccessControlService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(\OCP\IUserSession::class),
			);
		});
		$context->registerService(AppAccessMiddleware::class, function ($c): AppAccessMiddleware {
			return new AppAccessMiddleware(
				$c->get(\OCP\IUserSession::class),
				$c->get(AccessControlService::class),
				$c->get(\OCP\IRequest::class),
				$c->get(\OCP\IURLGenerator::class),
				$c->get(\OCP\L10N\IFactory::class),
			);
		});
		$context->registerService(LocaleFormatService::class, function ($c): LocaleFormatService {
			return new LocaleFormatService(
				$c->get(\OCP\L10N\IFactory::class),
				$c->get(\OCP\IDateTimeFormatter::class),
				$c->get(\OCP\IUserSession::class),
				$c->get(\OCP\IDateTimeZone::class),
				$c->get(SettingsService::class),
				$c->get(TimezoneCatalog::class),
			);
		});
		$context->registerService(TimezoneCatalog::class, fn () => new TimezoneCatalog());
		$context->registerService(CurrencyCatalog::class, fn () => new CurrencyCatalog());
		$context->registerService(IconCatalog::class, fn () => new IconCatalog());
		$context->registerService(AuditLogService::class, function ($c): AuditLogService {
			return new AuditLogService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IRequest::class),
			);
		});
		$context->registerService(SettingsService::class, function ($c): SettingsService {
			return new SettingsService(
				$c->get(\OCP\IConfig::class),
				$c->get(TimezoneCatalog::class),
				$c->get(CurrencyCatalog::class),
			);
		});
		$context->registerService(FileService::class, function ($c): FileService {
			return new FileService(
				$c->get(\OCP\Files\IRootFolder::class),
				$c->get(\OCP\IConfig::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(GlossaryService::class, function ($c): GlossaryService {
			return new GlossaryService($c->get(\OCP\L10N\IFactory::class));
		});
		$context->registerService(BookingIcalService::class, function ($c): BookingIcalService {
			return new BookingIcalService(
				$c->get(\OCP\IConfig::class),
				$c->get(\OCP\IURLGenerator::class),
			);
		});
		$context->registerService(NotificationService::class, function ($c): NotificationService {
			return new NotificationService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\Notification\IManager::class),
				$c->get(\OCP\Mail\IMailer::class),
				$c->get(\OCP\L10N\IFactory::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get(SettingsService::class),
				$c->get(BookingIcalService::class),
			);
		});
		$context->registerService(VehicleService::class, function ($c): VehicleService {
			return new VehicleService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(AuditLogService::class),
				$c->get(\OCP\Security\ISecureRandom::class),
			);
		});
		$context->registerService(VehicleAssignmentService::class, function ($c): VehicleAssignmentService {
			return new VehicleAssignmentService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(AuditLogService::class),
				$c->get(AccessControlService::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(VehicleService::class),
			);
		});
		$context->registerService(TaxBenefitService::class, function ($c): TaxBenefitService {
			return new TaxBenefitService(
				$c->get(AccessControlService::class),
				$c->get(VehicleService::class),
				$c->get(VehicleAssignmentService::class),
				$c->get(DriverService::class),
				$c->get(LineManagerService::class),
				$c->get(SettingsService::class),
			);
		});
		$context->registerService(LogbookService::class, function ($c): LogbookService {
			return new LogbookService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(SettingsService::class),
				$c->get(AuditLogService::class),
				$c->get(AccessControlService::class),
				$c->get(VehicleAssignmentService::class),
			);
		});
		$context->registerService(ReimbursementRateService::class, function ($c): ReimbursementRateService {
			return new ReimbursementRateService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(AccessControlService::class),
				$c->get(AuditLogService::class),
			);
		});
		$context->registerService(DriverService::class, function ($c): DriverService {
			return new DriverService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(AuditLogService::class),
				$c->get(\OCP\IUserManager::class),
			);
		});
		$context->registerService(ComplianceService::class, function ($c): ComplianceService {
			return new ComplianceService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(DriverService::class),
				$c->get(AuditLogService::class),
			);
		});
		$context->registerService(PrivateVehicleService::class, function ($c): PrivateVehicleService {
			return new PrivateVehicleService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(DriverService::class),
				$c->get(AccessControlService::class),
				$c->get(AuditLogService::class),
				$c->get(SettingsService::class),
			);
		});
		$context->registerService(ReimbursementClaimService::class, function ($c): ReimbursementClaimService {
			return new ReimbursementClaimService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(PrivateVehicleService::class),
				$c->get(ReimbursementRateService::class),
				$c->get(AccessControlService::class),
				$c->get(AuditLogService::class),
				$c->get(SettingsService::class),
			);
		});
		$context->registerService(OdometerReadingService::class, function ($c): OdometerReadingService {
			return new OdometerReadingService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(AccessControlService::class),
				$c->get(VehicleAssignmentService::class),
				$c->get(AuditLogService::class),
			);
		});
		$context->registerService(\OCA\MobilityCheck\Service\AllocationService::class, function ($c): \OCA\MobilityCheck\Service\AllocationService {
			return new \OCA\MobilityCheck\Service\AllocationService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(VehicleService::class),
				$c->get(VehicleAssignmentService::class),
				$c->get(SettingsService::class),
			);
		});
		$context->registerService(VehicleSearchService::class, function ($c): VehicleSearchService {
			return new VehicleSearchService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(VehicleService::class),
				$c->get(VehicleAssignmentService::class),
				$c->get(AccessControlService::class),
				$c->get(SettingsService::class),
				$c->get(DriverService::class),
				$c->get(\OCA\MobilityCheck\Service\AllocationService::class),
			);
		});
		$context->registerService(SearchProfileService::class, function ($c): SearchProfileService {
			return new SearchProfileService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(AccessControlService::class),
			);
		});
		$context->registerService(VehicleFeatureService::class, function ($c): VehicleFeatureService {
			return new VehicleFeatureService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(AccessControlService::class),
				$c->get(AuditLogService::class),
			);
		});
		$context->registerService(ExportService::class, function ($c): ExportService {
			return new ExportService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(AccessControlService::class),
				$c->get(LogbookService::class),
				$c->get(\OCP\Security\ISecureRandom::class),
				$c->get(AuditLogService::class),
				$c->get(SettingsService::class),
			);
		});
		$context->registerService(LineManagerService::class, function ($c): LineManagerService {
			return new LineManagerService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(AccessControlService::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(AuditLogService::class),
			);
		});
		$context->registerService(ApprovalChainService::class, function ($c): ApprovalChainService {
			return new ApprovalChainService(
				$c->get(SettingsService::class),
				$c->get(LineManagerService::class),
				$c->get(AccessControlService::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(\OCP\IDBConnection::class),
			);
		});
		$context->registerService(StationService::class, function ($c): StationService {
			return new StationService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(AccessControlService::class),
				$c->get(AuditLogService::class),
				$c->get(TimezoneCatalog::class),
			);
		});
		$context->registerService(RelocationService::class, function ($c): RelocationService {
			return new RelocationService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(AccessControlService::class),
				$c->get(AuditLogService::class),
				$c->get(VehicleService::class),
			);
		});
		$context->registerService(BookingService::class, function ($c): BookingService {
			return new BookingService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(VehicleService::class),
				$c->get(ComplianceService::class),
				$c->get(SettingsService::class),
				$c->get(AccessControlService::class),
				$c->get(AuditLogService::class),
				$c->get(VehicleAssignmentService::class),
				$c->get(LogbookService::class),
				$c->get(LineManagerService::class),
				$c->get(ApprovalChainService::class),
				$c->get(DriverService::class),
				$c->get(RelocationService::class),
				$c->get(ChargebackService::class),
				$c->get(\OCA\MobilityCheck\Service\AllocationService::class),
			);
		});
		$context->registerService(DamageService::class, function ($c): DamageService {
			return new DamageService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(VehicleService::class),
				$c->get(AccessControlService::class),
				$c->get(AuditLogService::class),
				$c->get(LineManagerService::class),
				$c->get(BookingService::class),
			);
		});
		$context->registerService(\OCA\MobilityCheck\Service\RepairService::class, function ($c): \OCA\MobilityCheck\Service\RepairService {
			return new \OCA\MobilityCheck\Service\RepairService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(DamageService::class),
				$c->get(AccessControlService::class),
				$c->get(AuditLogService::class),
				$c->get(\OCP\IUserManager::class),
			);
		});
		$context->registerService(CostService::class, function ($c): CostService {
			return new CostService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(VehicleService::class),
				$c->get(AccessControlService::class),
				$c->get(AuditLogService::class),
			);
		});
		$context->registerService(ChargebackService::class, function ($c): ChargebackService {
			return new ChargebackService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(AccessControlService::class),
				$c->get(AuditLogService::class),
				$c->get(SettingsService::class),
			);
		});
		$context->registerService(MaintenanceService::class, function ($c): MaintenanceService {
			return new MaintenanceService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(VehicleService::class),
				$c->get(AccessControlService::class),
				$c->get(AuditLogService::class),
			);
		});
		$context->registerService(ReportService::class, function ($c): ReportService {
			return new ReportService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(CostService::class),
				$c->get(DriverService::class),
				$c->get(LineManagerService::class),
			);
		});

		$context->registerService(BookingOverdueJob::class, function ($c): BookingOverdueJob {
			return new BookingOverdueJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(\OCP\IDBConnection::class),
				$c->get(NotificationService::class),
				$c->get(AccessControlService::class),
				$c->get(SettingsService::class),
				$c->get(AuditLogService::class),
			);
		});
		$context->registerService(BookingNoShowJob::class, function ($c): BookingNoShowJob {
			return new BookingNoShowJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(\OCP\IDBConnection::class),
				$c->get(BookingService::class),
				$c->get(NotificationService::class),
				$c->get(AccessControlService::class),
				$c->get(SettingsService::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(LineManagerService::class),
			);
		});
		$context->registerService(BookingApprovalEscalationJob::class, function ($c): BookingApprovalEscalationJob {
			return new BookingApprovalEscalationJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(\OCP\IDBConnection::class),
				$c->get(NotificationService::class),
				$c->get(AccessControlService::class),
				$c->get(SettingsService::class),
				$c->get(LineManagerService::class),
				$c->get(\OCP\IUserManager::class),
			);
		});
		$context->registerService(\OCA\MobilityCheck\BackgroundJob\FleetReassignmentJob::class, function ($c): \OCA\MobilityCheck\BackgroundJob\FleetReassignmentJob {
			return new \OCA\MobilityCheck\BackgroundJob\FleetReassignmentJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(\OCA\MobilityCheck\Service\ReassignmentService::class),
				$c->get(SettingsService::class),
			);
		});

		$context->registerService(\OCA\MobilityCheck\Service\GdprErasureService::class, function ($c): \OCA\MobilityCheck\Service\GdprErasureService {
			return new \OCA\MobilityCheck\Service\GdprErasureService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(AccessControlService::class),
				$c->get(AuditLogService::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get(\OCP\IUserManager::class),
			);
		});
		$context->registerService(\OCA\MobilityCheck\Service\RetentionService::class, function ($c): \OCA\MobilityCheck\Service\RetentionService {
			return new \OCA\MobilityCheck\Service\RetentionService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(AccessControlService::class),
				$c->get(AuditLogService::class),
				$c->get(SettingsService::class),
			);
		});
		$context->registerService(\OCA\MobilityCheck\Service\ReassignmentService::class, function ($c): \OCA\MobilityCheck\Service\ReassignmentService {
			return new \OCA\MobilityCheck\Service\ReassignmentService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(SettingsService::class),
				$c->get(VehicleSearchService::class),
				$c->get(\OCA\MobilityCheck\Service\AllocationService::class),
				$c->get(BookingService::class),
				$c->get(NotificationService::class),
				$c->get(AuditLogService::class),
				$c->get(VehicleAssignmentService::class),
				$c->get(VehicleService::class),
				$c->get(AccessControlService::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerNotifierService(Notifier::class);
		$context->registerMiddleware(AppAccessMiddleware::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
	}

	public function boot(IBootContext $context): void
	{
		try {
			$c = $this->getContainer();
			$this->seedDefaultsOnce($c);
			$session = $c->get(IUserSession::class);
			$user = $session->getUser();
			if ($user === null) {
				return;
			}
			$access = $c->get(AccessControlService::class);
			if (!$access->canUseApp($user->getUID())) {
				return;
			}
			$c->get(INavigationManager::class)->add(function () use ($c): array {
				return [
					'id' => self::APP_ID,
					'app' => self::APP_ID,
					'order' => 14,
					'href' => $c->get(IURLGenerator::class)->linkToRoute('mobilitycheck.page.index'),
					'icon' => $c->get(IURLGenerator::class)->imagePath(self::APP_ID, 'app.svg'),
					'name' => $c->get(IFactory::class)->get(self::APP_ID)->t('MobilityCheck'),
				];
			});
		} catch (\Throwable) {
			// Navigation is best-effort; never fatal.
		}
	}

	/**
	 * Idempotently seed the default cost categories (§4.8). The migration
	 * also seeds them in `postSchemaChange`, but Nextcloud's enable flow
	 * has skipped that hook in observed installs — so we keep a safety
	 * net here gated by a one-shot app config flag.
	 */
	private function seedDefaultsOnce($c): void
	{
		try {
			$config = $c->get(\OCP\IConfig::class);
			if ($config->getAppValue(self::APP_ID, 'defaults_seeded', '0') === '1') {
				return;
			}
			$db = $c->get(\OCP\IDBConnection::class);
			$count = (int) ($db->getQueryBuilder()
				->select($db->getQueryBuilder()->func()->count('*'))
				->from('mc_cost_categories')
				->executeQuery()
				->fetchOne() ?: 0);
			if ($count === 0) {
				foreach (['fuel', 'repair', 'insurance', 'road_tax', 'fine', 'parking', 'maintenance', 'cleaning', 'other'] as $name) {
					$ins = $db->getQueryBuilder();
					$ins->insert('mc_cost_categories')->values([
						'name' => $ins->createNamedParameter($name),
						'is_active' => $ins->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
					]);
					$ins->executeStatement();
				}
			}
			$config->setAppValue(self::APP_ID, 'defaults_seeded', '1');
		} catch (\Throwable) {
			// First request after install may race the migration; retry next request.
		}
	}
}
