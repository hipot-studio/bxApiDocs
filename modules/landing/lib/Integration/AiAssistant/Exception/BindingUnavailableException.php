<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Exception;

/**
 * The chat binding cannot start a generation: it is missing, busy or already spent.
 */
final class BindingUnavailableException extends \RuntimeException
{
	public function __construct(string $message, ?\Throwable $previous = null)
	{
		parent::__construct($message, 0, $previous);
	}
}
