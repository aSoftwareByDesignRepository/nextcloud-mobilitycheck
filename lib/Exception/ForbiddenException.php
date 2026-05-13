<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Exception;

use OCP\AppFramework\Http;

/**
 * §2.2 — A user passed the app entry gate (§2.4 / canUseApp) but is not
 * allowed to perform the requested action because their role does not
 * cover it. Maps to HTTP 403 inside controllers; deliberately distinct
 * from {@see AppAccessDeniedException} so the JSON error code stays
 * stable and we never accidentally render the "access denied to the
 * whole app" template when the user is just hitting the wrong button.
 */
class ForbiddenException extends \RuntimeException
{
	public function __construct(string $code = 'FORBIDDEN')
	{
		parent::__construct($code, Http::STATUS_FORBIDDEN);
	}
}
