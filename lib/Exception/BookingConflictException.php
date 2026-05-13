<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Exception;

use OCP\AppFramework\Http;

/**
 * Raised by {@see \OCA\MobilityCheck\Service\BookingService::createBooking()}
 * when a competing booking won the gap-lock race for the same vehicle
 * and time window. The competing booking id is exposed so the UI can
 * link the operator to the existing reservation.
 */
class BookingConflictException extends \RuntimeException
{
	public function __construct(
		private int $competingBookingId,
		private string $startDatetime,
		private string $endDatetime,
	) {
		parent::__construct('BOOKING_CONFLICT', Http::STATUS_CONFLICT);
	}

	public function getCompetingBookingId(): int
	{
		return $this->competingBookingId;
	}

	public function getStartDatetime(): string
	{
		return $this->startDatetime;
	}

	public function getEndDatetime(): string
	{
		return $this->endDatetime;
	}
}
