<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Exception;

final class OpenAppException extends \RuntimeException
{
	public const CODE_EXTERNAL_ID_MISSING = 'EXTERNAL_ID_MISSING';
	public const CODE_ENDPOINT_UNAVAILABLE = 'ENDPOINT_UNAVAILABLE';

	public function __construct(
		string $message,
		private readonly string $errorCode,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
