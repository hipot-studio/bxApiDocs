<?php

namespace Bitrix\Superset\Internal\Support;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\RequestResult;

final class RequestRejection
{
	public const CODE = 'request_rejected';
	private const FALLBACK_MESSAGE = 'The BI request was rejected. Check the request arguments and try again.';
	private const ERROR_TYPES = [
		'SYNTAX_ERROR',
		'COLUMN_DOES_NOT_EXIST_ERROR',
		'TABLE_DOES_NOT_EXIST_ERROR',
		'SCHEMA_DOES_NOT_EXIST_ERROR',
	];

	public static function fromResponse(RequestResult $requestResult, ?array $body): ?Result
	{
		if (ActAs::isTableAccessDenied($body))
		{
			return null;
		}

		$status = $requestResult->getHttpStatus();
		$errors = $body['errors'] ?? null;
		if (!is_array($errors) || $errors === [] || in_array($status, [401, 403, 404], true))
		{
			return null;
		}

		// Only the query error marks a fault of the query: the service refuses a dataset with the status it always
		// did, 500 included. A fault of the query is not a failure of the service and must not move the portal to
		// SUPERSET_STATUS_ERROR.
		$queryError = self::findQueryError($errors);
		if ($queryError !== null)
		{
			$declaredStatus = $status >= HttpStatus::BAD_REQUEST && $status < HttpStatus::INTERNAL_SERVER_ERROR
				? $status
				: HttpStatus::UNPROCESSABLE_ENTITY
			;

			return self::create($queryError, $declaredStatus)
				->setData(['httpStatus' => $declaredStatus, 'requestResult' => $requestResult])
			;
		}

		if ($status < HttpStatus::BAD_REQUEST || $status >= HttpStatus::INTERNAL_SERVER_ERROR)
		{
			return null;
		}

		foreach ($errors as $error)
		{
			if (!is_array($error) || !in_array($error['error_type'] ?? null, self::ERROR_TYPES, true))
			{
				return null;
			}
		}

		$firstError = reset($errors);
		$message = $firstError['message'] ?? null;

		return self::create(is_string($message) ? $message : null, $requestResult->getHttpStatus())
			->setData(['httpStatus' => $requestResult->getHttpStatus(), 'requestResult' => $requestResult])
		;
	}

	/**
	 * A newer BI service keeps message and error_type of a refused query as they were and adds its type and text
	 * under extra.query_error; an earlier one does not, and the errors are then read as before.
	 */
	private static function findQueryError(array $errors): ?string
	{
		foreach ($errors as $error)
		{
			$queryError = is_array($error) && is_array($error['extra'] ?? null)
				? ($error['extra']['query_error'] ?? null)
				: null;
			if (
				is_array($queryError)
				&& in_array($queryError['type'] ?? null, self::ERROR_TYPES, true)
				&& is_string($queryError['message'] ?? null)
			)
			{
				return $queryError['message'];
			}
		}

		return null;
	}

	public static function create(?string $message, int $httpStatus): Result
	{
		$message = trim($message ?? '');
		if ($message === '' || mb_strlen($message) > 500 || strpbrk($message, "<\r\n") !== false)
		{
			$message = self::FALLBACK_MESSAGE;
		}

		return (new Result())->addError(new Error($message, self::CODE))->setData(['httpStatus' => $httpStatus]);
	}
}
