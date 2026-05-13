<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Exception;

use OCP\AppFramework\Http;

/**
 * Thrown when the current user is not allowed to use MobilityCheck at all
 * (§2.4 app entry gate) or lacks the role required for a specific action
 * (§2.2 permission matrix). The denial reason is rendered in the access
 * denied template / mapped to a stable JSON error code.
 */
class AppAccessDeniedException extends \RuntimeException
{
	public function __construct(private string $denialReason = 'no_app_role')
	{
		parent::__construct('access_denied', Http::STATUS_FORBIDDEN);
	}

	public function getDenialReason(): string
	{
		return $this->denialReason;
	}
}
