<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Exception;

/**
 * AI sites are switched off by the release option.
 */
final class AiSitesDisabledException extends \RuntimeException
{
	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct('AI sites are disabled.', 0, $previous);
	}
}
