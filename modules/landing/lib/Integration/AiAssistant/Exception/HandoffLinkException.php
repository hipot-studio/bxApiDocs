<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Exception;

/**
 * Refusal to give a link carrying the request of the chat; the message starts with the ASCII error code.
 */
class HandoffLinkException extends \RuntimeException
{
	public function __construct(
		private readonly string $errorCode,
		string $message,
		?\Throwable $previous = null,
	)
	{
		parent::__construct($errorCode . ': ' . $message, 0, $previous);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
