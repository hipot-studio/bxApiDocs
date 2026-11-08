<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Permission\Rule;

use Bitrix\Socialnetwork\Space\Member;
use Bitrix\Socialnetwork\UserToGroupTable;

final class GroupRelationCache
{
	private static array $relations = [];

	public static function get(int $groupId, int $userId): ?Member
	{
		$key = self::getKey($groupId, $userId);

		if (!array_key_exists($key, self::$relations))
		{
			self::$relations[$key] = UserToGroupTable::query()
				->setSelect(['ID', 'ROLE', 'USER_ID', 'GROUP_ID', 'INITIATED_BY_TYPE', 'INITIATED_BY_USER_ID', 'AUTO_MEMBER'])
				->where('GROUP_ID', $groupId)
				->where('USER_ID', $userId)
				->fetchObject()
			;
		}

		return self::$relations[$key];
	}

	public static function invalidate(int $groupId, int $userId): void
	{
		unset(self::$relations[self::getKey($groupId, $userId)]);
	}

	private static function getKey(int $groupId, int $userId): string
	{
		return $groupId . '_' . $userId;
	}
}
