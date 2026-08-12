<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Exception;

use Bitrix\Main\SystemException;

/**
 * Base class for module exceptions that carry a stable, machine-readable error code
 * alongside the human-readable message. Subclasses pin the domain (registration,
 * bot operations, ...) via their class name; the error code identifies the specific
 * failure mode within that domain (e.g. LICENSE_REJECTED, BOT_NOT_INSTALLED).
 */
abstract class CodedSystemException extends SystemException
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
