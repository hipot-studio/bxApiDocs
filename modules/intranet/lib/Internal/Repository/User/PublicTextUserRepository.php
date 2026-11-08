<?php

namespace Bitrix\Intranet\Internal\Repository\User;

use Bitrix\Main\UserTable;

class PublicTextUserRepository
{
	private static array $usersById = [];
	private static array $loadedUserIds = [];

	public function getByIds(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn(int $userId): bool => $userId > 0,
		)));
		$missingUserIds = array_values(array_filter(
			$userIds,
			static fn(int $userId): bool => !isset(self::$loadedUserIds[$userId]),
		));

		if ($missingUserIds !== [])
		{
			$users = $this->fetchUsers([
				'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'TITLE', 'EMAIL'],
				'filter' => ['@ID' => $missingUserIds],
				'order' => ['ID' => 'ASC'],
			]);
			foreach ($missingUserIds as $userId)
			{
				self::$loadedUserIds[$userId] = true;
			}
			foreach ($users as $user)
			{
				$userId = (int)($user['ID'] ?? 0);
				if ($userId > 0)
				{
					self::$usersById[$userId] = $user;
				}
			}
		}

		$result = [];
		$orderedUserIds = $userIds;
		sort($orderedUserIds, SORT_NUMERIC);
		foreach ($orderedUserIds as $userId)
		{
			if (isset(self::$usersById[$userId]))
			{
				$result[] = self::$usersById[$userId];
			}
		}

		return $result;
	}

	protected function fetchUsers(array $parameters): array
	{
		return UserTable::getList($parameters)->fetchAll();
	}
}
