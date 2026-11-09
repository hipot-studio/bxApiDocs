<?php

namespace Bitrix\BIConnector\Access\Table;

/**
 * Signature of the Superset login the permission set is asked for.
 *
 * The login reaches the portal through the engine, which takes it from Superset on trust. The signature binds
 * it to the BI key of the portal: lowercase hex of HMAC-SHA256 keyed with the key over "bx-user:" + login.
 * Superset computes it next to the login and the engine checks it before its own cache, so a forged login
 * does not get past the engine; the portal checks it again as the second line. The prefix keeps the message
 * apart from the one the administrator key of Superset is computed over with the same key.
 *
 * Four code bases compute this value - the portal, Superset, the engine and its tests - so the formula is
 * a contract and not an implementation detail.
 */
final class LoginSignature
{
	private const MESSAGE_PREFIX = 'bx-user:';

	public static function sign(string $key, string $login): string
	{
		return hash_hmac('sha256', self::MESSAGE_PREFIX . $login, $key);
	}

	/**
	 * @param string $key BI key of the portal the request was let in with.
	 * @param string $login
	 * @param string|null $signature As it arrived; anything that is not the exact lowercase hex is invalid.
	 * @return bool
	 */
	public static function isValid(string $key, string $login, ?string $signature): bool
	{
		if ($key === '' || $signature === null || $signature === '')
		{
			return false;
		}

		return hash_equals(self::sign($key, $login), $signature);
	}
}
