<?php

namespace Bitrix\Ldap\Internal;

/**
 * @package Bitrix\Ldap\Internal
 * You must not use classes from Internal namespace outside current module.
 */
final class LoginPrefixResolver
{
	/**
	 * @param string $login
	 * @return array{0: string, 1: string}
	 */
	public static function resolve(string $login): array
	{
		$prefix = '';
		$pos = mb_strpos($login, "\\");

		if ($pos > 0)
		{
			$prefix = mb_substr($login, 0, $pos);
			$login = mb_substr($login, $pos + 1);
		}

		return [ $login, $prefix ];
	}
}
