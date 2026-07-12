<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

interface FavoritesRepositoryInterface
{
	/**
	 * @return int[]
	 */
	public function getFavoriteGroupIds(int $userId): array;

	/**
	 * @param int[] $groupIds
	 * @return array<int, bool>
	 */
	public function getFavoriteFlags(array $groupIds, int $userId): array;

	public function isFavorite(int $groupId, int $userId): bool;

	public function add(int $groupId, int $userId): void;

	public function remove(int $groupId, int $userId): void;
}
