<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service;

use Bitrix\Socialnetwork\V2\Internal\Repository\FavoritesRepositoryInterface;

class FavoritesService
{
	public function __construct(
		private readonly FavoritesRepositoryInterface $favoritesRepository,
	)
	{
	}

	/**
	 * @return bool New favorite state (true = added, false = removed).
	 */
	public function switchFavorite(int $groupId, int $userId): bool
	{
		if ($this->favoritesRepository->isFavorite(groupId: $groupId, userId: $userId))
		{
			$this->favoritesRepository->remove(groupId: $groupId, userId: $userId);

			return false;
		}

		$this->favoritesRepository->add(groupId: $groupId, userId: $userId);

		return true;
	}
}
