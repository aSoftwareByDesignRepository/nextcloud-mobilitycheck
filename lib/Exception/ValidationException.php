<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Exception;

use OCP\AppFramework\Http;

/**
 * Domain validation error (e.g. licence_plate already in use, odometer
 * regression, VAT mismatch). Carries a stable, l10n-keyable error code
 * and an optional field-name for field-level rendering on the form.
 */
class ValidationException extends \RuntimeException
{
	/** @param array<string,mixed> $context */
	public function __construct(
		string $code,
		private ?string $field = null,
		private array $context = [],
		private int $httpStatus = Http::STATUS_UNPROCESSABLE_ENTITY,
	) {
		parent::__construct($code, $httpStatus);
	}

	public function getField(): ?string
	{
		return $this->field;
	}

	/** @return array<string,mixed> */
	public function getContext(): array
	{
		return $this->context;
	}

	public function getHttpStatus(): int
	{
		return $this->httpStatus;
	}
}
