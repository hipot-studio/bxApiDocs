<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\LeftMenu;

use Bitrix\Intranet\Internal\Integration\Socialnetwork\Dto\WorkgroupSectionState;
use Bitrix\Intranet\Internal\Integration\Socialnetwork\FeatureProvider;
use Bitrix\Intranet\Internal\Integration\Socialnetwork\WorkgroupLink;
use Bitrix\Intranet\Internal\Integration\Socialnetwork\WorkgroupSectionProvider;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\SystemException;

class GroupFavoritesService
{
	public const MAX_RECOGNIZED_GROUPS = 100;

	private const OPTION_CATEGORY = 'intranet';
	private const OPTION_NAME_PREFIX = 'left_menu_standard_items_';
	private const SORT_OPTION_NAME_PREFIX = 'left_menu_sorted_items_';
	private const USER_OPTION_BATCH_SIZE = 300;
	private ?array $siteIds = null;

	public function __construct(
		private readonly GroupFavoritesFilter $filter = new GroupFavoritesFilter(),
		private readonly FeatureProvider $featureProvider = new FeatureProvider(),
		private readonly WorkgroupSectionProvider $sectionProvider = new WorkgroupSectionProvider(),
	)
	{
	}

	public function hasFavorites(int $groupId, int $userId): bool
	{
		if ($groupId <= 0 || $userId <= 0)
		{
			return false;
		}

		return $this->hasFavoritesOnSites($groupId, $userId, $this->getSiteIds());
	}

	public function filterFavoritePairs(array $pairs): array
	{
		$uniquePairs = [];
		foreach ($pairs as $pair)
		{
			$groupId = (int)($pair['groupId'] ?? 0);
			$userId = (int)($pair['userId'] ?? 0);
			if ($groupId > 0 && $userId > 0)
			{
				$uniquePairs[$groupId . ':' . $userId] = compact('groupId', 'userId');
			}
		}

		$itemsByUser = $this->loadFavoriteItemsForUsers(array_column($uniquePairs, 'userId'));
		$result = [];
		foreach ($uniquePairs as $pair)
		{
			if ($this->hasFavoritesInLoadedItems($pair['groupId'], $itemsByUser[$pair['userId']] ?? []))
			{
				$result[] = $pair;
			}
		}

		return $result;
	}

	private function hasFavoritesOnSites(int $groupId, int $userId, array $siteIds): bool
	{
		foreach ($siteIds as $siteId)
		{
			if ($this->filter->hasGroupItems($this->getItems($siteId, $userId), $groupId))
			{
				return true;
			}
		}

		return false;
	}

	private function hasFavoritesInLoadedItems(int $groupId, array $itemsBySite): bool
	{
		foreach ($itemsBySite as $items)
		{
			if ($this->filter->hasGroupItems($items, $groupId))
			{
				return true;
			}
		}

		return false;
	}

	private function loadFavoriteItemsForUsers(array $userIds): array
	{
		$userIds = $this->normalizeUserIds($userIds);
		if ($userIds === [])
		{
			return [];
		}

		$commonItemsBySite = [];
		$userItemsBySite = [];
		foreach ($this->loadFavoriteOptionRows($this->getSiteIds(), $userIds) as $row)
		{
			$siteId = (string)($row['SITE_ID'] ?? '');
			$userId = (int)($row['USER_ID'] ?? 0);
			$items = $this->deserializeFavoriteItems($row['VALUE'] ?? null);
			if ($siteId === '')
			{
				continue;
			}

			if ($userId === 0 && ($row['COMMON'] ?? null) === 'Y')
			{
				$commonItemsBySite[$siteId] = $items;
			}
			elseif ($userId > 0)
			{
				$userItemsBySite[$userId][$siteId] = $items;
			}
		}

		$result = [];
		foreach ($userIds as $userId)
		{
			foreach ($this->getSiteIds() as $siteId)
			{
				$result[$userId][$siteId] = array_key_exists($siteId, $userItemsBySite[$userId] ?? [])
					? $userItemsBySite[$userId][$siteId]
					: ($commonItemsBySite[$siteId] ?? []);
			}
		}

		return $result;
	}

	protected function loadFavoriteOptionRows(array $siteIds, array $userIds): iterable
	{
		foreach ($siteIds as $siteId)
		{
			$optionName = $this->getOptionName($siteId);
			$userIdBatches = array_chunk($userIds, self::USER_OPTION_BATCH_SIZE);
			foreach ($userIdBatches as $batchIndex => $userIdBatch)
			{
				if ($batchIndex === 0)
				{
					$userIdBatch[] = 0;
				}
				$query = new SqlExpression(
					'SELECT USER_ID, COMMON, VALUE FROM b_user_option'
					. ' WHERE CATEGORY = ?s AND NAME = ?s AND USER_ID IN (?@)',
					self::OPTION_CATEGORY,
					$optionName,
					$userIdBatch,
				);
				$rows = Application::getConnection()->query($query->compile());
				while ($row = $rows->fetch())
				{
					yield ['SITE_ID' => $siteId] + $row;
				}
			}
		}
	}

	private function deserializeFavoriteItems(mixed $value): array
	{
		if (!is_string($value))
		{
			return [];
		}

		$items = unserialize($value, ['allowed_classes' => false]);

		return is_array($items) ? $items : [];
	}

	private function normalizeUserIds(array $userIds): array
	{
		$normalized = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$normalized[$userId] = $userId;
			}
		}

		return array_values($normalized);
	}

	public function canAddLink(array $items, string $link, int $userId, ?WorkgroupSectionState $linkState): bool
	{
		$newGroupId = WorkgroupLink::extractGroupId($link);
		if ($newGroupId === null || !$this->isProjectQuotaEnabled())
		{
			return true;
		}

		if ($linkState !== null && !$linkState->canView)
		{
			return false;
		}

		if ($this->isOrdinaryGroup($linkState))
		{
			return true;
		}

		$linksByGroupId = [$newGroupId => $link];
		foreach ($items as $item)
		{
			$itemLink = is_array($item) ? ($item['LINK'] ?? null) : null;
			$groupId = is_string($itemLink) ? WorkgroupLink::extractGroupId($itemLink) : null;
			if ($groupId === $newGroupId)
			{
				return true;
			}
			if ($groupId !== null)
			{
				$linksByGroupId[$groupId] = $itemLink;
				if (count($linksByGroupId) > WorkgroupSectionProvider::MAX_LINKS_TO_INSPECT)
				{
					return false;
				}
			}
		}

		$existingLinksByGroupId = $linksByGroupId;
		unset($existingLinksByGroupId[$newGroupId]);
		$states = $existingLinksByGroupId !== []
			? $this->getSectionStates($userId, array_values($existingLinksByGroupId))
			: [];
		if ($linkState !== null)
		{
			$states[$link] = $linkState;
		}
		$limitedGroupIds = [];
		foreach ($linksByGroupId as $groupId => $groupLink)
		{
			$state = $states[$groupLink] ?? null;
			if (!$this->isOrdinaryGroup($state))
			{
				$limitedGroupIds[$groupId] = true;
			}
		}

		return count($limitedGroupIds) <= self::MAX_RECOGNIZED_GROUPS;
	}

	protected function isProjectQuotaEnabled(): bool
	{
		return $this->featureProvider->isNewProjectsOn();
	}

	protected function getSectionStates(int $userId, array $links): array
	{
		return $this->sectionProvider->getStatesByLinks($userId, $links);
	}

	public function resolveState(int $userId, string $link): ?WorkgroupSectionState
	{
		if ($userId <= 0 || $link === '' || !$this->isProjectQuotaEnabled())
		{
			return null;
		}

		return $this->sectionProvider->getStateByLink($userId, $link);
	}

	private function isOrdinaryGroup(?WorkgroupSectionState $state): bool
	{
		return $state !== null && $state->isFound && $state->canView && !$state->isNewProject;
	}

	public function deleteFavoritesForGroups(array $groupIds, int $userId): bool
	{
		$plan = $this->getGroupsDeletionPlan($groupIds, $userId);
		$this->applyDeletionPlan($userId, $plan);

		return $plan !== [];
	}

	private function getGroupsDeletionPlan(array $groupIds, int $userId): array
	{
		$groupIds = $this->normalizeGroupIds($groupIds);
		if ($groupIds === [] || $userId <= 0)
		{
			return [];
		}

		$plan = [];
		foreach ($this->getSiteIds() as $siteId)
		{
			$items = $this->getItems($siteId, $userId);
			$result = $this->filter->removeGroupsItems($items, $groupIds);
			$remainingItems = $result['items'];
			$favoriteItemIds = [];
			foreach ($groupIds as $groupId)
			{
				$favoriteItemIds = array_merge($favoriteItemIds, WorkgroupLink::getFavoriteItemIds($groupId));
			}

			$sortedItems = $this->removeSortedItemReferences(
				$this->getSortedItems($siteId, $userId),
				array_merge($this->getRemovedItemIds($items, $remainingItems), $favoriteItemIds),
			);
			if ($result['changed'] || $sortedItems !== null)
			{
				$plan[$siteId] = [
					'items' => $remainingItems,
					'sortedItems' => $sortedItems,
				];
			}
		}

		return $plan;
	}

	private function normalizeGroupIds(array $groupIds): array
	{
		$normalized = [];
		foreach ($groupIds as $groupId)
		{
			$groupId = (int)$groupId;
			if ($groupId > 0)
			{
				$normalized[$groupId] = $groupId;
			}
		}

		return array_values($normalized);
	}

	private function applyDeletionPlan(int $userId, array $plan): void
	{
		foreach ($plan as $siteId => $sitePlan)
		{
			if ($sitePlan['sortedItems'] !== null)
			{
				$this->saveSortedItems($siteId, $userId, $sitePlan['sortedItems']);
			}

			$this->saveItems($siteId, $userId, $sitePlan['items']);
		}
	}

	public function saveFavorites(string $siteId, int $userId, array $items): void
	{
		$this->saveItems($siteId, $userId, $items);
	}

	public function deleteFavoritesForSite(string $siteId, int $userId): void
	{
		if (!\CUserOptions::DeleteOption(self::OPTION_CATEGORY, $this->getOptionName($siteId), false, $userId))
		{
			throw new SystemException('Could not delete the left menu favorites option.');
		}
	}

	public function deleteAllFavoritesForSite(string $siteId): void
	{
		if (!\CUserOptions::DeleteOptionsByName(self::OPTION_CATEGORY, $this->getOptionName($siteId)))
		{
			throw new SystemException('Could not delete the left menu favorites options.');
		}
	}

	protected function getSiteIds(): array
	{
		if ($this->siteIds !== null)
		{
			return $this->siteIds;
		}

		$this->siteIds = $this->loadSiteIds();

		return $this->siteIds;
	}

	protected function loadSiteIds(): array
	{
		$siteIds = [];
		$sites = \CSite::getList();

		while ($site = $sites->Fetch())
		{
			$siteIds[] = (string)$site['LID'];
		}

		return $siteIds;
	}

	protected function getItems(string $siteId, int $userId): array
	{
		$items = \CUserOptions::GetOption(self::OPTION_CATEGORY, $this->getOptionName($siteId), null, $userId);

		return is_array($items) ? $items : [];
	}

	protected function saveItems(string $siteId, int $userId, array $items): void
	{
		if ($items === [])
		{
			$this->deleteFavoritesForSite($siteId, $userId);

			return;
		}

		if (!\CUserOptions::SetOption(self::OPTION_CATEGORY, $this->getOptionName($siteId), $items, false, $userId))
		{
			throw new SystemException('Could not save the left menu favorites option.');
		}
	}

	protected function getSortedItems(string $siteId, int $userId): ?array
	{
		$items = \CUserOptions::GetOption(
			self::OPTION_CATEGORY,
			self::SORT_OPTION_NAME_PREFIX . $siteId,
			null,
			$userId,
		);

		return is_array($items) ? $items : null;
	}

	protected function saveSortedItems(string $siteId, int $userId, array $items): void
	{
		if (!\CUserOptions::SetOption(
			self::OPTION_CATEGORY,
			self::SORT_OPTION_NAME_PREFIX . $siteId,
			$items,
			false,
			$userId,
		))
		{
			throw new SystemException('Could not save the left menu sorted items option.');
		}
	}

	private function removeSortedItemReferences(?array $sortedItems, array $removedItemIds): ?array
	{
		if ($sortedItems === null || $removedItemIds === [])
		{
			return null;
		}

		$removedItemIds = array_fill_keys($removedItemIds, true);
		$changed = false;
		foreach (['show', 'hide'] as $section)
		{
			if (isset($sortedItems[$section]) && is_array($sortedItems[$section]))
			{
				$this->removeReferences($sortedItems[$section], $removedItemIds, $changed);
			}
		}

		return $changed ? $sortedItems : null;
	}

	private function removeReferences(array &$items, array $removedItemIds, bool &$changed): void
	{
		foreach ($items as $key => &$item)
		{
			if (is_array($item))
			{
				if (!array_key_exists('group_id', $item))
				{
					$this->removeReferences($item, $removedItemIds, $changed);
				}

				continue;
			}

			if ((is_string($item) || is_int($item)) && isset($removedItemIds[(string)$item]))
			{
				unset($items[$key]);
				$changed = true;
			}
		}
		unset($item);
	}

	private function getRemovedItemIds(array $items, array $remainingItems): array
	{
		$removedIds = [];
		foreach (array_diff_key($items, $remainingItems) as $key => $item)
		{
			$id = is_array($item) ? ($item['ID'] ?? $key) : $key;
			if (is_string($id) || is_int($id))
			{
				$removedIds[] = (string)$id;
			}
		}

		return array_values(array_unique($removedIds));
	}

	private function getOptionName(string $siteId): string
	{
		return self::OPTION_NAME_PREFIX . $siteId;
	}
}
