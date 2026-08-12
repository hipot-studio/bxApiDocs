<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnAfterDetachChildEvent;

use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterDetachChildEvent;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Socialnetwork\Internals\Registry\GroupRegistry;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\ChatMessageSender;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message\ChildChatDetachedFromProject;
use Bitrix\Socialnetwork\V2\Internal\Service\UserService;

class SendChildDetachedMessage
{
	public static function execute(AfterDetachChildEvent $event): void
	{
		$chat = $event->getChat();
		if (!$chat instanceof CollabChat)
		{
			return;
		}

		$workgroup = GroupRegistry::getInstance()->get((int)$chat->getEntityId());
		if ($workgroup === null)
		{
			return;
		}

		$userCollection = ServiceLocator::getInstance()->get(UserService::class)->getUsers([$event->getUserId()]);
		$contextUser = $userCollection->isEmpty() ? null : $userCollection->getFirstEntity();

		$messageData = new ChildChatDetachedFromProject($contextUser, (string)$workgroup->getName());

		ServiceLocator::getInstance()
			->get(ChatMessageSender::class)
			->sendMessage((int)$event->getChildChat()->getChatId(), $messageData)
		;
	}
}
