<?php

namespace Bitrix\Ldap\Internal\User;

/**
 * @package Bitrix\Ldap\Internal
 * You must not use classes from Internal namespace outside current module.
 */
final class Cache
{
	/**
	 * @var array<int, array>
	 */
	private static $storage = [];

	/**
	 * @param int $userId
	 * @param array $user
	 * @return void
	 */
	public static function set(int $userId, array $user): void
	{
		self::$storage[$userId] = $user;
	}

	/**
	 * @param int $userId
	 * @param string[] $requiredFields
	 * @return array|null
	 */
	public static function get(int $userId, array $requiredFields = []): ?array
	{
		return self::has($userId, $requiredFields) ? self::$storage[$userId] : null;
	}

	/**
	 * @param int $userId
	 * @param string[] $requiredFields
	 * @return bool
	 */
	public static function has(int $userId, array $requiredFields = []): bool
	{
		if (!isset(self::$storage[$userId]))
		{
			return false;
		}

		foreach ($requiredFields as $field)
		{
			if (!array_key_exists($field, self::$storage[$userId]))
			{
				return false;
			}
		}

		return true;
	}

	public static function clear(): void
	{
		self::$storage = [];
	}
}
