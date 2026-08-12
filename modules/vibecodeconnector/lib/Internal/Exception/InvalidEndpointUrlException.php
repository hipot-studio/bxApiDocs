<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Exception;

use Bitrix\Main\SystemException;

final class InvalidEndpointUrlException extends SystemException
{
	public function __construct(
		string $message = '',
		private readonly ?string $errorCode = null,
		?\Throwable $previous = null,
	)
	{
		parent::__construct($message, 0, '', 0, $previous);
	}

	public function getErrorCode(): ?string
	{
		return $this->errorCode;
	}
}
