<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal;

use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Throwable;

class Logger implements LoggerInterface
{
	public function logValidationErrorWarning(ErrorCollection $errors): void
	{
		LogFacade::logValidationErrors($errors);
	}

	public function logError(null|string|Throwable|Error $error, string $wrapperClass = SystemException::class): void
	{
		if ($error === null)
		{
			return;
		}

		LogFacade::handle($error, $wrapperClass);
	}

	public function logWarning(mixed $data, string $marker = self::DEFAULT_MARKER): void
	{
		if ($data instanceof Throwable)
		{
			// The generic branch renders a non-scalar with var_export(), and for a throwable that
			// means the whole object graph its trace holds - an ORM exception drags the query,
			// the connection and the application along and eats hundreds of megabytes.
			LogFacade::logThrowable($data, $marker);

			return;
		}

		LogFacade::log($data, $marker);
	}
}
