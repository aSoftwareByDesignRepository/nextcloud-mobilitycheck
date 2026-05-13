<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCP\IL10N;

/**
 * Appendix A §A7.1 — Server-built print HTML for PDF export (no untrusted
 * client HTML). Tables use semantic markup suitable for Dompdf.
 */
class ReportPdfBuilder
{
	/**
	 * @param array<string,mixed> $payload Raw report payload from {@see ReportService}
	 */
	public static function build(string $tab, array $payload, IL10N $l, string $htmlLang = 'en'): string
	{
		$htmlLang = preg_replace('/[^a-zA-Z0-9-]+/', '', $htmlLang) ?: 'en';
		$title = match ($tab) {
			'costs' => $l->t('Cost report'),
			'utilisation' => $l->t('Vehicle utilisation'),
			'bookings' => $l->t('Bookings report'),
			'damage' => $l->t('Damage report'),
			'compliance' => $l->t('Driver compliance'),
			'notifications' => $l->t('Notifications log'),
			default => $l->t('MobilityCheck report'),
		};
		$generated = gmdate('Y-m-d H:i:s') . ' UTC';
		$body = self::bodyForTab($tab, $payload, $l);
		$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		return '<!DOCTYPE html><html lang="' . $esc($htmlLang) . '"><head><meta charset="UTF-8">'
			. '<title>' . $esc($title) . '</title>'
			. '<style>@page{size:A4;margin:12mm;}body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10pt;color:#111;}h1{font-size:14pt;}table{border-collapse:collapse;width:100%;margin-top:8px;}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left;}th{background:#eee;}.meta{color:#444;font-size:9pt;margin-bottom:8px;}</style>'
			. '</head><body><h1>' . $esc($title) . '</h1>'
			. '<p class="meta">' . $esc($l->t('Generated')) . ': ' . $esc($generated) . '</p>'
			. $body
			. '</body></html>';
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private static function bodyForTab(string $tab, array $payload, IL10N $l): string
	{
		return match ($tab) {
			'costs' => self::costsHtml($payload, $l),
			'utilisation' => self::utilisationHtml($payload, $l),
			'bookings' => self::bookingsHtml($payload, $l),
			'damage' => self::damageHtml($payload, $l),
			'compliance' => self::complianceHtml($payload, $l),
			'notifications' => self::notificationsHtml($payload, $l),
			default => '<p>' . htmlspecialchars($l->t('Unsupported report type.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
		};
	}

	/** @param array<string,mixed> $d */
	private static function costsHtml(array $d, IL10N $l): string
	{
		$rows = '';
		foreach ($d['byVehicle'] ?? [] as $r) {
			$rows .= '<tr><td>' . self::e((string)($r['internalName'] ?? ''))
				. '</td><td>' . self::e((string)($r['vehicleId'] ?? ''))
				. '</td><td style="text-align:right">' . self::e((string)($r['total'] ?? '0')) . '</td></tr>';
		}
		$total = (string)($d['total'] ?? '0');
		return '<p><strong>' . self::e($l->t('Total gross')) . ':</strong> ' . self::e($total) . '</p>'
			. '<table><thead><tr><th>' . self::e($l->t('Vehicle')) . '</th><th>' . self::e($l->t('Vehicle ID')) . '</th><th>' . self::e($l->t('Total')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
	}

	/** @param list<array<string,mixed>>|array<string,mixed> $d */
	private static function utilisationHtml(array $d, IL10N $l): string
	{
		$list = [];
		if ($d !== [] && array_is_list($d)) {
			$list = $d;
		} elseif (isset($d['vehicleId'], $d['utilisationPercent']) && is_numeric($d['vehicleId'])) {
			// Single-vehicle payload from ReportService::vehicleUtilisation (non-fleet).
			$list = [[
				'vehicleId' => (int)$d['vehicleId'],
				'internalName' => (string)($d['internalName'] ?? ('#' . (int)$d['vehicleId'])),
				'bookingsCount' => (int)($d['totalBookings'] ?? 0),
				'totalDistanceKm' => (int)($d['totalDistanceKm'] ?? 0),
				'utilisationPercent' => $d['utilisationPercent'],
			]];
		} else {
			$rows = $d['rows'] ?? [];
			$list = is_array($rows) ? $rows : [];
		}
		$rows = '';
		foreach ($list as $r) {
			if (!is_array($r)) {
				continue;
			}
			$dist = $r['totalDistanceKm'] ?? null;
			$distCell = $dist !== null && $dist !== '' ? self::e((string)$dist) . ' km' : '—';
			$rows .= '<tr><td>' . self::e((string)($r['internalName'] ?? $r['name'] ?? ''))
				. '</td><td>' . self::e((string)($r['vehicleId'] ?? $r['id'] ?? ''))
				. '</td><td>' . self::e((string)($r['bookingsCount'] ?? $r['totalBookings'] ?? ''))
				. '</td><td>' . $distCell
				. '</td><td>' . self::e((string)($r['utilisationPercent'] ?? '')) . '%</td></tr>';
		}
		return '<table><thead><tr><th>' . self::e($l->t('Vehicle')) . '</th><th>' . self::e($l->t('Vehicle ID'))
			. '</th><th>' . self::e($l->t('Bookings')) . '</th><th>' . self::e($l->t('Distance'))
			. '</th><th>' . self::e($l->t('Utilisation')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
	}

	/** @param list<array<string,mixed>> $rows */
	private static function bookingsHtml(array $rows, IL10N $l): string
	{
		$body = '';
		foreach ($rows as $r) {
			if (!is_array($r)) {
				continue;
			}
			$body .= '<tr><td>' . self::e((string)($r['id'] ?? ''))
				. '</td><td>' . self::e((string)($r['vehicleId'] ?? ''))
				. '</td><td>' . self::e((string)($r['driverUserId'] ?? ''))
				. '</td><td>' . self::e((string)($r['status'] ?? ''))
				. '</td><td>' . self::e((string)($r['purpose'] ?? '')) . '</td></tr>';
		}
		return '<table><thead><tr><th>ID</th><th>' . self::e($l->t('Vehicle')) . '</th><th>' . self::e($l->t('Driver'))
			. '</th><th>' . self::e($l->t('Status')) . '</th><th>' . self::e($l->t('Purpose')) . '</th></tr></thead><tbody>' . $body . '</tbody></table>';
	}

	/** @param list<array<string,mixed>> $rows */
	private static function damageHtml(array $rows, IL10N $l): string
	{
		$body = '';
		foreach ($rows as $r) {
			if (!is_array($r)) {
				continue;
			}
			$body .= '<tr><td>' . self::e((string)($r['id'] ?? ''))
				. '</td><td>' . self::e((string)($r['vehicleId'] ?? ''))
				. '</td><td>' . self::e((string)($r['severity'] ?? ''))
				. '</td><td>' . self::e((string)($r['status'] ?? '')) . '</td></tr>';
		}
		return '<table><thead><tr><th>ID</th><th>' . self::e($l->t('Vehicle')) . '</th><th>' . self::e($l->t('Severity'))
			. '</th><th>' . self::e($l->t('Status')) . '</th></tr></thead><tbody>' . $body . '</tbody></table>';
	}

	/** @param list<array<string,mixed>> $rows */
	private static function complianceHtml(array $rows, IL10N $l): string
	{
		$body = '';
		foreach ($rows as $r) {
			if (!is_array($r)) {
				continue;
			}
			$body .= '<tr><td>' . self::e((string)($r['userId'] ?? ''))
				. '</td><td>' . self::e((string)($r['licenceStatus'] ?? ''))
				. '</td><td>' . self::e((string)($r['complianceStatus'] ?? '')) . '</td></tr>';
		}
		return '<table><thead><tr><th>' . self::e($l->t('Driver')) . '</th><th>' . self::e($l->t('Licence'))
			. '</th><th>' . self::e($l->t('Compliance')) . '</th></tr></thead><tbody>' . $body . '</tbody></table>';
	}

	/** @param list<array<string,mixed>> $rows */
	private static function notificationsHtml(array $rows, IL10N $l): string
	{
		$body = '';
		foreach ($rows as $r) {
			if (!is_array($r)) {
				continue;
			}
			$body .= '<tr><td>' . self::e((string)($r['sentAt'] ?? ''))
				. '</td><td>' . self::e((string)($r['notificationType'] ?? ''))
				. '</td><td>' . self::e((string)($r['recipientUserId'] ?? '')) . '</td></tr>';
		}
		return '<table><thead><tr><th>' . self::e($l->t('Sent at')) . '</th><th>' . self::e($l->t('Type'))
			. '</th><th>' . self::e($l->t('Recipient')) . '</th></tr></thead><tbody>' . $body . '</tbody></table>';
	}

	private static function e(string $s): string
	{
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
