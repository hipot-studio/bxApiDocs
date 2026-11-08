<?php

namespace Bitrix\BIConnector\Integration\Superset;

/**
 * Derives the Superset account login of a portal user.
 *
 * The only place that knows the rule. Values are part of the contract with Superset: it identifies
 * the employee by this login, so the value has to stay derivable from USER_ID alone.
 *
 * SupersetUserTable derives the login itself on both write paths and drops whatever the caller
 * passed, so a login of another employee cannot reach the column from the outside.
 */
final class SupersetUserLogin
{
	private const LOGIN_LENGTH = 16;
	private const EMAIL_DOMAIN = '@bitrix.info';

	public static function getLogin(int $userId): string
	{
		return substr(hash('sha1', (string)$userId), 0, self::LOGIN_LENGTH);
	}

	public static function getEmail(string $login): string
	{
		return $login . self::EMAIL_DOMAIN;
	}
}
