<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Exception;

use OCP\AppFramework\Http;

class NotFoundException extends \RuntimeException
{
	public function __construct(string $code = 'NOT_FOUND')
	{
		parent::__construct($code, Http::STATUS_NOT_FOUND);
	}
}
