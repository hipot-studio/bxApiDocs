<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Helpers for Rest domain services returning {@see Result}.
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

	public static function alreadyExists(int|string $id, string $message = 'Entity already exists'): Result
	{
		$result = new Result();
		$result->addError(new Error($message, RestErrorCode::ALREADY_EXISTS, ['id' => (string)$id]));

		return $result;
	}

	/**
	 * Single error for a domain rejection (CCatalog::CheckFields, CIBlockSection::getLastError).
	 * Such messages arrive glued with `<br>` and are reported as one plain text error.
	 * The rejection belongs to no single request field, so the error carries no code.
	 */
	public static function validationFailed(string $message): Result
	{
		$result = new Result();
		$result->addError(new Error(self::toPlainText($message)));

		return $result;
	}

	/**
	 * Rejection of a named request field: the transport reports the error code as `validation[].field`.
	 *
	 * @see \Bitrix\Rest\V3\Exception\Validation\ValidationException::output()
	 */
	public static function fieldValidationFailed(string $field, string $message): Result
	{
		$result = new Result();
		$result->addError(new Error(self::toPlainText($message), $field));

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
	public static function success(array $data = []): Result
	{
		$result = new Result();
		if ($data !== [])
		{
			$result->setData($data);
		}

		return $result;
	}

	private static function toPlainText(string $message): string
	{
		$text = preg_replace('#<br\s*/?>#i', ' ', $message) ?? $message;
		$text = strip_tags($text);
		$text = preg_replace('/\s+/u', ' ', $text) ?? $text;

		return trim($text);
	}
}
