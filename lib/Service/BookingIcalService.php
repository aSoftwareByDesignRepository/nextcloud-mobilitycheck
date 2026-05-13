<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * RFC 5545 iCalendar (VCALENDAR + one VEVENT) for booking-related notification emails.
 *
 * Uses METHOD:PUBLISH and UTC DTSTART/DTEND matching {@see BookingService} storage.
 * Text properties are escaped per RFC 5545; lines are folded at UTF-8 boundaries.
 *
 * Limitations (documented for auditors): updates and reschedules reuse the same UID
 * without SEQUENCE; clients may duplicate events instead of updating — recipients can
 * remove stale imports. SUMMARY/DESCRIPTION may contain operational names supplied by users.
 */
final class BookingIcalService
{
	public function __construct(
		private IConfig $config,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * @return list<string>
	 */
	public static function emailTypesWithCalendarAttachment(): array
	{
		return [
			NotificationService::TYPE_BOOKING_REQUESTED,
			NotificationService::TYPE_BOOKING_APPROVED,
			NotificationService::TYPE_BOOKING_RESCHEDULED,
			NotificationService::TYPE_BOOKING_EXTENDED,
			NotificationService::TYPE_APPROVAL_ESCALATED_LM,
			NotificationService::TYPE_APPROVAL_ESCALATED_FLEET,
			NotificationService::TYPE_APPROVAL_LINE_MANAGER_TIMEOUT_REMINDER,
			NotificationService::TYPE_APPROVAL_LINE_MANAGER_OVERRIDDEN,
			NotificationService::TYPE_APPROVAL_LINE_MANAGER_REASSIGNED,
		];
	}

	/**
	 * @param array<string, mixed> $ctx Context from {@see NotificationService::send()}
	 */
	public function buildForEmail(string $type, array $ctx, IL10N $l, int $entityId): ?string
	{
		if (!in_array($type, self::emailTypesWithCalendarAttachment(), true)) {
			return null;
		}
		$bookingId = (int)($ctx['bookingId'] ?? $entityId);
		if ($bookingId <= 0) {
			return null;
		}
		$startRaw = (string)($ctx['start'] ?? $ctx['start_datetime'] ?? '');
		$endRaw = (string)($ctx['end'] ?? $ctx['end_datetime'] ?? '');
		if ($type === NotificationService::TYPE_BOOKING_EXTENDED) {
			$endRaw = (string)($ctx['newEnd'] ?? $ctx['new_end_datetime'] ?? $endRaw);
		}
		if ($startRaw === '' || $endRaw === '') {
			return null;
		}
		$start = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startRaw, new \DateTimeZone('UTC'));
		$end = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endRaw, new \DateTimeZone('UTC'));
		if ($start === false || $end === false) {
			return null;
		}
		if ($end <= $start) {
			return null;
		}
		$dtStart = $start->format('Ymd\THis\Z');
		$dtEnd = $end->format('Ymd\THis\Z');
		$dtStamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z');
		$domain = (string)$this->config->getSystemValue('mail_domain', 'localhost');
		if ($domain === '') {
			$domain = 'localhost';
		}
		$uid = 'mobilitycheck-booking-' . $bookingId . '@' . $domain;

		$vehicle = (string)($ctx['vehicleName'] ?? '');
		$driver = (string)($ctx['driverName'] ?? '');
		$purpose = (string)($ctx['purpose'] ?? '');
		$summaryRaw = match ($type) {
			NotificationService::TYPE_BOOKING_REQUESTED => $l->t('MobilityCheck — booking approval: %s', [$vehicle !== '' ? $vehicle : '#' . $bookingId]),
			NotificationService::TYPE_BOOKING_APPROVED => $l->t('MobilityCheck — approved booking: %s', [$vehicle !== '' ? $vehicle : '#' . $bookingId]),
			NotificationService::TYPE_BOOKING_RESCHEDULED => $l->t('MobilityCheck — booking updated: %s', [$vehicle !== '' ? $vehicle : '#' . $bookingId]),
			NotificationService::TYPE_BOOKING_EXTENDED => $l->t('MobilityCheck — booking extended: %s', [$vehicle !== '' ? $vehicle : '#' . $bookingId]),
			default => $l->t('MobilityCheck — booking #%d', [$bookingId]),
		};
		$descParts = array_filter([
			$vehicle !== '' ? $l->t('Vehicle: %s', [$vehicle]) : null,
			$driver !== '' ? $l->t('Driver: %s', [$driver]) : null,
			$purpose !== '' ? $l->t('Purpose: %s', [mb_substr($purpose, 0, 500)]) : null,
		]);
		$descriptionRaw = implode("\n", $descParts);
		if ($descriptionRaw === '') {
			$descriptionRaw = $summaryRaw;
		}

		$status = $type === NotificationService::TYPE_BOOKING_REQUESTED ? 'TENTATIVE' : 'CONFIRMED';
		$summary = $this->escapeIcalText($summaryRaw);
		$description = $this->escapeIcalText(mb_substr($descriptionRaw, 0, 2000));

		$bookingUrl = $this->escapeIcalText($this->absoluteBookingUrl($bookingId));

		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Nextcloud//MobilityCheck//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:' . $this->escapeIcalText($uid),
			'DTSTAMP:' . $dtStamp,
			'DTSTART:' . $dtStart,
			'DTEND:' . $dtEnd,
			$this->foldIcalLine('SUMMARY:' . $summary),
			$this->foldIcalLine('DESCRIPTION:' . $description),
			'STATUS:' . $status,
			'TRANSP:OPAQUE',
			$this->foldIcalLine('URL:' . $bookingUrl),
			'END:VEVENT',
			'END:VCALENDAR',
		];

		return implode("\r\n", $lines);
	}

	private function absoluteBookingUrl(int $bookingId): string
	{
		return $this->urlGenerator->linkToRouteAbsolute('mobilitycheck.page.bookingDetail', ['id' => $bookingId]);
	}

	private function escapeIcalText(string $text): string
	{
		return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $text);
	}

	/**
	 * RFC 5545 “content line” folding: max 75 octets per physical line; continuation lines
	 * begin with one space (counted in the 75-octet limit → 74 new payload octets).
	 */
	private function foldIcalLine(string $line): string
	{
		$byteLen = strlen($line);
		if ($byteLen <= 75) {
			return $line;
		}
		$out = '';
		$byteOffset = 0;
		$first = true;
		while ($byteOffset < $byteLen) {
			if (!$first) {
				$out .= "\r\n ";
			}
			$maxTake = $first ? 75 : 74;
			$chunk = mb_strcut($line, $byteOffset, $maxTake, 'UTF-8');
			if ($chunk === '' || $chunk === false) {
				$chunk = substr($line, $byteOffset, 1);
			}
			$out .= $chunk;
			$byteOffset += strlen($chunk);
			$first = false;
		}
		return $out;
	}
}
