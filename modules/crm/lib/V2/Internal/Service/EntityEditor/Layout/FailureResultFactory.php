<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class FailureResultFactory
{
	public static function normalizePartialMutationFailure(Result $result, bool $mutationWasApplied = false): Result
	{
		if ($mutationWasApplied && !$result->isSuccess())
		{
			$result = (new Result())->addError(new Error(
				'The CRM entity editor layout operation was only partially applied.',
				ErrorCode::STORAGE_FAILED,
				['partialStatePossible' => true],
			));
		}

		foreach ($result->getErrors() as $error)
		{
			if (
				$error->getCode() === ErrorCode::STORAGE_FAILED
				&& ($error->getCustomData()['partialStatePossible'] ?? false) === true
			)
			{
				return self::consistencyFailure();
			}
		}

		return $result;
	}

	public static function unsupportedScope(): Result
	{
		return self::failure(
			'Custom CRM entity editor layouts require the custom scope gateway.',
			ErrorCode::UNSUPPORTED_VARIANT,
		);
	}

	public static function accessDenied(): Result
	{
		return self::failure(
			'Access to the CRM entity editor layout is denied.',
			ErrorCode::ACCESS_DENIED,
		);
	}

	public static function layoutNotFound(): Result
	{
		return self::failure(
			'The CRM entity editor layout was not found.',
			ErrorCode::LAYOUT_NOT_FOUND,
		);
	}

	public static function consistencyFailure(): Result
	{
		return self::failure(
			'The CRM entity editor layout operation was only partially applied.',
			ErrorCode::CONSISTENCY_FAILURE,
		);
	}

	private static function failure(string $message, string $code): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
