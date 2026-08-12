<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Anchor;
use Bitrix\Im\V2\Async\Promise\BackgroundJobPromise;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Tree\ChatTreeScopeFactory;
use Bitrix\Im\V2\Chat\Type\ChatsByType;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Common\Normalize;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Event\AfterReadChatsByTypeBatchEvent;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Relation\LastIdUpdater;
use Bitrix\Main\Loader;
use Bitrix\Im\V2\Sync;

class Reader
{
	public function __construct(
		protected readonly View\ViewUpdater $viewUpdater,
		protected readonly View\ViewProvider $viewProvider,
		protected readonly Counter\CountersUpdater $counterUpdater,
		protected readonly Counter\CountersProvider $counterProvider,
		protected readonly Anchor\ReadService $anchorReadService,
		protected readonly RecentReader $recentReader,
		protected readonly LastIdUpdater $lastIdUpdater,
		protected readonly ChatTreeScopeFactory $chatTreeScopeFactory,
		protected readonly TypeRegistry $typeRegistry,
	) {}

	public function readTo(Message $message, int $userId): ReadResult
	{
		if (!User::getInstance($userId)->isExist())
		{
			return ReadResult::error(new ReadingError(ReadingError::USER_NOT_FOUND));
		}

		$messages = $this->viewProvider->getLastUnviewedMessages($message, $userId);

		// All messages already viewed, but counters may still exist (e.g. after legacy unread).
		// Pass the target message so that read() clears counters, updates LAST_ID and sends events.
		if ($messages->isEmpty())
		{
			$messages = MessageCollection::createFromArray([$message]);
		}

		return $this->read($messages, $userId);
	}

	/**
	 * Marks EXACTLY these messages of a single chat read for a user: clears unread
	 * of exactly these messages (NOT "up to a message"), without moving the LAST_ID
	 * cursor and without touching other unread chat messages.
	 *
	 * Event-free: does NOT publish a typed read event ({@see Chat\ExternalChat::onAfterMessagesRead}
	 * sends AfterReadMessagesEvent) — otherwise a cross-module cascade would occur
	 * (loop-safety in the socialnetwork<->im integration). A durable
	 * sync signal to clients (including the mobile local DB via im.v2.Sync.list) is still
	 * given directly via {@see Chat::logReadToSync} — without a typed event.
	 *
	 * Deliberately does NOT reuse {@see Reader::read}: there the counter is cleared "up to
	 * a message inclusive" (toMessage) and read events are published. Here it is an exact
	 * clear of exactly the passed messages (byMessages). The delete strategy and eventing
	 * differ fundamentally, so this is a separate primitive, not a flag/branch of read().
	 *
	 * Expects a single-chat message collection (single-chat read-all of collab system
	 * messages): the counter is read for one chat, so mixed chats are not supported.
	 *
	 * Pull is sent with exact=true: the client clears indication exactly (only
	 * viewedMessages), without a sweep up to max(ID) and without rolling back the
	 * cursor by lastId.
	 */
	public function readExactly(MessageCollection $messages, int $userId): ReadResult
	{
		if (empty($messages->getIds()))
		{
			return ReadResult::error(new ReadingError(ReadingError::MESSAGE_LIST_EMPTY));
		}

		if (!User::getInstance($userId)->isExist())
		{
			return ReadResult::error(new ReadingError(ReadingError::USER_NOT_FOUND));
		}

		$chatIds = $messages->getChatIds();
		if (count($chatIds) > 1)
		{
			// Method contract: exactly one chat. Otherwise byMessages would clear
			// counters of different chats, while getForUser/pull run only for the
			// first one — desync.
			return ReadResult::error(new ReadingError(ReadingError::TOO_MANY_CHATS));
		}

		$chat = Chat::getInstance((int)(reset($chatIds) ?: 0));
		if (!$chat->isExist())
		{
			return ReadResult::error(new ReadingError(ReadingError::CHAT_NOT_FOUND));
		}

		// Access guard (single point for readExactly). These are event-free
		// programmatic read primitives used by cross-module sync (socialnetwork crosspost
		// iterates every chat link for a single userId). Without this check a member of
		// project A could clear read-state and emit a read-receipt in project B's chat.
		// checkAccess does not publish read events, so loop-safety is preserved.
		if (!$chat->checkAccess($userId)->isSuccess())
		{
			return ReadResult::error(new ReadingError(ReadingError::CHAT_NOT_READABLE));
		}

		$this->counterUpdater->delete()->byMessages($messages)->forUser($userId)->execute();
		$counter = $this->counterProvider->getForUser($chat->getChatId(), $userId);

		$viewedMessages = $this->viewUpdater->add($messages->withContextUser($userId)->fillViewed(), $userId);

		// Durable sync signal to clients (catch-up sync of the mobile local DB via
		// im.v2.Sync.list for offline devices) — WITHOUT a typed read event, to keep
		// loop-safety. Only when something was actually cleared: on an idempotent
		// re-read (at-least-once onContentViewed delivery) viewedMessages is empty, and
		// an extra sync record + needless chat re-fetch on mobile are not wanted.
		// See {@see Chat::logReadToSync}.
		if (!empty($viewedMessages->getIds()))
		{
			$chat->logReadToSync($userId);
		}

		$lastId = $chat->getRelationByUserId($userId)?->getLastId() ?? 0;

		// NB: exact-read, unlike read()/readAllInChat(), does NOT clear chat
		// notifications here. CIMNotify::DeleteBySubTag("IM_MESS_<chat>_<user>") drops the
		// chat-wide subtag (mentions etc. of the whole chat), so on a point-wise read of
		// feed-linked system messages it would also remove notifications for other still
		// unread messages of the chat. The subtag has no per-message granularity, so the
		// safe choice for exact-read is to leave chat notifications untouched.

		// exact=true: the client clears indication exactly (only viewedMessages), without
		// a sweep up to max(viewedMessages) and without rolling back the chat cursor by
		// the (old) lastId.
		$this->sendPullReadMessages($chat, $userId, $lastId, $counter, $viewedMessages, true);

		return new ReadResult($counter, $viewedMessages);
	}

	public function read(MessageCollection $messages, int $userId, bool $byEvent = false): ReadResult
	{
		if (empty($messages->getIds()))
		{
			return ReadResult::error(new ReadingError(ReadingError::MESSAGE_LIST_EMPTY));
		}
		$chat = $messages->getCommonChat();
		if (!$chat->isExist())
		{
			return ReadResult::error(new ReadingError(ReadingError::CHAT_NOT_FOUND));
		}
		if (!User::getInstance($userId)->isExist())
		{
			return ReadResult::error(new ReadingError(ReadingError::USER_NOT_FOUND));
		}

		$maxId = max($messages->getIds());
		$endMessage = $messages[$maxId];
		$previousLastId = $chat->getRelationByUserId($userId)?->getLastId() ?? 0;

		$this->counterUpdater->delete()->toMessage($endMessage, $userId)->execute();
		$counter = $this->counterProvider->getForUser($chat->getChatId(), $userId);

		$viewedMessages = $this->viewUpdater->add($messages->withContextUser($userId)->fillViewed(), $userId);

		$chat->onAfterMessagesRead($viewedMessages, $userId);
		$lastId = $chat->getRelationByUserId($userId)?->updateLastId($maxId) ?? 0;

		$this->recentReader->updateDateUpdate($userId, $chat->getChatId());

		// Must run after the counter delete / LAST_ID update above so the resolver sees the post-read unread state.
		$this->recentReader->recomputeCollabPreviewSourceOnRead($chat, $userId);

		\CIMNotify::DeleteBySubTag("IM_MESS_{$chat->getChatId()}_{$userId}", false, false);

		$this->sendPullReadMessages($chat, $userId, $lastId, $counter, $viewedMessages);
		$this->sendEventReadMessages($chat, $previousLastId, $counter, $userId, $byEvent);

		return (new ReadResult($counter, $viewedMessages));
	}

	public function readAllInChat(int $chatId, int $userId, bool $force = false): ReadResult
	{
		$chat = Chat::getInstance($chatId);
		if (!$chat->isExist())
		{
			return ReadResult::error(new ReadingError(ReadingError::CHAT_NOT_FOUND));
		}
		if (!$force && !$chat->isReadable($userId))
		{
			return ReadResult::error(new ReadingError(ReadingError::CHAT_NOT_READABLE));
		}

		$lastMessage = new Message($chat->getLastMessageId());
		if (!$lastMessage->getId())
		{
			return new ReadResult(0, new MessageCollection());
		}

		$this->counterUpdater->delete()->byChat($chatId)->forUser($userId)->execute();
		$counter = 0;
		$previousLastId = $chat->getRelationByUserId($userId)?->getLastId() ?? 0;

		$lastId = $chat->getRelationByUserId($userId)?->updateLastId($lastMessage->getId()) ?? 0;
		$this->recentReader->read($userId, $chat->getChatId());
		$this->anchorReadService->readByChatId($chatId, $userId);
		$chat->onAfterAllMessagesRead($userId);

		BackgroundJobPromise::deferJob(fn () => $this->readChildren($chat, $userId));

		\CIMNotify::DeleteBySubTag("IM_MESS_{$chat->getChatId()}_{$userId}", false, false);

		$this->sendPullReadMessages($chat, $userId, $lastId, $counter);
		$this->sendEventReadMessages($chat, $previousLastId, $counter, $userId, false);

		return (new ReadResult($counter, new MessageCollection()));
	}

	public function readAll(int $userId): void
	{
		$this->counterUpdater->delete()->all()->forUser($userId)->execute();
		$this->lastIdUpdater->updateAll($userId);
		$this->recentReader->readAll($userId);
		$this->anchorReadService->readAll($userId);
		Sync\Logger::getInstance()->add(
			new Sync\Event(Sync\Event::READ_ALL_EVENT, Sync\Event::CHAT_ENTITY, 0),
			$userId
		);

		(new Message\Pull\ReadAll($userId))->send();
		(new Message\Event\AfterReadAllChatsEvent($userId))->send();
	}

	public function readAllByRecentSection(string $recentSection, int $userId, ?int $parentId): void
	{
		$scope = $this->chatTreeScopeFactory->forRecentSection($recentSection, $parentId);
		if (!$scope->isPossible())
		{
			return;
		}

		$counterResult = $this->counterUpdater->delete()->byTreeScope($scope)->forUser($userId)->execute();
		$counterChatIds = Normalize::uniquePositiveIntegers($counterResult->getChatIds() ?? []);
		$this->lastIdUpdater->updateByChatIds($counterChatIds, $userId);

		$recentChatIds = $this->recentReader->readByTreeScope($userId, $scope);
		$anchorChatIds = $this->anchorReadService->readByTreeScope($scope, $userId)->getResult() ?? [];

		$affectedChatIds = $this->collectAffectedChatIds($counterChatIds, $recentChatIds, $anchorChatIds);
		if (empty($affectedChatIds))
		{
			return;
		}

		(new Message\Pull\ReadAllByRecentSection($userId, $recentSection, $parentId))->send();
		$this->sendAfterReadChatsByTypeBatchEvents($userId, $affectedChatIds);
	}

	public function readChildren(Chat $chat, int $userId): void
	{
		$scope = $this->chatTreeScopeFactory->forParent($chat->getId());

		$counterResult = $this->counterUpdater->delete()->byTreeScope($scope)->forUser($userId)->execute();
		$counterChatIds = Normalize::uniquePositiveIntegers($counterResult->getChatIds() ?? []);

		$recentChatIds = $this->recentReader->readByTreeScope($userId, $scope);
		$anchorChatIds = $this->anchorReadService->readByTreeScope($scope, $userId)->getResult() ?? [];

		if ($counterResult->hasDeleted())
		{
			$this->lastIdUpdater->updateByChatIds($counterChatIds, $userId);
		}

		$affectedChatIds = $this->collectAffectedChatIds($counterChatIds, $recentChatIds, $anchorChatIds);
		if (empty($affectedChatIds))
		{
			return;
		}

		(new Pull\ReadChildren($chat, $userId))->send();
		$this->sendAfterReadChatsByTypeBatchEvents($userId, $affectedChatIds);
	}

	/**
	 * @param mixed ...$chatIdsSets
	 * @return int[]
	 */
	private function collectAffectedChatIds(mixed ...$chatIdsSets): array
	{
		$rawChatIds = [];
		foreach ($chatIdsSets as $chatIds)
		{
			if (!is_array($chatIds))
			{
				continue;
			}

			array_push($rawChatIds, ...$chatIds);
		}

		return Normalize::uniquePositiveIntegers($rawChatIds);
	}

	private function sendPullReadMessages(Chat $chat, int $userId, int $lastId, int $counter, ?MessageCollection $messages = null, bool $exact = false): void
	{
		$messages ??= new MessageCollection();
		if (Loader::includeModule('pull'))
		{
			if (!$exact)
			{
				// Drops the user's whole pending IM_MESS push queue (all chats). For an
				// exact point-wise read this is too broad — it would cancel push for other
				// unread messages of the user whose server-side unread remains — so skip it.
				\CPushManager::DeleteFromQueueBySubTag($userId, 'IM_MESS');
			}
			(new Pull\ReadMessages($chat, $messages, $userId, $lastId, $counter, $exact))->send();
			(new Pull\ReadMessagesForOpponent($chat, $messages, $userId, $lastId))->send();
		}
	}

	private function sendEventReadMessages(Chat $chat, int $previousLastId, int $counter, int $userId, bool $byEvent): void
	{
		(new Event\Legacy\ReadMessages($chat, $previousLastId, $counter, $userId, $byEvent))->send();
	}

	/**
	 * @param int[] $chatIds
	 */
	private function sendAfterReadChatsByTypeBatchEvents(int $userId, array $chatIds): void
	{
		if ($userId <= 0)
		{
			return;
		}

		foreach ($this->collectChatsByType($chatIds) as $chatsByType)
		{
			(new AfterReadChatsByTypeBatchEvent($userId, $chatsByType))->send();
		}
	}

	/**
	 * @param int[] $chatIds
	 * @return ChatsByType[]
	 */
	private function collectChatsByType(array $chatIds): array
	{
		$chatIds = Normalize::uniquePositiveIntegers($chatIds);
		if (empty($chatIds))
		{
			return [];
		}

		/** @var array<string, ChatsByType> $groupsByType */
		$groupsByType = [];
		$rows = ChatTable::query()
			->setSelect(['ID', 'TYPE', 'ENTITY_TYPE'])
			->whereIn('ID', $chatIds)
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$type = $this->typeRegistry->getByLiteralAndEntity(
				(string)$row['TYPE'],
				isset($row['ENTITY_TYPE']) ? (string)$row['ENTITY_TYPE'] : null,
			);

			$chatId = (int)$row['ID'];
			if ($chatId <= 0)
			{
				continue;
			}

			$groupsByType[$type->extendedType] ??= new ChatsByType($type);
			$groupsByType[$type->extendedType]->addChatId($chatId);
		}

		return array_values($groupsByType);
	}
}
