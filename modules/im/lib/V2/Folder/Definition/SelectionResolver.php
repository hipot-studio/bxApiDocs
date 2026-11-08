<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Definition;

use Bitrix\Im\V2\Recent\Query\RecentFilter;
use Bitrix\Im\V2\Recent\Query\RecentParams;
use Bitrix\Im\V2\Recent\RecentProvider;

/**
 * Executes a {@see Selection} against the shared recent query path and returns
 * the matched chat ids. Keeps query execution out of the {@see Selection}
 * value object: Selection describes the composition, this resolver runs it -
 * for the counter (ALG-02) via {@see self::resolveRootChatIds()} and for pins
 * (ALG-03) via {@see self::matchingChatIds()}.
 *
 * The pin path is live: {@see self::matchingChatIds()} runs on every save of a composition
 * ({@see \Bitrix\Im\V2\Folder\Source\FolderSourceService::updateSources()}) - once for the sources
 * switched off and once for the ones left enabled. The counter path is not -
 * {@see self::resolveRootChatIds()} has no production caller while the counter is computed on the
 * client.
 */
final class SelectionResolver
{
	public function __construct(
		private readonly RecentProvider $recentProvider,
	)
	{
	}

	/**
	 * Root chat ids of the composition (parentChatId == 0), deduplicated by chat id.
	 *
	 * @return int[]
	 */
	public function resolveRootChatIds(Selection $selection, int $userId): array
	{
		return $this->fetchChatIds($userId, $selection->rootFramed());
	}

	/**
	 * Chat ids matching the composition as its branches define it, deduplicated
	 * by chat id.
	 *
	 * @param int[] $withinChatIds candidates the read is narrowed to; the empty set means the
	 *        whole recent list of the user. A caller that already holds the candidates (the pin
	 *        shadows of a folder) passes them here instead of scanning the sections. They must
	 *        not go into {@see SelectionBranch::$chatIds} - there they would REPLACE the type
	 *        condition of the branch instead of narrowing it, and the source would be lost.
	 * @return int[]
	 */
	public function matchingChatIds(Selection $selection, int $userId, array $withinChatIds = []): array
	{
		return $this->fetchChatIds($userId, $selection, $withinChatIds);
	}

	/**
	 * @param int[] $withinChatIds
	 * @return int[]
	 */
	private function fetchChatIds(int $userId, Selection $selection, array $withinChatIds = []): array
	{
		if ($userId <= 0 || $selection->isEmpty())
		{
			return [];
		}

		$recent = $this->recentProvider->getList(
			new RecentParams(filter: new RecentFilter(
				userId: $userId,
				chatIds: $withinChatIds,
				selection: $selection,
			)),
		);

		$chatIds = [];
		foreach ($recent as $item)
		{
			$chatIds[$item->getChatId()] = $item->getChatId();
		}

		return array_values($chatIds);
	}
}
