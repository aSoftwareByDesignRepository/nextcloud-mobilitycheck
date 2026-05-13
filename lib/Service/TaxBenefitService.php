<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Exception\ValidationException;

/**
 * §A8.4 / §A9 — Monthly geldwerter Vorteil under the 1 %-Regelung (§ 8 Abs. 2 EStG).
 *
 * All amounts are integer minor units. The gross list price stored on
 * {@see mc_vehicle_assignments.monthly_gross_list_price_minor} is the vehicle
 * Bruttolistenpreis (the column name is historical in the schema).
 */
final class TaxBenefitService
{
	public function __construct(
		private AccessControlService $access,
		private VehicleService $vehicles,
		private VehicleAssignmentService $assignments,
		private DriverService $drivers,
		private LineManagerService $lineManagers,
		private SettingsService $settings,
	) {
	}

	/**
	 * @return array{
	 *   vehicleId:int,
	 *   yearMonth:string,
	 *   currency:string,
	 *   assignment:?array<string,mixed>,
	 *   taxTreatment:string,
	 *   listPriceMinor:?int,
	 *   commuteKmOneWay:int,
	 *   onePercentMinor:int,
	 *   commuteSurchargeMinor:int,
	 *   totalMonthlyBenefitMinor:int,
	 *   listPriceMissing:bool,
	 *   appliesOnePercentRule:bool
	 * }
	 */
	public function monthlyPreview(int $vehicleId, string $yearMonth, string $viewerId): array
	{
		if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
			throw new ValidationException('YEAR_MONTH_INVALID', 'yearMonth');
		}
		$this->vehicles->get($vehicleId);
		$anchor = $yearMonth . '-01';
		$assignment = $this->assignments->getAssignmentCoveringDate($vehicleId, $anchor);
		$this->assertMayAccessTaxPreview($viewerId, $assignment);

		if ($assignment === null) {
			return [
				'vehicleId' => $vehicleId,
				'yearMonth' => $yearMonth,
				'currency' => $this->settings->currency(),
				'assignment' => null,
				'taxTreatment' => '',
				'listPriceMinor' => null,
				'commuteKmOneWay' => 0,
				'onePercentMinor' => 0,
				'commuteSurchargeMinor' => 0,
				'totalMonthlyBenefitMinor' => 0,
				'listPriceMissing' => false,
				'appliesOnePercentRule' => false,
			];
		}

		$tax = (string)($assignment['tax_treatment'] ?? VehicleAssignmentService::TAX_BUSINESS_ONLY);
		$listMinor = isset($assignment['monthly_gross_list_price_minor']) && $assignment['monthly_gross_list_price_minor'] !== null
			? (int)$assignment['monthly_gross_list_price_minor'] : null;
		$applies = $tax === VehicleAssignmentService::TAX_ONE_PERCENT;
		$commuteKm = 0;
		if ($applies) {
			$uid = (string)($assignment['assigned_user_id'] ?? '');
			if ($uid !== '') {
				$prof = $this->drivers->getByUserId($uid);
				if ($prof !== null && isset($prof['commute_distance_km']) && $prof['commute_distance_km'] !== null) {
					$commuteKm = max(0, (int)$prof['commute_distance_km']);
				}
			}
		}

		$listMissing = $applies && ($listMinor === null || $listMinor <= 0);
		$onePct = 0;
		$surcharge = 0;
		if ($applies && !$listMissing && $listMinor !== null) {
			$parts = self::computeOnePercentComponents($listMinor, $commuteKm);
			$onePct = $parts['onePercentMinor'];
			$surcharge = $parts['commuteSurchargeMinor'];
		}
		$total = $onePct + $surcharge;

		return [
			'vehicleId' => $vehicleId,
			'yearMonth' => $yearMonth,
			'currency' => $this->settings->currency(),
			'assignment' => $assignment,
			'taxTreatment' => $tax,
			'listPriceMinor' => $listMinor,
			'commuteKmOneWay' => $commuteKm,
			'onePercentMinor' => $onePct,
			'commuteSurchargeMinor' => $surcharge,
			'totalMonthlyBenefitMinor' => $total,
			'listPriceMissing' => $listMissing,
			'appliesOnePercentRule' => $applies,
		];
	}

	/**
	 * One UTF-8 CSV row (+ header) for payroll spreadsheet import.
	 *
	 * @return array{body:string, filename:string}
	 */
	public function payrollExportCsv(int $vehicleId, string $yearMonth, string $viewerId): array
	{
		$p = $this->monthlyPreview($vehicleId, $yearMonth, $viewerId);
		$sep = ';';
		$header = [
			'vehicle_id',
			'year_month',
			'currency',
			'list_price_minor',
			'commute_km_one_way',
			'one_percent_minor',
			'commute_surcharge_003pct_minor',
			'total_monthly_benefit_minor',
			'list_price_missing_flag',
		];
		$row = [
			(string)$p['vehicleId'],
			$p['yearMonth'],
			$p['currency'],
			(string)($p['listPriceMinor'] ?? ''),
			(string)$p['commuteKmOneWay'],
			(string)$p['onePercentMinor'],
			(string)$p['commuteSurchargeMinor'],
			(string)$p['totalMonthlyBenefitMinor'],
			$p['listPriceMissing'] ? '1' : '0',
		];
		$body = "\u{FEFF}" . implode($sep, $header) . "\n" . implode($sep, $row) . "\n";
		return [
			'body' => $body,
			'filename' => 'mobilitycheck-tax-benefit-' . $yearMonth . '-vehicle-' . $vehicleId . '.csv',
		];
	}

	/** @param ?array<string,mixed> $assignment */
	private function assertMayAccessTaxPreview(string $viewerId, ?array $assignment): void
	{
		if ($this->access->isFleetAdminOrManager($viewerId) || $this->access->isAuditor($viewerId)) {
			return;
		}
		if (!$this->access->isLineManager($viewerId)) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
		if ($assignment === null) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
		if (($assignment['assignment_mode'] ?? '') !== VehicleAssignmentService::MODE_DEDICATED) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
		$driverUid = (string)($assignment['assigned_user_id'] ?? '');
		if ($driverUid === '' || !$this->lineManagers->isActiveLineManagerForDriver($viewerId, $driverUid)) {
			throw new ForbiddenException('INSUFFICIENT_ROLE');
		}
	}

	/**
	 * § 8 Abs. 2 EStG — 1 % of gross list price per month plus 0.03 % of list price × one-way commute km.
	 *
	 * @return array{onePercentMinor:int, commuteSurchargeMinor:int, totalMinor:int}
	 */
	public static function computeOnePercentComponents(int $listPriceMinor, int $commuteKmOneWay): array
	{
		if ($listPriceMinor <= 0 || $commuteKmOneWay < 0) {
			return ['onePercentMinor' => 0, 'commuteSurchargeMinor' => 0, 'totalMinor' => 0];
		}
		$one = intdiv($listPriceMinor, 100);
		$sur = $commuteKmOneWay === 0
			? 0
			: MobilityCheckMoney::roundHalfUp($listPriceMinor * 3 * $commuteKmOneWay / 10000.0);
		return ['onePercentMinor' => $one, 'commuteSurchargeMinor' => $sur, 'totalMinor' => $one + $sur];
	}
}
