<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\Exception\NotFoundException;
use OCA\MobilityCheck\Exception\ValidationException;
use Dompdf\Dompdf;
use Dompdf\Options;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/** Appendix A7 — synchronous CSV export hand-off with expiring download tokens. */
class ExportService
{
	private const TTL_SECONDS = 3600;

	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private LogbookService $logbook,
		private ISecureRandom $random,
		private AuditLogService $audit,
		private SettingsService $settings,
	) {
	}

	/**
	 * @param array<string,mixed> $filters vehicleId, from, to
	 * @return array{token:string,expiresAt:string}
	 */
	public function requestLogbookCsv(string $userId, array $filters): array
	{
		if (!$this->settings->logbookEnabled()) {
			throw new ValidationException('LOGBOOK_MODULE_DISABLED');
		}
		$this->access->requireAnyAppRole($userId);
		$this->access->requireNotWorkshopOnly($userId);
		$vehicleId = (int)($filters['vehicleId'] ?? $filters['vehicle_id'] ?? 0);
		$from = (string)($filters['from'] ?? '');
		$to = (string)($filters['to'] ?? '');
		if ($vehicleId <= 0 || $from === '' || $to === '') {
			throw new ValidationException('EXPORT_FILTER_REQUIRED');
		}
		$rows = $this->logbook->list([
			'vehicleId' => $vehicleId,
			'from' => $from,
			'to' => $to,
			'confirmedOnly' => true,
		], $userId);
		$fh = fopen('php://temp', 'r+');
		if ($fh === false) {
			throw new \RuntimeException('temp_open_failed');
		}
		fprintf($fh, "\xEF\xBB\xBF");
		fputcsv($fh, ['trip_date', 'trip_type', 'driver_user_id', 'distance_km', 'purpose', 'client_or_contact', 'odometer_start_km', 'odometer_end_km'], ';');
		foreach ($rows as $r) {
			fputcsv($fh, [
				$r['trip_date'], $r['trip_type'], $r['driver_user_id'], $r['distance_km'],
				(string)($r['purpose'] ?? ''), (string)($r['client_or_contact'] ?? ''),
				$r['odometer_start_km'], $r['odometer_end_km'],
			], ';');
		}
		rewind($fh);
		$csv = stream_get_contents($fh) ?: '';
		fclose($fh);

		$dir = sys_get_temp_dir() . '/mc_exports';
		if (!is_dir($dir)) {
			mkdir($dir, 0700, true);
		}
		$token = bin2hex($this->random->generate(16));
		$path = $dir . '/' . $token . '.csv';
		file_put_contents($path, $csv);

		$now = gmdate('Y-m-d H:i:s');
		$exp = gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS);
		$ins = $this->db->getQueryBuilder();
		$ins->insert('mc_export_downloads')->values([
			'user_id' => $ins->createNamedParameter($userId),
			'token' => $ins->createNamedParameter($token),
			'mime_type' => $ins->createNamedParameter('text/csv'),
			'filename' => $ins->createNamedParameter('logbook-export.csv'),
			'storage_path' => $ins->createNamedParameter($path),
			'created_at' => $ins->createNamedParameter($now),
			'expires_at' => $ins->createNamedParameter($exp),
		]);
		$ins->executeStatement();
		$this->audit->log('export_download', 0, 'request_logbook_csv', $userId, ['vehicle_id' => $vehicleId]);

		return ['token' => $token, 'expiresAt' => $exp];
	}

	/**
	 * List the current user's recent exports, newest first. Used by the
	 * /exports page so users can re-download a still-valid token or see
	 * when an export was generated and by which filters.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listForUser(string $userId, int $limit = 50): array
	{
		$this->access->requireAuditorOrManagerOrAdmin($userId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('token', 'mime_type', 'filename', 'storage_path', 'created_at', 'expires_at')
			->from('mc_export_downloads')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'DESC')
			->setMaxResults(max(1, min(200, $limit)));
		$res = $qb->executeQuery();
		$now = time();
		$out = [];
		while (($r = $res->fetch()) !== false) {
			$expSec = strtotime((string)$r['expires_at'] . ' UTC');
			$out[] = [
				'token' => (string)$r['token'],
				'filename' => (string)$r['filename'],
				'mime_type' => (string)$r['mime_type'],
				'created_at' => (string)$r['created_at'],
				'expires_at' => (string)$r['expires_at'],
				'expired' => $expSec !== false && $expSec < $now,
			];
		}
		$res->closeCursor();
		return $out;
	}

	/** @return array{path:string,mime:string,filename:string} */
	public function resolveToken(string $userId, string $token): array
	{
		if ($token === '') {
			throw new ValidationException('TOKEN_REQUIRED');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('mc_export_downloads')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		$row = $qb->executeQuery()->fetch();
		if (!$row || ($row['user_id'] ?? '') !== $userId) {
			throw new NotFoundException('EXPORT_TOKEN_INVALID');
		}
		if (strtotime((string)$row['expires_at'] . ' UTC') < time()) {
			throw new ValidationException('EXPORT_TOKEN_EXPIRED');
		}
		return [
			'path' => (string)$row['storage_path'],
			'mime' => (string)$row['mime_type'],
			'filename' => (string)$row['filename'],
		];
	}

	/**
	 * §A7.1 — Render trusted HTML to PDF and register a time-limited download
	 * token (same table as CSV exports).
	 *
	 * @return array{token:string,expiresAt:string}
	 */
	public function requestReportPdfFromHtml(string $userId, string $html, string $filenameBase): array
	{
		$this->access->requireAuditorOrManagerOrAdmin($userId);
		if ($html === '') {
			throw new ValidationException('EXPORT_FILTER_REQUIRED');
		}
		$options = new Options();
		$options->set('isRemoteEnabled', false);
		$options->set('defaultFont', 'DejaVu Sans');
		$dompdf = new Dompdf($options);
		$dompdf->loadHtml($html, 'UTF-8');
		$dompdf->setPaper('A4', 'portrait');
		$dompdf->render();
		$pdf = $dompdf->output();
		if ($pdf === false || $pdf === '') {
			throw new \RuntimeException('pdf_render_failed');
		}

		$dir = sys_get_temp_dir() . '/mc_exports';
		if (!is_dir($dir)) {
			mkdir($dir, 0700, true);
		}
		$token = bin2hex($this->random->generate(16));
		$safeBase = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $filenameBase) ?: 'report';
		$filename = $safeBase . '.pdf';
		$path = $dir . '/' . $token . '.pdf';
		file_put_contents($path, $pdf);

		$now = gmdate('Y-m-d H:i:s');
		$exp = gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS);
		$ins = $this->db->getQueryBuilder();
		$ins->insert('mc_export_downloads')->values([
			'user_id' => $ins->createNamedParameter($userId),
			'token' => $ins->createNamedParameter($token),
			'mime_type' => $ins->createNamedParameter('application/pdf'),
			'filename' => $ins->createNamedParameter($filename),
			'storage_path' => $ins->createNamedParameter($path),
			'created_at' => $ins->createNamedParameter($now),
			'expires_at' => $ins->createNamedParameter($exp),
		]);
		$ins->executeStatement();
		$this->audit->log('export_download', 0, 'request_report_pdf', $userId, ['filename' => $filename]);

		return ['token' => $token, 'expiresAt' => $exp];
	}
}
