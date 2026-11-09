<?php

namespace Bitrix\Superset\Internal\Support;

use Bitrix\Superset\Internal\RequestResult;

/**
 * Act-as contract with the Superset fork: the service account names the user a request runs as.
 */
final class ActAs
{
	public const HEADER = 'X-Bx-Act-As';
	public const APPLIED_HEADER = 'X-Bx-Act-As-Applied';

	/** Superset answered without confirming the substitution: it may have run as the service account. */
	public const ERROR_UNSUPPORTED = 'act_as_unsupported';
	public const ERROR_INVALID_LOGIN = 'act_as_invalid_login';

	/** Refusals of the fork: the target user is not allowed (403) or the login is malformed (400). */
	public const FORK_ERROR_DENIED = 'BX_ACT_AS_DENIED';
	public const FORK_ERROR_INVALID = 'BX_ACT_AS_INVALID';

	/** The person is acted as, but the permissions on tables close what the request reads; no table is named. */
	public const TABLE_ACCESS_DENIED = 'table_access_denied';

	/**
	 * @return string|null the act-as refusal type of a Superset error body, null for any other answer
	 */
	public static function findForkRefusal(?array $decodedBody): ?string
	{
		$errors = is_array($decodedBody['errors'] ?? null) ? $decodedBody['errors'] : [];
		foreach ($errors as $error)
		{
			$type = is_array($error) ? ($error['error_type'] ?? null) : null;
			if ($type === self::FORK_ERROR_DENIED || $type === self::FORK_ERROR_INVALID)
			{
				return $type;
			}
		}

		return null;
	}

	public static function isTableAccessDenied(?array $decodedBody): bool
	{
		return ($decodedBody['error_code'] ?? null) === self::TABLE_ACCESS_DENIED;
	}

	public static function isApplied(RequestResult $requestResult, string $login): bool
	{
		return $requestResult->getHeader(self::APPLIED_HEADER) === $login;
	}
}
