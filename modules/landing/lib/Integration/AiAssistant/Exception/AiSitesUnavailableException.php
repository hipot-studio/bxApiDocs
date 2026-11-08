<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Exception;

/**
 * The product gate refused AI sites for this portal; the reason code explains why.
 */
final class AiSitesUnavailableException extends \RuntimeException
{
	public function __construct(
		private readonly string $reasonCode,
		?\Throwable $previous = null,
	)
	{
		parent::__construct('AI sites are unavailable: ' . $reasonCode, 0, $previous);
	}

	public function getReasonCode(): string
	{
		return $this->reasonCode;
	}
}
