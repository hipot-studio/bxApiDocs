<?php

declare(strict_types=1);

namespace Bitrix\Crm\UserField;

use Bitrix\Main\DB;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

final class UserFieldLimitError
{
	public const CODE = 'CRM_USER_FIELD_LIMIT_EXCEEDED';

	private const CORE_CREATE_LIMIT_ERROR_CODE = 'MAIN_USER_FIELD_LIMIT_EXCEEDED';
	private const CORE_CREATE_ERROR_PREFIX = 'Could not create new user field ';

	private const STORAGE_LIMIT_MESSAGE_MARKERS = [
		'Too many columns',
		'Row size too large',
		'tables can have at most 1600 columns',
		'row is too big',
		'too many indexes',
		'too many keys specified',
	];

	public static function getMessage(): string
	{
		return Loc::getMessage('CRM_USER_FIELD_LIMIT_ERROR')
			?? 'The user field was not created because the maximum number of user fields has been reached.'
		;
	}

	public static function getError(): Error
	{
		return new Error(self::getMessage(), self::CODE);
	}

	public static function isCreateLimitError(mixed $error): bool
	{
		if (
			$error instanceof Error
			&& in_array($error->getCode(), [self::CODE, self::CORE_CREATE_LIMIT_ERROR_CODE], true)
		)
		{
			return true;
		}

		if (self::hasAdminExceptionLimitSignal($error))
		{
			return true;
		}

		$message = self::getErrorMessage($error);
		if (!str_starts_with($message, self::CORE_CREATE_ERROR_PREFIX))
		{
			return false;
		}

		return self::hasStorageLimitMarker($message)
			|| ($error instanceof \Throwable && self::hasThrowableStorageLimitMarker($error))
		;
	}

	private static function getErrorMessage(mixed $error): string
	{
		if ($error instanceof \Throwable)
		{
			return $error->getMessage();
		}

		if ($error instanceof Error)
		{
			return $error->getMessage();
		}

		if (is_object($error) && method_exists($error, 'GetString'))
		{
			return (string)$error->GetString();
		}

		return (string)$error;
	}

	private static function hasAdminExceptionLimitSignal(mixed $error): bool
	{
		if (!is_object($error) || !method_exists($error, 'GetMessages'))
		{
			return false;
		}

		foreach ((array)$error->GetMessages() as $message)
		{
			if (!is_array($message))
			{
				continue;
			}

			if (in_array(($message['id'] ?? null), [self::CODE, self::CORE_CREATE_LIMIT_ERROR_CODE], true))
			{
				return true;
			}
		}

		return false;
	}

	private static function hasThrowableStorageLimitMarker(\Throwable $throwable): bool
	{
		do
		{
			if ($throwable instanceof DB\Exception && self::hasStorageLimitMarker($throwable->getDatabaseMessage()))
			{
				return true;
			}

			if (self::hasStorageLimitMarker($throwable->getMessage()))
			{
				return true;
			}

			$throwable = $throwable->getPrevious();
		}
		while ($throwable !== null);

		return false;
	}

	private static function hasStorageLimitMarker(string $message): bool
	{
		foreach (self::STORAGE_LIMIT_MESSAGE_MARKERS as $marker)
		{
			if (stripos($message, $marker) !== false)
			{
				return true;
			}
		}

		return false;
	}

	public static function hasCreateLimitError(iterable $errors): bool
	{
		foreach ($errors as $error)
		{
			if (self::isCreateLimitError($error))
			{
				return true;
			}
		}

		return false;
	}

	public static function getAdminExceptionMessage(): array
	{
		return [
			'id' => self::CODE,
			'text' => self::getMessage(),
		];
	}
}
