<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Service;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Helpers for Rest V3 domain services returning {@see Result}.
 */
final class ServiceResult
{
	public static function accessDenied(string $message = 'Access denied'): Result
	{
		$result = new Result();
		$result->addError(new Error($message, RestErrorCode::ACCESS_DENIED));

		return $result;
	}

	public static function notFound(int $id, string $message = 'Entity not found'): Result
	{
		$result = new Result();
		$result->addError(new Error($message, RestErrorCode::NOT_FOUND, ['id' => $id]));

		return $result;
	}

	/**
	 * @param list<Error>|Error $errors
	 */
	public static function validation(array|Error $errors): Result
	{
		$result = new Result();
		if ($errors instanceof Error)
		{
			$result->addError($errors);
		}
		else
		{
			$result->addErrors($errors);
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function ok(array $data = []): Result
	{
		$result = new Result();
		if ($data !== [])
		{
			$result->setData($data);
		}

		return $result;
	}
}
