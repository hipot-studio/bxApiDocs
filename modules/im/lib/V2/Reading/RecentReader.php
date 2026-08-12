<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Tree\ChatAncestorIterator;
use Bitrix\Im\V2\Chat\Tree\ChatTreeScope;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Pull\Event\RecentUpdate;
use Bitrix\Im\V2\Reading\Counter\CountersProvider;
use Bitrix\Im\V2\Reading\Counter\Internal\CountersCache;
use Bitrix\Im\V2\Reading\Pull\UnreadChat;
use Bitrix\Im\V2\Recent\PreviewSource\CollabPreviewSourcePointerService;
use Bitrix\Im\V2\Recent\PreviewSource\CollabPreviewSourceResolver;
use Bitrix\Im\V2\Recent\RecentProvider;
use Bitrix\Im\V2\Recent\RecentUpdater;
use Bitrix\Im\V2\Sync;
use Bitrix\Main\DI\ServiceLocator;

class RecentReader
{
	public function __construct(
		private readonly RecentProvider $provider,
		private readonly RecentUpdater $updater,
		private readonly CountersCache $countersCache,
		private readonly CountersProvider $countersProvider,
		private readonly ChatAncestorIterator $ancestorIterator,
	) {}

	public function read(int $userId, int $chatId): ReadResult
	{
		return $this->changeReadStatus($userId, $chatId, false);
	}

	public function unread(int $userId, int $chatId, int $markedId): ReadResult
	{
		return $this->changeReadStatus($userId, $chatId, true, $markedId);
	}

	public function readAll(int $userId): void
	{
		$this->updater->update($userId, unread: false);
	}

	/**
	 * Returns affected chat IDs.
	 *
	 * @return int[]
	 */
	public function readByTreeScope(int $userId, ChatTreeScope $scope): array
	{
		return $this->updater->updateByTreeScope($userId, $scope);
	}

	public function updateDateUpdate(int $userId, int $chatId): void
	{
		$this->updater->update($userId, $chatId);
	}

	private function changeReadStatus(int $userId, int $chatId, bool $unread, int $markedId = 0): ReadResult
	{
		$item = $this->provider->getItem($userId, $chatId);
		if ($item === null)
		{
			return ReadResult::error(new ReadingError(ReadingError::RECENT_ITEM_NOT_FOUND));
		}

		if ($item->isUnread() === $unread && $item->getMarkedId() === $markedId)
		{
			return new ReadResult($this->countersProvider->getForUser($chatId, $userId), new MessageCollection());
		}

		$item->setUnread($unread)->setMarkedId($markedId); // update static cache

		$chat = Chat::getInstance($chatId);

		$previewSourceColumns = $this->resolvePreviewSourceColumns($chat, $userId, $unread);

		$this->updater->update($userId, $chatId, $unread, $markedId, $previewSourceColumns);
		// Recent read doesn't affect message counter, so we can get it before cache invalidation
		$counter = $this->countersProvider->getForUser($chatId, $userId);
		$this->countersCache->remove($userId);

		// Branches are mutually exclusive, so the preview-source pointer is written exactly once per read:
		// for the collab itself it was already written in the update above (only the push remains here),
		// for a child read the ancestor still needs a recompute.
		if ($unread)
		{
			$this->sendAncestorContext($chat, $userId);
		}
		elseif ($previewSourceColumns !== null)
		{
			$this->sendCollabPreviewUpdate($chatId, $userId);
		}
		else
		{
			$this->recomputeCollabPreviewSourceOnRead($chat, $userId);
		}

		(new UnreadChat($chat, $userId, $counter, $item))->send();
		Sync\Logger::getInstance()->add(
			new Sync\Event(Sync\Event::ADD_EVENT, Sync\Event::CHAT_ENTITY, $chatId),
			$userId,
			$chat
		);

		return new ReadResult($counter, new MessageCollection());
	}

	/**
	 * Resolves the preview-source pointer columns for a collab recent row, to piggyback onto the same
	 * single-row update. Returns null (pointer untouched) for non-collab rows, when unread, or when the
	 * feature flag is off.
	 *
	 * @return array{PREVIEW_SOURCE_CID:int,PREVIEW_SOURCE_MID:int}|null
	 */
	private function resolvePreviewSourceColumns(Chat $chat, int $userId, bool $unread): ?array
	{
		if ($unread || !Features::isCollabPreviewSourceEnabled())
		{
			return null;
		}

		$type = $chat->getType();
		if ($type !== Chat::IM_TYPE_COLLAB && $type !== Chat::IM_TYPE_OPEN_COLLAB)
		{
			return null;
		}

		$collabChatId = (int)$chat->getChatId();
		if ($collabChatId <= 0)
		{
			return null;
		}

		/** @var CollabPreviewSourceResolver $resolver */
		$resolver = ServiceLocator::getInstance()->get(CollabPreviewSourceResolver::class);
		$source = $resolver->resolve($collabChatId, $userId);

		// isMainChat (incl. "everything muted") materializes as 0/0 = source is the row itself.
		return [
			'PREVIEW_SOURCE_CID' => $source->isMainChat ? 0 : $source->sourceChatId,
			'PREVIEW_SOURCE_MID' => $source->isMainChat ? 0 : $source->messageId,
		];
	}

	/**
	 * Recomputes the preview-source pointer of the just-read chat's collab ancestor for this user only,
	 * then pushes the refreshed preview over the Pull stream. No-op when the feature flag is off or the
	 * read chat has no collab ancestor.
	 */
	public function recomputeCollabPreviewSourceOnRead(Chat $readChat, int $userId): void
	{
		if (!Features::isCollabPreviewSourceEnabled())
		{
			return;
		}

		$readChatId = (int)$readChat->getChatId();
		if ($readChatId <= 0)
		{
			return;
		}

		$collabChatId = ServiceLocator::getInstance()
			->get(CollabPreviewSourcePointerService::class)
			->recomputeForSourceChatRead($readChatId, $userId)
		;
		if ($collabChatId === null)
		{
			return;
		}

		$this->sendCollabPreviewUpdate($collabChatId, $userId);
	}

	private function sendCollabPreviewUpdate(int $collabChatId, int $userId): void
	{
		$collabChat = Chat::getInstance($collabChatId);
		if (!$collabChat->isExist())
		{
			return;
		}

		$collabItem = $this->provider->getItem($userId, $collabChatId);
		if ($collabItem === null)
		{
			// The user has no recent row in the collab (e.g. not a member of a closed project he only
			// reaches through a child task chat): pushing a collab recentUpdate here would leak the
			// project into his chat list. Mirror the guard used by sendAncestorContext().
			return;
		}

		(new RecentUpdate($collabChat, [$userId], $collabItem->getDateLastActivity()))->send();
	}

	private function sendAncestorContext(Chat $chat, int $userId): void
	{
		foreach ($this->ancestorIterator->ancestorsOf($chat) as $ancestor)
		{
			$parentItem = $this->provider->getItem($userId, $ancestor->getChatId());
			if ($parentItem !== null)
			{
				(new RecentUpdate($ancestor, [$userId], $parentItem->getDateLastActivity()))->send();
			}
		}
	}
}
