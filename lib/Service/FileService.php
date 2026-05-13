<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\AppInfo\Application;
use OCA\MobilityCheck\Exception\ValidationException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Wrapper around Nextcloud's Files app. Stores **node IDs** for every
 * upload (licence scans, damage photos, repair invoices, exports) and
 * keeps a per-app folder structure (§1.4-style discipline borrowed from
 * BudgetCheck — never `file_put_contents`, never store filesystem
 * paths). MIME and size validation runs in PHP before persisting.
 */
class FileService
{
	private const DEFAULT_MAX_BYTES = 10 * 1024 * 1024; // 10 MB (§8 uploads)

	public const FOLDER_LICENCE_SCANS = 'MobilityCheck/Licences';
	public const FOLDER_DAMAGE_PHOTOS = 'MobilityCheck/Damage';
	public const FOLDER_REPAIR_INVOICES = 'MobilityCheck/Repairs';
	public const FOLDER_EXPORTS = 'MobilityCheck/Exports';
	public const FOLDER_RECEIPTS = 'MobilityCheck/Receipts';

	private const ALLOWED_MIMES = [
		// Images
		'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif',
		// Documents (licence scans, invoices, receipts)
		'application/pdf',
		// Exports
		'text/csv', 'text/plain', 'text/html',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	];

	public function __construct(
		private IRootFolder $rootFolder,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function maxUploadBytes(): int
	{
		$value = (int)$this->config->getAppValue(Application::APP_ID, 'max_upload_bytes', (string)self::DEFAULT_MAX_BYTES);
		return $value > 0 ? $value : self::DEFAULT_MAX_BYTES;
	}

	/**
	 * Store an uploaded file in the user's Files tree.
	 *
	 * @return array{nodeId:string,name:string,mime:string,size:int}
	 */
	public function storeUserUpload(
		string $userId,
		string $folderPath,
		string $originalName,
		string $sourceTmpPath,
		?string $mimeType = null,
	): array {
		$size = is_file($sourceTmpPath) ? (int)filesize($sourceTmpPath) : 0;
		if ($size <= 0) {
			throw new ValidationException('UPLOAD_EMPTY');
		}
		if ($size > $this->maxUploadBytes()) {
			throw new ValidationException('UPLOAD_TOO_LARGE');
		}
		$detected = $this->detectMime($sourceTmpPath, $mimeType);
		if (!in_array($detected, self::ALLOWED_MIMES, true)) {
			throw new ValidationException('UPLOAD_MIME_NOT_ALLOWED', null, ['mime' => $detected]);
		}
		$safeName = $this->sanitiseFilename($originalName);
		$userFolder = $this->getUserFolder($userId);
		$target = $this->ensureFolder($userFolder, $folderPath);
		$finalName = $this->uniqueName($target, $safeName);
		$content = file_get_contents($sourceTmpPath);
		if ($content === false) {
			throw new ValidationException('UPLOAD_READ_FAILED');
		}
		$node = $target->newFile($finalName, $content);
		return [
			'nodeId' => (string)$node->getId(),
			'name' => $node->getName(),
			'mime' => $detected,
			'size' => $size,
		];
	}

	public function readableByUser(string $userId, string $nodeId): bool
	{
		try {
			$node = $this->resolveNode($userId, $nodeId);
			return $node !== null && $node->isReadable();
		} catch (\Throwable) {
			return false;
		}
	}

	public function resolveNode(string $userId, string $nodeId): ?Node
	{
		$id = (int)$nodeId;
		if ($id <= 0) {
			return null;
		}
		try {
			$folder = $this->getUserFolder($userId);
			$nodes = $folder->getById($id);
			if ($nodes === []) {
				return null;
			}
			return $nodes[0];
		} catch (\Throwable $e) {
			$this->logger->info('MobilityCheck file resolve failed', ['e' => $e->getMessage()]);
			return null;
		}
	}

	public function getUserFolder(string $userId): Folder
	{
		return $this->rootFolder->getUserFolder($userId);
	}

	private function ensureFolder(Folder $parent, string $path): Folder
	{
		$cleanPath = trim($path, "/ \t\n\r\0\x0B");
		if ($cleanPath === '') {
			return $parent;
		}
		$current = $parent;
		foreach (explode('/', $cleanPath) as $segment) {
			$segment = $this->sanitiseFilename($segment);
			if ($segment === '') {
				continue;
			}
			try {
				$child = $current->get($segment);
				if (!$child instanceof Folder) {
					throw new ValidationException('UPLOAD_PATH_BLOCKED');
				}
				$current = $child;
			} catch (NotFoundException) {
				$current = $current->newFolder($segment);
			}
		}
		return $current;
	}

	private function uniqueName(Folder $folder, string $name): string
	{
		$base = $name;
		$ext = '';
		$dotPos = strrpos($name, '.');
		if ($dotPos !== false && $dotPos > 0) {
			$base = substr($name, 0, $dotPos);
			$ext = substr($name, $dotPos);
		}
		$i = 0;
		$candidate = $name;
		while ($folder->nodeExists($candidate)) {
			$i++;
			$candidate = $base . ' (' . $i . ')' . $ext;
			if ($i > 9999) {
				throw new \RuntimeException('UPLOAD_NAME_RESOLVE_FAILED');
			}
		}
		return $candidate;
	}

	private function sanitiseFilename(string $name): string
	{
		$name = trim($name);
		// Drop path traversal segments and disallowed characters.
		$name = preg_replace('#[\\\\/]+#', '_', $name) ?? '';
		$name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
		$name = preg_replace('/[<>:"|?*]/', '_', $name) ?? '';
		if (strlen($name) > 191) {
			$name = substr($name, 0, 191);
		}
		return $name === '' ? 'upload' : $name;
	}

	private function detectMime(string $path, ?string $hint): string
	{
		if (function_exists('finfo_open')) {
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			if ($finfo !== false) {
				$detected = finfo_file($finfo, $path);
				finfo_close($finfo);
				if (is_string($detected) && $detected !== '') {
					return strtolower($detected);
				}
			}
		}
		if ($hint !== null && $hint !== '') {
			return strtolower($hint);
		}
		return 'application/octet-stream';
	}
}
