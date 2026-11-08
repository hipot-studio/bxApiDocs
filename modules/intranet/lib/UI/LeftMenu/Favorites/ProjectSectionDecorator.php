<?php

declare(strict_types=1);

namespace Bitrix\Intranet\UI\LeftMenu\Favorites;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Internal\Integration\Socialnetwork\FeatureProvider;
use Bitrix\Intranet\Internal\Integration\Socialnetwork\WorkgroupLink;
use Bitrix\Intranet\Internal\Integration\Socialnetwork\WorkgroupSectionProvider;
use Bitrix\Intranet\Internal\Service\LeftMenu\GroupFavoritesService;
use Bitrix\Intranet\UI\LeftMenu\MenuItem;
use Bitrix\Main\Application;

class ProjectSectionDecorator
{
	public function apply(array $items): array
	{
		if (!$this->isNewProjectsOn())
		{
			return [];
		}

		$itemIdsByLink = [];
		foreach ($items as $id => $item)
		{
			if (!$item instanceof MenuItem\ItemUserFavorites)
			{
				continue;
			}

			$link = (string)$item->getLink();
			if ($link === '')
			{
				continue;
			}

			$itemIdsByLink[$link][] = $id;
		}

		if ($itemIdsByLink === [])
		{
			return [];
		}

		$recognizedLinks = [];
		$otherLinks = [];
		foreach (array_keys($itemIdsByLink) as $link)
		{
			if (WorkgroupLink::extractGroupId($link) !== null)
			{
				$recognizedLinks[$link] = true;
			}
			else
			{
				$otherLinks[] = $link;
			}
		}
		$links = [...array_keys($recognizedLinks), ...$otherLinks];
		$linksToInspect = array_slice($links, 0, WorkgroupSectionProvider::MAX_LINKS_TO_INSPECT);
		$overflowLinks = array_fill_keys(
			array_slice($links, WorkgroupSectionProvider::MAX_LINKS_TO_INSPECT),
			true,
		);
		$userId = $this->getCurrentUserId();
		$states = $this->getProvider()->getStatesByLinks($userId, $linksToInspect);

		$itemsToHide = [];
		$goneGroupIds = [];
		foreach ($itemIdsByLink as $link => $ids)
		{
			$state = $states[$link] ?? null;
			if ($state === null)
			{
				if (isset($overflowLinks[$link], $recognizedLinks[$link]))
				{
					foreach ($ids as $id)
					{
						$itemsToHide[] = $items[$id];
					}
				}

				continue;
			}

			if (!$state->isFound)
			{
				$goneGroupIds[$state->groupId] = true;
			}

			foreach ($ids as $id)
			{
				if ($state->isAccessibleProjectSection())
				{
					$items[$id]->markAsProjectSection($state->canonicalLink);
				}
				elseif (!$state->canView)
				{
					$itemsToHide[] = $items[$id];
				}
			}
		}

		$this->forgetGoneContainers($userId, array_keys($goneGroupIds));

		return $itemsToHide;
	}

	private function forgetGoneContainers(int $userId, array $groupIds): void
	{
		if ($userId <= 0 || $groupIds === [])
		{
			return;
		}

		try
		{
			$this->deleteFavorites($userId, $groupIds);
		}
		catch (\Throwable $exception)
		{
			$this->writeToLog($exception);
		}
	}

	protected function deleteFavorites(int $userId, array $groupIds): void
	{
		(new GroupFavoritesService())->deleteFavoritesForGroups($groupIds, $userId);
	}

	protected function writeToLog(\Throwable $exception): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($exception);
	}

	protected function isNewProjectsOn(): bool
	{
		return (new FeatureProvider())->isNewProjectsOn();
	}

	protected function getProvider(): WorkgroupSectionProvider
	{
		return new WorkgroupSectionProvider();
	}

	protected function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}
}
