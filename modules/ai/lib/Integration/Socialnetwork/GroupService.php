<?php

declare(strict_types=1);

namespace Bitrix\AI\Integration\Socialnetwork;

use Bitrix\Main\LoaderException;
use Bitrix\Socialnetwork\Internals\Registry\UserRegistry;
use Bitrix\Main\Loader;

class GroupService
{
	protected bool $hasModule = false;
	protected bool $hasCheckModule = false;

	public function __construct(
		protected GroupRepository $groupRepository
	)
	{
	}

	/**
	 * Returns the subset of the passed ids whose group is active and not closed.
	 *
	 * @param int[] $groupIds
	 * @return list<int>
	 * @throws LoaderException Callers drop every access code absent from the result, so a silent empty list would wipe the group sharings.
	 */
	public function getExistingGroupIds(array $groupIds): array
	{
		$ids = $this->normalizeIds($groupIds);
		if (empty($ids))
		{
			return [];
		}

		if (!$this->hasSocialnetwork())
		{
			throw new LoaderException('Module socialnetwork is not available, group existence cannot be checked.');
		}

		return $this->groupRepository->getExistingIds($ids);
	}

	/**
	 * Returns the subset of the passed ids whose group is not visible.
	 *
	 * @param int[] $groupIds
	 * @return list<int>
	 */
	public function getNotVisibleGroupIds(array $groupIds): array
	{
		$ids = $this->normalizeIds($groupIds);
		if (empty($ids))
		{
			return [];
		}

		if (!$this->hasSocialnetwork())
		{
			return [];
		}

		return $this->groupRepository->getNotVisibleIds($ids);
	}

	public function getGroupIdsForUser($userId): array
	{
		if (!$this->hasSocialnetwork())
		{
			return [];
		}

		return array_keys(UserRegistry::getInstance($userId)->getUserGroups());
	}

	protected function hasSocialnetwork(): bool
	{
		if (!$this->hasCheckModule)
		{
			$this->hasCheckModule = true;
			try
			{
				$this->hasModule = Loader::includeModule('socialnetwork');
			}
			catch (LoaderException $exception)
			{
				return false;
			}
		}

		return $this->hasModule;
	}

	/**
	 * @param int[] $ids
	 * @return list<int>
	 */
	private function normalizeIds(array $ids): array
	{
		$result = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$result[$id] = $id;
			}
		}

		return array_values($result);
	}
}
