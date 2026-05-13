<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Util;

/**
 * Conservative free-text redaction for GDPR (no DB-specific functions).
 * Used for {@see \OCA\MobilityCheck\Service\GdprErasureService} on fields
 * like `mc_bookings.passengers`.
 */
final class GdprFreeTextPseudonymizer
{
	/**
	 * Replace occurrences of a Nextcloud user id when it is not glued to
	 * other alphanumeric ASCII (avoids clobbering "admin" inside "administrator").
	 */
	public static function redactNextcloudUid(string $text, string $uid, string $replacement): string
	{
		if ($uid === '' || $text === '') {
			return $text;
		}
		$q = preg_quote($uid, '/');
		$pattern = '/(?<![A-Za-z0-9])' . $q . '(?![A-Za-z0-9])/';
		$out = preg_replace($pattern, $replacement, $text);
		return $out !== null ? $out : $text;
	}

	/**
	 * Deterministic placeholder for a display name (stable for the same
	 * leaver + normalised spelling; does not reveal the original name).
	 */
	public static function displayNamePlaceholder(string $leaver, string $displayName): string
	{
		$key = mb_strtolower(trim($displayName), 'UTF-8');
		return '__n_' . substr(hash('sha256', $leaver . "\0" . $key), 0, 16) . '__';
	}

	/**
	 * Case-insensitive removal of a display name when it is not part of a
	 * larger Unicode letter run (reduces false positives on substrings).
	 * Only applied when {@see mb_strlen} of trimmed name is at least 4.
	 */
	public static function redactDisplayName(string $text, string $displayName, string $placeholder): string
	{
		$dn = trim($displayName);
		if ($dn === '' || mb_strlen($dn, 'UTF-8') < 4) {
			return $text;
		}
		$len = mb_strlen($dn, 'UTF-8');
		$result = $text;
		$offset = 0;
		while (true) {
			$pos = mb_stripos($result, $dn, $offset, 'UTF-8');
			if ($pos === false) {
				break;
			}
			$before = $pos > 0 ? mb_substr($result, $pos - 1, 1, 'UTF-8') : '';
			$afterPos = $pos + $len;
			$after = $afterPos < mb_strlen($result, 'UTF-8') ? mb_substr($result, $afterPos, 1, 'UTF-8') : '';
			if (self::isUnicodeLetter($before) || self::isUnicodeLetter($after)) {
				$offset = $pos + 1;
				continue;
			}
			$result = mb_substr($result, 0, $pos, 'UTF-8')
				. $placeholder
				. mb_substr($result, $afterPos, null, 'UTF-8');
			$offset = $pos + mb_strlen($placeholder, 'UTF-8');
		}
		return $result;
	}

	private static function isUnicodeLetter(string $c): bool
	{
		if ($c === '') {
			return false;
		}
		return preg_match('/\p{L}/u', $c) === 1;
	}
}
