<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Socialnetwork\WorkgroupFavoritesTable;

class FavoritesRepository implements FavoritesRepositoryInterface
{
	public function getFavoriteGroupIds(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$rows = WorkgroupFavoritesTable::getList([
			'filter' => ['=USER_ID' => $userId],
			'select' => ['GROUP_ID'],
		])->fetchAll();

		return array_map(
			static fn(array $row): int => (int)$row['GROUP_ID'],
			$rows,
		);
	}

	public function getFavoriteFlags(array $groupIds, int $userId): array
	{
		if (empty($groupIds) || $userId <= 0)
		{
			return [];
		}

		$result = [];

		$rows = WorkgroupFavoritesTable::getList([
			'select' => ['GROUP_ID'],
			'filter' => [
				'=GROUP_ID' => $groupIds,
				'=USER_ID' => $userId,
			],
		]);

		foreach ($rows as $row)
		{
			$result[(int)$row['GROUP_ID']] = true;
		}

		return $result;
	}

	public function isFavorite(int $groupId, int $userId): bool
	{
		$row = WorkgroupFavoritesTable::getList([
			'select' => ['GROUP_ID'],
			'filter' => [
				'=GROUP_ID' => $groupId,
				'=USER_ID' => $userId,
			],
			'limit' => 1,
		])->fetch();

		return $row !== false;
	}

	public function add(int $groupId, int $userId): void
	{
		WorkgroupFavoritesTable::set([
			'GROUP_ID' => $groupId,
			'USER_ID' => $userId,
		]);
	}

	public function remove(int $groupId, int $userId): void
	{
		WorkgroupFavoritesTable::delete([
			'GROUP_ID' => $groupId,
			'USER_ID' => $userId,
		]);
	}
}
