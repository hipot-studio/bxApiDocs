<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Core\Queue;

use Throwable;

/**
 * The journal keeps its context as JSON, and the properties of a standard exception are not
 * public: an exception passed as an object is encoded into an empty pair and leaves nothing
 * where the reason of the failure should have been. Unfolded here once, every queue journal
 * writes the same four fields and the trail stays readable.
 */
final class LogContext
{
	public static function fromThrowable(Throwable $exception): array
	{
		return [
			'class' => $exception::class,
			'message' => $exception->getMessage(),
			'file' => $exception->getFile(),
			'line' => $exception->getLine(),
		];
	}
}
