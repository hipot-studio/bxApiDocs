<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal;

use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\SystemException;
use Throwable;

interface LoggerInterface
{
	public const DEFAULT_MARKER = 'DEBUG_TASKS';
	public const VALIDATION_MARKER = 'TASKS_VALIDATION_DEBUG';
	public const TASKS_NOT_EXISTS_MARKER = 'TASKS_NOT_EXISTS';

	public function logValidationErrorWarning(ErrorCollection $errors): void;

	public function logError(null|string|Throwable|Error $error, string $wrapperClass = SystemException::class): void;

	public function logWarning(mixed $data, string $marker = self::DEFAULT_MARKER): void;
}
