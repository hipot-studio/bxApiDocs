<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Folder\Error\FolderError;
use Bitrix\Im\V2\Folder\Pin\FolderPinState;
use Bitrix\Im\V2\Folder\Pin\PinProvider;
use Bitrix\Im\V2\Folder\Pin\PinService;
use Bitrix\Im\V2\Folder\Query\FolderProviderParams;
use Bitrix\Im\V2\Folder\System\AllOpenChannelFolder;
use Bitrix\Im\V2\Folder\System\SystemFolder;
use Bitrix\Im\V2\Recent\Query\RecentFilter;
use Bitrix\Im\V2\Recent\Query\RecentParams;
use Bitrix\Im\V2\Recent\Recent;
use Bitrix\Im\V2\Recent\RecentItem;
use Bitrix\Im\V2\Recent\RecentProvider;
use Bitrix\Im\V2\Result;

class FolderRecentProvider
{
	private const ORDER = ['DATE_LAST_ACTIVITY' => 'DESC'];

	public function __construct(
		private readonly RecentProvider $recentProvider,
		private readonly PinProvider $pinProvider,
	) {}

	public function getTail(Folder $folder, FolderProviderParams $params, int $limit): Result
	{
		$result = new Result();

		// AllOpenChannelFolder is a sort-anchor only; chat-level operations are
		// forbidden. Frontend uses legacy im.v2.Recent.Channel.tail instead.
		if ($folder instanceof AllOpenChannelFolder)
		{
			return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		$userId = $folder->getUserId() ?? 0;
		$folderId = $folder->getId() ?? 0;

		if ($folder instanceof SystemFolder && !$folder->isAvailable($userId))
		{
			return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		if ($userId <= 0 || $folderId <= 0 || $limit <= 0)
		{
			return $result->setResult(new Recent());
		}

		$pinState = $this->pinProvider->getByFolder($folder);

		$items = $folder instanceof PersonalFolder
			? $this->getTailForPersonal($folder, $params, $limit, $pinState)
			: $this->getTailForSystem($folder, $params, $limit, $pinState);

		return $result->setResult($items);
	}

	private function getTailForPersonal(
		PersonalFolder $folder,
		FolderProviderParams $params,
		int $limit,
		FolderPinState $pinState,
	): Recent
	{
		$userId = (int)$folder->getUserId();
		$memberIds = $folder->getChatIds();

		if ($memberIds === [])
		{
			return new Recent();
		}

		$items = (array)$this->recentProvider->getList(
			new RecentParams(
				filter: new RecentFilter(
					userId: $userId,
					lastMessageDate: $params->lastMessageDate,
					chatIds: $memberIds,
				),
				limit: count($memberIds),
				order: self::ORDER,
			),
		);

		$pinned = [];
		$tail = [];
		foreach ($items as $item)
		{
			if ($pinState->hasChat($item->getChatId()))
			{
				$pinned[] = $item;
			}
			else
			{
				$tail[] = $item;
			}
		}

		$pinned = $this->sortPinned($pinned, $pinState);

		return $this->buildRecent(array_merge($pinned, $tail), $pinState, $limit);
	}

	private function getTailForSystem(
		Folder $folder,
		FolderProviderParams $params,
		int $limit,
		FolderPinState $pinState,
	): Recent
	{
		$userId = (int)$folder->getUserId();
		$pinnedIds = $pinState->getPinnedChatIds();
		$isFirstPage = $params->lastMessageDate === null;

		$pinnedItems = [];
		if ($isFirstPage && $pinnedIds !== [])
		{
			$pinnedItems = (array)$this->recentProvider->getList(
				new RecentParams(
					filter: new RecentFilter(
						userId: $userId,
						chatIds: $pinnedIds,
					),
					limit: PinService::LIMIT_PINS_PER_FOLDER,
					order: self::ORDER,
				),
			);
			$pinnedItems = $this->sortPinned($pinnedItems, $pinState);
		}

		$tailLimit = max(0, $limit - count($pinnedItems));
		$tailItems = [];
		if ($tailLimit > 0)
		{
			$tailFilter = new RecentFilter(
				userId: $userId,
				recentSection: $folder->getRecentSection(),
				parentChatId: $folder->getRecentParentScope(),
			);

			$tailFilter = $isFirstPage
				? $tailFilter->with(excludeChatIds: $pinnedIds)
				: $tailFilter->with(lastMessageDate: $params->lastMessageDate);

			$tailItems = (array)$this->recentProvider->getList(
				new RecentParams(
					filter: $tailFilter,
					limit: $tailLimit,
					order: self::ORDER,
				),
			);
		}

		return $this->buildRecent(array_merge($pinnedItems, $tailItems), $pinState, $limit);
	}

	/**
	 * @param RecentItem[] $items
	 * @return RecentItem[]
	 */
	private function sortPinned(array $items, FolderPinState $pinState): array
	{
		$pinSortMap = $pinState->getPinSortMap();
		usort($items, static function (RecentItem $a, RecentItem $b) use ($pinSortMap) {
			$sa = $pinSortMap[$a->getChatId()] ?? PHP_INT_MAX;
			$sb = $pinSortMap[$b->getChatId()] ?? PHP_INT_MAX;
			return $sa <=> $sb;
		});

		return $items;
	}

	/**
	 * @param RecentItem[] $items
	 */
	private function buildRecent(array $items, FolderPinState $pinState, int $limit): Recent
	{
		$items = array_slice($items, 0, $limit);

		$collection = new Recent();
		foreach ($items as $item)
		{
			$item->setPinned($pinState->hasChat($item->getChatId()));
			$collection[] = $item;
		}

		return $collection;
	}
}
