<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Util;

/**
 * Portable JSON transforms for GDPR pseudonymisation (no DB-specific JSON
 * operators). Used by {@see \OCA\MobilityCheck\Service\GdprErasureService}.
 */
final class GdprJsonPseudonymizer
{
	/**
	 * Remove an exact Nextcloud user-id match from a JSON array of string ids
	 * (e.g. `mc_bookings.passenger_user_ids`, `mc_stations.notification_recipient_user_ids`).
	 *
	 * Malformed JSON or non-list payloads are left unchanged so we never
	 * corrupt operator-edited data.
	 *
	 * @return array{0: bool, 1: ?string} Tuple: whether the value changed, and
	 *         the new JSON string or null meaning SQL NULL (empty list after strip).
	 */
	public static function scrubJsonStringUidList(?string $json, string $leaver): array
	{
		if ($json === null || $json === '') {
			return [false, null];
		}
		try {
			/** @var mixed $decoded */
			$decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			return [false, $json];
		}
		if (!is_array($decoded)) {
			return [false, $json];
		}
		$isList = array_keys($decoded) === range(0, count($decoded) - 1);
		if (!$isList) {
			return [false, $json];
		}
		$out = [];
		foreach ($decoded as $item) {
			if (!is_string($item) && !is_int($item)) {
				return [false, $json];
			}
			$s = (string)$item;
			if ($s === $leaver) {
				continue;
			}
			$out[] = $s;
		}
		if (count($out) === count($decoded)) {
			return [false, $json];
		}
		if ($out === []) {
			return [true, null];
		}
		return [true, json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
	}

	/**
	 * Replace every JSON string value exactly equal to `$leaver` with `$tombstone`,
	 * recursively (e.g. frozen `approval_chain_snapshot_json`).
	 *
	 * @return array{0: bool, 1: ?string} Whether the value changed, and new JSON or null for SQL NULL.
	 */
	public static function scrubNestedUidStrings(?string $json, string $leaver, string $tombstone): array
	{
		if ($json === null || $json === '') {
			return [false, null];
		}
		try {
			/** @var mixed $decoded */
			$decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			return [false, $json];
		}
		if (!is_array($decoded)) {
			return [false, $json];
		}
		$mutated = false;
		$replaced = self::replaceUidDeep($decoded, $leaver, $tombstone, $mutated);
		if (!$mutated) {
			return [false, $json];
		}
		return [true, json_encode($replaced, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
	}

	/**
	 * @param array<mixed> $node
	 * @return array<mixed>|mixed
	 */
	private static function replaceUidDeep(mixed $node, string $leaver, string $tombstone, bool &$mutated): mixed
	{
		if ($node === $leaver) {
			$mutated = true;
			return $tombstone;
		}
		if (!is_array($node)) {
			return $node;
		}
		$out = [];
		foreach ($node as $k => $v) {
			$out[$k] = self::replaceUidDeep($v, $leaver, $tombstone, $mutated);
		}
		return $out;
	}
}
