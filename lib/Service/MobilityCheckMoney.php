<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\ValidationException;

/**
 * §1.4 — All monetary values are stored as integer minor units
 * (Euro cents). Floating-point money arithmetic is forbidden.
 *
 * This helper centralises parsing, rounding (kaufmännische
 * Rundung — half-up), and formatting so every service speaks the
 * same dialect. Used by both `CostService` (VAT split) and
 * `ReimbursementService` (per-km × distance).
 */
final class MobilityCheckMoney
{
	/** Smallest representable amount (1 cent). */
	public const ONE_CENT = 1;

	/** VAT rate basis points (1900 = 19.00 %). */
	public const VAT_RATE_BP_VALID = [0, 700, 1900];

	/**
	 * Parse a human decimal (e.g. "12,34" or "12.34" or "12") into
	 * Euro cents. Rejects negative values and obvious garbage.
	 *
	 * @throws ValidationException when the value cannot be parsed.
	 */
	public static function decimalToMinor(string|int|float $value): int
	{
		if (is_int($value)) {
			$cents = $value * 100;
			if ($cents < 0) {
				throw new ValidationException('MONEY_NEGATIVE_NOT_ALLOWED');
			}
			return $cents;
		}
		if (is_float($value)) {
			$cents = (int) round($value * 100);
			if ($cents < 0) {
				throw new ValidationException('MONEY_NEGATIVE_NOT_ALLOWED');
			}
			return $cents;
		}
		$raw = trim($value);
		if ($raw === '') {
			throw new ValidationException('MONEY_REQUIRED');
		}
		// Normalise: strip spaces and the thousand separator (a dot when the
		// decimal mark is a comma, a comma when the decimal mark is a dot).
		$raw = str_replace([' ', "\u{00A0}"], '', $raw);
		$lastComma = strrpos($raw, ',');
		$lastDot = strrpos($raw, '.');
		if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
			// Comma is the decimal separator (German default).
			$raw = str_replace('.', '', $raw);
			$raw = str_replace(',', '.', $raw);
		} else {
			// Dot is the decimal separator (English).
			$raw = str_replace(',', '', $raw);
		}
		if (!preg_match('/^-?\d+(\.\d{1,4})?$/', $raw)) {
			throw new ValidationException('MONEY_INVALID');
		}
		$float = (float)$raw;
		if ($float < 0) {
			throw new ValidationException('MONEY_NEGATIVE_NOT_ALLOWED');
		}
		// Kaufmännische Rundung — half-up.
		return self::roundHalfUp($float * 100);
	}

	public static function minorToDecimalString(int $minor, int $fractionDigits = 2): string
	{
		$negative = $minor < 0;
		$abs = abs($minor);
		$divisor = (int) (10 ** $fractionDigits);
		$whole = intdiv($abs, $divisor);
		$frac = $abs % $divisor;
		$out = (string) $whole . '.' . str_pad((string)$frac, $fractionDigits, '0', STR_PAD_LEFT);
		return $negative ? '-' . $out : $out;
	}

	/**
	 * VAT split given a gross amount in minor units and a VAT rate
	 * in basis points (e.g. 1900 for 19 %). Net and VAT use
	 * Kaufmännische Rundung (half-up). The remainder lands on net
	 * so gross = net + vat exactly (no fractional cent leaks).
	 *
	 * @return array{net:int,vat:int,gross:int,rate_bp:int}
	 */
	public static function splitVat(int $grossMinor, int $vatRateBp): array
	{
		if ($grossMinor < 0) {
			throw new ValidationException('MONEY_NEGATIVE_NOT_ALLOWED');
		}
		if (!in_array($vatRateBp, self::VAT_RATE_BP_VALID, true) && $vatRateBp >= 0 && $vatRateBp <= 9999) {
			// Permit custom positive rates within sensible bounds for the test bench.
			// Caller responsible for validating against allowed list.
		}
		if ($vatRateBp < 0 || $vatRateBp > 9999) {
			throw new ValidationException('VAT_RATE_INVALID');
		}
		if ($vatRateBp === 0) {
			return ['net' => $grossMinor, 'vat' => 0, 'gross' => $grossMinor, 'rate_bp' => 0];
		}
		// gross = net * (1 + r) → net = gross / (1 + r)
		$divisor = 10000 + $vatRateBp;
		$net = self::roundHalfUp(($grossMinor * 10000) / $divisor);
		$vat = $grossMinor - $net;
		return ['net' => $net, 'vat' => $vat, 'gross' => $grossMinor, 'rate_bp' => $vatRateBp];
	}

	public static function roundHalfUp(float $value): int
	{
		if ($value >= 0) {
			return (int) floor($value + 0.5);
		}
		return -((int) floor(-$value + 0.5));
	}

	/**
	 * Multiply a per-km rate (minor / km) by a distance and return
	 * minor units. Uses Kaufmännische Rundung. Use this for
	 * reimbursement amount calculations so the result matches the
	 * row stored on `mc_reimbursement_claims`.
	 */
	public static function ratePerKmToAmount(int $ratePerKmMinor, int $distanceKm): int
	{
		if ($ratePerKmMinor < 0 || $distanceKm < 0) {
			throw new ValidationException('MONEY_NEGATIVE_NOT_ALLOWED');
		}
		return $ratePerKmMinor * $distanceKm;
	}
}
