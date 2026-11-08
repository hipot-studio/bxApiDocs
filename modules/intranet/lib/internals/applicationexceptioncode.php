<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internals;

final class ApplicationExceptionCode
{
	public const INVITATION_PUBLIC_CODES = ['EMAIL_ALIAS_NOT_ALLOWED'];

	/**
	 * Returns the position from which application exceptions of the next call are recorded.
	 * Take it right before the call: the current exception, if any, is moved to the stack
	 * by the first reset inside the call and does not belong to it.
	 */
	public static function getStackOffset(): int
	{
		global $APPLICATION;

		if (!is_object($APPLICATION))
		{
			return 0;
		}

		$stackSize = is_array($APPLICATION->ERROR_STACK ?? null) ? count($APPLICATION->ERROR_STACK) : 0;

		return $APPLICATION->GetException() instanceof \CApplicationException ? $stackSize + 1 : $stackSize;
	}

	/**
	 * Returns the code of the latest application exception recorded since the offset
	 * whose text is part of the error message.
	 * CUser::CheckFields resets exceptions of OnBeforeUserAdd handlers, so they are searched in CMain::$ERROR_STACK.
	 * The search stops at the latest matching exception: its code is returned only if it is in the allowed list.
	 *
	 * @param string[] $allowedCodes
	 */
	public static function findByErrorMessage(string $errorMessage, int $stackOffset, array $allowedCodes): ?string
	{
		global $APPLICATION;

		if ($errorMessage === '' || !is_object($APPLICATION))
		{
			return null;
		}

		$stack = is_array($APPLICATION->ERROR_STACK ?? null) ? $APPLICATION->ERROR_STACK : [];
		$exceptions = array_slice($stack, max(0, $stackOffset));
		$exceptions[] = $APPLICATION->GetException();

		foreach (array_reverse($exceptions) as $exception)
		{
			if (!$exception instanceof \CApplicationException)
			{
				continue;
			}

			$message = (string)$exception->GetString();
			if ($message === '' || !str_contains($errorMessage, $message))
			{
				continue;
			}

			$code = $exception->GetID();

			return is_string($code) && in_array($code, $allowedCodes, true) ? $code : null;
		}

		return null;
	}
}
