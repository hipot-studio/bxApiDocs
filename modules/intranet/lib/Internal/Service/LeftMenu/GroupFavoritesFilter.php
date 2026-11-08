<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\LeftMenu;

use Bitrix\Intranet\Internal\Integration\Socialnetwork\WorkgroupLink;

class GroupFavoritesFilter
{
	public function removeGroupsItems(array $items, array $groupIds): array
	{
		$groupIds = array_fill_keys($groupIds, true);
		$changed = false;

		foreach ($items as $key => $item)
		{
			$link = is_array($item) ? ($item['LINK'] ?? null) : null;
			$groupId = is_string($link) && $link !== '' ? $this->extractGroupId($link) : null;
			if ($groupId !== null && isset($groupIds[$groupId]))
			{
				unset($items[$key]);
				$changed = true;
			}
		}

		return ['items' => $items, 'changed' => $changed];
	}

	public function hasGroupItems(array $items, int $groupId): bool
	{
		foreach ($items as $item)
		{
			if ($this->belongsToGroup($item, $groupId))
			{
				return true;
			}
		}

		return false;
	}

	protected function pointsToGroup(string $link, int $groupId): bool
	{
		return WorkgroupLink::pointsToGroup($link, $groupId);
	}

	protected function extractGroupId(string $link): ?int
	{
		return WorkgroupLink::extractGroupId($link);
	}

	private function belongsToGroup(mixed $item, int $groupId): bool
	{
		$link = is_array($item) ? ($item['LINK'] ?? null) : null;

		if (!is_string($link) || $link === '')
		{
			return false;
		}

		return $this->pointsToGroup($link, $groupId);
	}
}
