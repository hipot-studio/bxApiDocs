<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Notification;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Notification\ChatProvider;
use Bitrix\Im\V2\Reading\Counter\Notification\CountersUpdater;

class Cleaner
{
	public function __construct(
		private readonly CountersUpdater $countersUpdater,
		private readonly ChatProvider $notificationChatProvider,
	) {}

	public function onDeleteMessages(MessageCollection $notifications, ?int $skipUser = null): void
	{
		$affectedUserIds = $this->notificationChatProvider->getUserIdsByNotifications($notifications);
		$affectedUserIds = array_filter($affectedUserIds, static fn (int $userId): bool => $userId !== $skipUser);

		$this->countersUpdater->delete()
			->byMessages($notifications)
			->forAllUsers($affectedUserIds)
			->execute()
		;
	}

	public function onDeleteBySubtag(MessageCollection $notifications, int $addedNotificationUserId): void
	{
		$this->onDeleteMessages($notifications, $addedNotificationUserId);
	}

	public function onDeleteMessage(Message $message): void
	{
		$userId = $this->notificationChatProvider->getUserId($message->getChatId());
		$this->countersUpdater->delete()->byMessage($message)->forUser($userId)->execute();
	}

	public function onDeleteAllNotifications(int $userId): void
	{
		$chatId = $this->notificationChatProvider->getChatId($userId);
		$this->countersUpdater->delete()->byChat($chatId)->forUser($userId)->execute();
	}
}
