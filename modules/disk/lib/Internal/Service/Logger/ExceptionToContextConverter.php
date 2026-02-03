<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Logger;

use Throwable;

class ExceptionToContextConverter
{
	/**
	 * Convert exception into fields for logger context.
	 *
	 * @param Throwable $exception
	 * @return array
	 */
	public static function convert(Throwable $exception): array
	{
		return [
			'exception_message' => $exception->getMessage(),
			'code' => $exception->getCode(),
			'type' => get_class($exception),
			'file' => $exception->getFile(),
			'line' => $exception->getLine(),
		];
	}
}