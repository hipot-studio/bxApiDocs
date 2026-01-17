<?php

namespace Bitrix\Bizproc\Internal\Exception;

use Bitrix\Main\Error;

class ErrorBuilder
{
	public static function buildFromException(Exception $exception): Error
	{
		return new Error(
			$exception->getMessage(),
			$exception->getCode(),
		);
	}

	public static function build(string $message, int|string $code = 0): Error
	{
		return new Error(
			$message,
			$code,
		);
	}
}
