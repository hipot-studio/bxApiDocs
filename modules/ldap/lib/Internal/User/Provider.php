<?php

namespace Bitrix\Ldap\Internal\User;

use Bitrix\Main\UserTable;
use CTimeZone;

/**
 * @package Bitrix\Ldap\Internal
 * You must not use classes from Internal namespace outside current module.
 */
final class Provider
{
	private static array $defaultSelectedFields = ['ID', 'LOGIN', 'ACTIVE', 'TIMESTAMP_X', 'PERSONAL_PHOTO'];

	public static function getByServerId(int $serverId): array
	{
		CTimeZone::Disable();
		$users = UserTable::query()
			->setSelect(self::$defaultSelectedFields)
			->where('EXTERNAL_AUTH_ID', 'LDAP#' . $serverId)
			->exec();
		CTimeZone::Enable();

		$result = [];
		while ($user = $users->fetchRaw())
		{
			Cache::set((int)$user['ID'], $user);
			$result[mb_strtolower($user['LOGIN'])] = $user;
		}

		return $result;
	}

	public static function getById(int $userId): ?array
	{
		CTimeZone::Disable();
		$users = UserTable::query()
			->setSelect(self::$defaultSelectedFields)
			->where('ID', $userId)
			->setLimit(1)
			->exec();
		CTimeZone::Enable();

		if ($user = $users->fetchRaw())
		{
			Cache::set((int)$user['ID'], $user);
			return $user;
		}

		return null;
	}

	public static function getByLogins(array $logins): array
	{
		CTimeZone::Disable();
		$users = UserTable::query()
			->setSelect(self::$defaultSelectedFields)
			->whereIn('LOGIN', $logins)
			->exec();
		CTimeZone::Enable();

		$result = [];
		while ($user = $users->fetchRaw())
		{
			Cache::set((int)$user['ID'], $user);
			$result[mb_strtolower($user['LOGIN'])] = $user;
		}

		return $result;
	}
}
