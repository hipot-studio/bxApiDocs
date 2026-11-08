<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Internals\Task;

final class UpdateEventSuppression
{
	private const PARAMETER = 'SKIP_TASK_UPDATE_EVENT';

	private static ?object $token = null;

	public static function getParameters(): array
	{
		return [self::PARAMETER => self::getToken()];
	}

	public static function extract(array &$parameters): bool
	{
		$suppressed = ($parameters[self::PARAMETER] ?? null) === self::getToken();
		unset($parameters[self::PARAMETER]);

		return $suppressed;
	}

	private static function getToken(): object
	{
		return self::$token ??= new \stdClass();
	}
}
