<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Exception;

use Bitrix\Main\SystemException;

final class IncomingJwtInvalidException extends SystemException
{
	public function __construct(
		string $message = '',
		int $code = 0,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, $code, '', 0, $previous);
	}
}
