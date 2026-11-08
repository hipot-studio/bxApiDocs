<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Exception;

/**
 * Too many consecutive topic moderation blocks for the same draft.
 */
final class ModerationBlockLimitException extends \RuntimeException
{
	public function __construct(string $message, ?\Throwable $previous = null)
	{
		parent::__construct($message, 0, $previous);
	}
}
