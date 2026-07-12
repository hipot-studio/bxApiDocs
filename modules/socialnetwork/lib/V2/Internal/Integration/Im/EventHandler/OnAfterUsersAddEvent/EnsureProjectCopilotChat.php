<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnAfterUsersAddEvent;

use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterUsersAddEvent;
use Bitrix\Main\Application;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Provider\ProjectCopilotChatProvider;

class EnsureProjectCopilotChat
{
	public static function execute(AfterUsersAddEvent $event): void
	{
		$chat = $event->getChat();
		if (!$chat instanceof CollabChat)
		{
			return;
		}

		$projectId = (int)$chat->getEntityId();
		$chatId = (int)$chat->getId();
		$newMemberIds = $event->getChanges()->getNewMembers();

		if ($projectId <= 0 || $chatId <= 0 || empty($newMemberIds))
		{
			return;
		}

		Application::getInstance()->addBackgroundJob(
			static fn () => self::ensureCopilots($newMemberIds, $projectId, $chatId),
		);
	}

	private static function ensureCopilots(array $userIds, int $projectId, int $parentChatId): void
	{
		$provider = Container::getInstance()->get(ProjectCopilotChatProvider::class);
		foreach ($userIds as $userId)
		{
			$provider->getByUserAndProject((int)$userId, $projectId, $parentChatId);
		}
	}
}
