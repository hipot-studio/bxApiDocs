<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Exception;

/**
 * The ai module cannot be loaded, so no generation can be started.
 */
final class AiModuleUnavailableException extends \RuntimeException
{
	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct('AI module is unavailable.', 0, $previous);
	}
}
