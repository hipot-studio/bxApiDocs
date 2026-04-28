<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Notification;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Notification\ChatProvider;
use Bitrix\Im\V2\Reading\Counter\Notification\CountersProvider;
use Bitrix\Im\V2\Reading\Counter\Notification\CountersUpdater;
use Bitrix\Im\V2\Reading\Notification\Pull\UnreadNotifications;
use Bitrix\Im\V2\Reading\ReadingError;

class Unreader
{
	public function __construct(
		private readonly CountersUpdater $countersUpdater,
		private readonly CountersProvider $countersProvider,
		private readonly ChatProvider $notificationChatProvider,
	) {}

	public function unread(Message $message): UnreadResult
	{
		$collection = MessageCollection::createFromArray([$message]);
		$userId = $this->notificationChatProvider->getUserId($message->getChatId());

		return $this->unreadMultiForUser($collection, $userId);
	}

	public function unreadMultiForUser(MessageCollection $messages, int $userId): UnreadResult
	{
		if ($userId === 0)
		{
			return UnreadResult::error(new ReadingError(ReadingError::USER_ID_EMPTY));
		}

		$chatId = $this->notificationChatProvider->getChatId($userId);
		if ($messages->isEmpty())
		{
			$counter = $this->countersProvider->getForUsers([$userId])->getByUserId($userId);
			return UnreadResult::empty($userId, $chatId, $counter);
		}
		if (!$messages->getCommonChat()->isExist())
		{
			return UnreadResult::error(new ReadingError(ReadingError::TOO_MANY_CHATS));
		}

		$this->countersUpdater->add($messages, $userId);
		$counter = $this->countersProvider->getForUsers([$userId])->getByUserId($userId);
		$result = new UnreadResult($userId, $chatId, $counter, $messages->getIds());

		(new UnreadNotifications($result))->send();

		return $result;
	}
}
