<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Scheduled;

use Bitrix\Im\V2\Chat\Event\AfterDeleteEvent;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;

class ScheduledMessageEventHandler
{
	public static function onAfterChatDelete(AfterDeleteEvent $event): void
	{
		$chatId = $event->getChat()->id;
		ServiceLocator::getInstance()->get(ScheduledMessageService::class)->deleteByChatId($chatId);
	}

	public static function onAfterUserDelete(int $userId): void
	{
		ServiceLocator::getInstance()->get(ScheduledMessageService::class)->deleteByAuthorId($userId);
	}

	public static function onAfterUserUpdate(array $fields): void
	{
		if (
			(int)($fields['ID'] ?? 0) <= 0
			|| ($fields['ACTIVE'] ?? null) !== 'N'
			|| ($fields['RESULT'] ?? null) !== true
		)
		{
			return;
		}

		ServiceLocator::getInstance()->get(ScheduledMessageService::class)->deleteByAuthorId((int)$fields['ID']);
	}

	public static function onChatUserDelete(Event $event): void
	{
		$parameters = $event->getParameters();

		$chatId = (int)$parameters['chatId'];
		$userIds = array_map(
			fn($userId): int => (int)$userId,
			$parameters['userIds'],
		);

		ServiceLocator::getInstance()
			->get(ScheduledMessageService::class)
			->deleteByChatIdAndAuthorIds($chatId, $userIds)
		;
	}
}
