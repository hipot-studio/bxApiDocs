<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnResolveRecentFixedChatIdsEvent;

use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Im\V2\Chat\ExternalChat\Event\ResolveRecentFixedChatIdsEvent;
use Bitrix\Main\EventResult;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Provider\ProjectCopilotChatProvider;

class AddProjectFixedChatIds
{
	public static function execute(ResolveRecentFixedChatIdsEvent $event): ?EventResult
	{
		if ($event->getRecentSection() !== 'collabDefault')
		{
			return null;
		}

		$chat = $event->getChat();
		if (!$chat instanceof CollabChat)
		{
			return null;
		}

		$projectId = (int)$chat->getEntityId();
		$userId = $event->getUserId();
		$parentChatId = $chat->getChatId() ?? 0;
		if ($projectId <= 0 || $userId <= 0 || $parentChatId <= 0)
		{
			return null;
		}

		if ($chat->getRelationByUserId($userId) !== null)
		{
			// Just ensure the copilot chat exists; not fixed for now.
			Container::getInstance()
				->get(ProjectCopilotChatProvider::class)
				->getByUserAndProject($userId, $projectId, $parentChatId)
			;
		}

		return new EventResult(EventResult::SUCCESS, ['recentFixedChatIds' => [$parentChatId]]);
	}
}
