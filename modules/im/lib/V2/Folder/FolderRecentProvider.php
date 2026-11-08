<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Folder\Definition\Selection;
use Bitrix\Im\V2\Folder\Error\FolderError;
use Bitrix\Im\V2\Folder\Pin\FolderPinState;
use Bitrix\Im\V2\Folder\Pin\PinProvider;
use Bitrix\Im\V2\Folder\Pin\PinService;
use Bitrix\Im\V2\Folder\Query\FolderProviderParams;
use Bitrix\Im\V2\Folder\System\ChatFolder;
use Bitrix\Im\V2\Folder\System\SystemFolder;
use Bitrix\Im\V2\Recent\PortalRecentProvider;
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
		private readonly PortalRecentProvider $portalRecentProvider,
	) {}

	public function getTail(Folder $folder, FolderProviderParams $params, int $limit): Result
	{
		$result = new Result();

		$definition = $folder->getDefinition();

		// Showcase/unknown folders (e.g. AllOpenChannelFolder) expose no standard
		// recent list; frontend uses legacy im.v2.Recent.Channel.tail instead.
		if (!$definition->supportsTail())
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
			: $this->getTailForSystem($folder, $definition->toSelection(), $params, $limit, $pinState);

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

	/**
	 * System folders (single-section and composite "All"). The composition is
	 * resolved into a {@see Selection}; a single-section folder yields one branch
	 * (unchanged query), "All" yields an OR of its member branches over the root
	 * frame. One recent row per chat, so the union carries no duplicates.
	 */
	private function getTailForSystem(
		Folder $folder,
		Selection $selection,
		FolderProviderParams $params,
		int $limit,
		FolderPinState $pinState,
	): Recent
	{
		$userId = (int)$folder->getUserId();
		$pinnedIds = $pinState->getPinnedChatIds();
		$isFirstPage = $params->lastMessageDate === null;

		// ALG-02 and ALG-03, resolved once for the whole page. The order is load-bearing: a composition
		// that does not cover the chat section has no private rows to decide anything about, so it must
		// not cost a settings read, let alone the partition query.
		$hasChatSection = $this->hasChatSection($selection);
		$areColleaguesShown = $hasChatSection && Recent::areColleaguesShownTo($userId);
		$hideJoinedPortalRows = $hasChatSection && !$areColleaguesShown;
		// The partition holds people the reader has no conversation with, so it carries nothing unread -
		// and neither does the greeting row the client synthesizes from an open gate.
		$isPortalStreamAllowed = $areColleaguesShown && !$params->unreadOnly;

		$pinnedItems = [];
		if ($isFirstPage && $pinnedIds !== [])
		{
			// Pinned block through the resolver: the pin lookup is constrained by the
			// same composition Selection that builds the tail (its branches carry the
			// root frame). A stale shadow of a disabled source no longer matches any
			// branch, so it cannot float to the top - the architectural guard behind
			// {@see PinService::syncShadowsOnSourcesRemove()} shadow hygiene.
			$pinnedItems = (array)$this->recentProvider->getList(
				new RecentParams(
					filter: new RecentFilter(
						userId: $userId,
						chatIds: $pinnedIds,
						selection: $selection,
						unreadOnly: $params->unreadOnly,
						hideInvisibleCompanions: $hasChatSection,
						hideJoinedPortalRows: $hideJoinedPortalRows,
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
				selection: $selection,
				unreadOnly: $params->unreadOnly,
				hideInvisibleCompanions: $hasChatSection,
				hideJoinedPortalRows: $hideJoinedPortalRows,
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

			if ($isPortalStreamAllowed)
			{
				$metaItems = (array)$this->portalRecentProvider->getList(
					$userId,
					$params->lastMessageDate,
					$tailLimit,
				);
				$tailItems = $this->mergeByDate($tailItems, $metaItems, $tailLimit);
			}
		}

		$recent = $this->buildRecent(array_merge($pinnedItems, $tailItems), $pinState, $limit);

		if ($areColleaguesShown)
		{
			// Explicitly, on the page that shows the meta: the flags cost two queries the other recent
			// endpoints would pay for without ever reading them.
			$recent->applyAcquaintanceMeta();
		}

		// DTO-02: an open gate is what the clients need the sections of a row without a chat for, page
		// content aside - the birthday greeting they synthesize themselves has no row in the response.
		return $isPortalStreamAllowed ? $recent->markPortalStreamAllowed() : $recent;
	}

	private function hasChatSection(Selection $selection): bool
	{
		foreach ($selection->getBranches() as $branch)
		{
			if ($branch->typeCondition === ChatFolder::RECENT_SECTION)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * ALG-01. Two date-ordered streams into one page: the reader's own rows and the portal
	 * acquaintances, both read with the same cursor.
	 *
	 * @param RecentItem[] $tail
	 * @param RecentItem[] $meta
	 * @return RecentItem[]
	 */
	private function mergeByDate(array $tail, array $meta, int $tailLimit): array
	{
		$tail = array_values($tail);
		// Before the merge and not after it: a duplicate removed from a page already cut to the limit
		// would leave the page short, and a short page is how the controller reports the end of the list.
		$meta = $this->dropAcquaintedCompanions($meta, $tail);
		$tailCount = count($tail);
		$metaCount = count($meta);

		$i = 0;
		$j = 0;
		$merged = [];
		while (count($merged) < $tailLimit && ($i < $tailCount || $j < $metaCount))
		{
			if ($j >= $metaCount)
			{
				$merged[] = $tail[$i++];

				continue;
			}

			if ($i >= $tailCount)
			{
				$merged[] = $meta[$j++];

				continue;
			}

			// Ties go to the tail row: an own conversation outranks an acquaintance on the same second.
			$merged[] = $this->activityOf($tail[$i]) >= $this->activityOf($meta[$j])
				? $tail[$i++]
				: $meta[$j++]
			;
		}

		return $merged;
	}

	/**
	 * First pass of ALG-01: the reader may have started a conversation between the two queries, so the
	 * same person can arrive twice - once as their own row, once as an acquaintance. The acquaintance
	 * is the copy that goes.
	 *
	 * @param RecentItem[] $meta
	 * @param RecentItem[] $tail
	 * @return RecentItem[]
	 */
	private function dropAcquaintedCompanions(array $meta, array $tail): array
	{
		$ownDialogIds = [];
		foreach ($tail as $item)
		{
			$ownDialogIds[$item->getDialogId()] = true;
		}

		return array_values(array_filter(
			$meta,
			static fn(RecentItem $item) => !isset($ownDialogIds[$item->getDialogId()]),
		));
	}

	private function activityOf(RecentItem $item): int
	{
		return $item->getDateLastActivity()?->getTimestamp() ?? 0;
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
