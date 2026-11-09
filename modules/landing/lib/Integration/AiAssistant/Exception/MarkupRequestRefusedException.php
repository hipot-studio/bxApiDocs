<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Exception;

/**
 * A markup file intake request refused for a reason the caller tells apart by a string code.
 * It stays a RuntimeException: the tool maps it as every other failure, by the message.
 */
class MarkupRequestRefusedException extends \RuntimeException
{
	public const ACCESS_DENIED = 'ACCESS_DENIED';
	public const UPLOAD_NOT_FOUND = 'MARKUP_UPLOAD_NOT_FOUND';
	public const DISABLED = 'MARKUP_DISABLED';
	public const BAD_REQUEST = 'MARKUP_BAD_REQUEST';
	public const HASH_MISMATCH = 'MARKUP_HASH_MISMATCH';
	public const FILE_NOT_FOUND = 'MARKUP_FILE_NOT_FOUND';
	public const MODE_DISABLED = AiSiteModeDisabledException::ERROR_CODE;

	public function __construct(
		string $message,
		private readonly string $errorCode,
		?\Throwable $previous = null,
	)
	{
		parent::__construct($message, 0, $previous);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
