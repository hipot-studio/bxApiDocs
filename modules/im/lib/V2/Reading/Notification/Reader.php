<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Notification;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Notification\ChatProvider;
use Bitrix\Im\V2\Reading\Counter\Entity\UsersCounterMap;
use Bitrix\Im\V2\Reading\Counter\Notification\CountersProvider;
use Bitrix\Im\V2\Reading\Counter\Notification\CountersUpdater;
use Bitrix\Im\V2\Reading\Notification\Pull\ReadAllNotifications;
use Bitrix\Im\V2\Reading\Notification\Pull\ReadNotifications;
use Bitrix\Im\V2\Reading\ReadingError;
use Bitrix\Main\Loader;
use Bitrix\Pull\MobileCounter;

class Reader
{
	public function __construct(
		private readonly CountersUpdater $countersUpdater,
		private readonly CountersProvider $countersProvider,
		private readonly ChatProvider $notificationChatProvider,
	) {}

	public function read(MessageCollection $messages): ReadResult
	{
		$chatIds = $messages->getChatIds();
		if (count($chatIds) > 1)
		{
			return ReadResult::error(new ReadingError(ReadingError::TOO_MANY_CHATS));
		}
		if (empty($chatIds))
		{
			return ReadResult::error(new ReadingError(ReadingError::MESSAGE_LIST_EMPTY));
		}
		$chatId = array_values($chatIds)[0];
		$userId = $this->notificationChatProvider->getUserId($chatId);
		if ($userId === 0)
		{
			return ReadResult::error(new ReadingError(ReadingError::WRONG_CHAT_TYPE));
		}

		$multiReadResult = $this->readMulti($messages);
		$this->sendMobileCounter($userId);

		return ReadResult::fromMultiReadResult($multiReadResult, $userId, $chatId);
	}

	public function readMulti(MessageCollection $messages): MultiReadResult
	{
		$messages = $this->notificationChatProvider->filterNotificationMessages($messages);
		if ($messages->isEmpty())
		{
			return new MultiReadResult(new UsersCounterMap(), []);
		}
		$readMessagesByUsers = $this->getMessagesByUsers($messages);
		$affectedUsers = array_keys($readMessagesByUsers);
		$this->countersUpdater->delete()->byMessages($messages)->forAllUsers($affectedUsers)->execute();
		$counters = $this->countersProvider->getForUsers($affectedUsers);
		foreach ($counters as $userId => $counter)
		{
			$chatId = $this->notificationChatProvider->getChatId($userId);
			$readResult = new ReadResult($userId, $chatId, $counter, $readMessagesByUsers[$userId] ?? []);
			(new ReadNotifications($readResult))->send();
		}

		return new MultiReadResult($counters, $readMessagesByUsers);
	}

	public function readAll(int $userId, ?MessageCollection $excludeNotifications = null): ReadAllResult
	{
		$chatId = $this->notificationChatProvider->getChatId($userId);
		$excludeMessageIds = $this->getExcludeIds($chatId, $excludeNotifications);
		$this->countersUpdater->delete()->byChat($chatId, $excludeMessageIds)->forUser($userId)->execute();
		$counter = $this->countersProvider->getForUsers([$userId])->getByUserId($userId);
		$result = new ReadAllResult($userId, $chatId, $counter, $excludeMessageIds);
		(new ReadAllNotifications($result))->send();
		$this->sendMobileCounter($userId);

		return $result;
	}

	private function sendMobileCounter(int $userId): void
	{
		if (Loader::includeModule('pull'))
		{
			MobileCounter::send($userId);
		}
	}

	private function getMessagesByUsers(MessageCollection $messages): array
	{
		$messageIdsByUserId = [];

		foreach ($messages as $message)
		{
			$messageId = $message->getId();
			$chatId = $message->getChatId();
			$userId = $this->notificationChatProvider->getUserId($chatId);
			if ($userId)
			{
				$messageIdsByUserId[$userId][] = $messageId;
			}
		}

		return $messageIdsByUserId;
	}

	private function getExcludeIds(int $chatId, ?MessageCollection $excludeMessages = null): array
	{
		$baseExcludeIds = $excludeMessages?->getIds() ?? [];
		$confirms = $this->getConfirmIds($chatId);

		return array_unique(array_merge($baseExcludeIds, $confirms));
	}

	private function getConfirmIds(int $chatId): array
	{
		$rows = MessageTable::query()
			->setSelect(['ID'])
			->where('CHAT_ID', $chatId)
			->where('NOTIFY_TYPE', \IM_NOTIFY_CONFIRM)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'ID'));
	}
}
