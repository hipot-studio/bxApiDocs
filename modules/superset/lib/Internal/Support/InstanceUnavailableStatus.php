<?php

namespace Bitrix\Superset\Internal\Support;

use Bitrix\Main;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\RequestResult;

/**
 * Keeps the "instance unavailable" status (555) visible in errors of composite operations,
 * so callers can tell a sleeping instance from a regular failure.
 */
final class InstanceUnavailableStatus
{
	public static function createError(string $message, RequestResult $requestResult): Main\Error
	{
		if ($requestResult->getHttpStatus() === HttpStatus::DEACTIVATED_INSTANCE)
		{
			return new Main\Error($message, self::getCode());
		}

		return new Main\Error($message);
	}

	public static function isReportedBy(Main\Result $result): bool
	{
		foreach ($result->getErrors() as $error)
		{
			if ((string)$error->getCode() === self::getCode())
			{
				return true;
			}
		}

		return false;
	}

	public static function createWrappingError(string $message, Main\Result $inner): Main\Error
	{
		if (self::isReportedBy($inner))
		{
			return new Main\Error($message, self::getCode());
		}

		return new Main\Error($message);
	}

	private static function getCode(): string
	{
		return (string)HttpStatus::DEACTIVATED_INSTANCE;
	}
}
