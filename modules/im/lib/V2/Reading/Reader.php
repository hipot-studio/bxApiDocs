<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading;

use Bitrix\Im\V2\Anchor;
use Bitrix\Im\V2\Async\Promise\BackgroundJobPromise;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
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

	public function readAllByType(Chat\Type $type, int $userId): void
	{
		$deleteResult = $this->counterUpdater->delete()->byType($type)->forUser($userId)->execute();
		$this->lastIdUpdater->updateByChatIds($deleteResult->getChatIds() ?? [], $userId);
		$this->recentReader->readByType($userId, $type);
		$this->anchorReadService->readByType($type, $userId);

		(new Message\Pull\ReadAllByType($userId, $type))->send();
		(new Message\Event\AfterReadAllChatsByTypeEvent($userId, $type))->send();
	}

	public function readChildren(Chat $chat, int $userId): void
	{
		$result = $this->counterUpdater->delete()->byParent($chat->getId())->forUser($userId)->execute();

		if ($result->hasDeleted())
		{
			$this->lastIdUpdater->updateByChatIds($result->getChatIds() ?? [], $userId);
			(new Pull\ReadChildren($chat, $userId))->send();
		}
	}

	private function sendPullReadMessages(Chat $chat, int $userId, int $lastId, int $counter, ?MessageCollection $messages = null): void
	{
		$messages ??= new MessageCollection();
		if (Loader::includeModule('pull'))
		{
			\CPushManager::DeleteFromQueueBySubTag($userId, 'IM_MESS');
			(new Pull\ReadMessages($chat, $messages, $userId, $lastId, $counter))->send();
			(new Pull\ReadMessagesForOpponent($chat, $messages, $userId, $lastId))->send();
		}
	}

	private function sendEventReadMessages(Chat $chat, int $previousLastId, int $counter, int $userId, bool $byEvent): void
	{
		(new Event\Legacy\ReadMessages($chat, $previousLastId, $counter, $userId, $byEvent))->send();
	}
}
