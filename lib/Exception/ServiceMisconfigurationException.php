<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Exception;

/**
 * Thrown when operator configuration is invalid and the request must
 * fail closed (HTTP 500, stable error code) — e.g. §4.5a §E.2.
 */
final class ServiceMisconfigurationException extends \RuntimeException
{
}
