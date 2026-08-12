<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnFilterUsersByAccessEvent;

use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\Chat\OpenCollabChat;
use Bitrix\Im\V2\Chat\ExternalChat\Event\FilterUsersByAccessEvent;
use Bitrix\Main\Error;
use Bitrix\Main\EventResult;
use Bitrix\Socialnetwork\Helper\Feature;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class CheckAccess
{
	public static function execute(FilterUsersByAccessEvent $event): ?EventResult
	{
		if (!\Bitrix\Socialnetwork\V2\Feature::isNewProjectsOn())
		{
			return null;
		}

		$restricted =
			!Feature::isFeatureEnabled(Feature::PROJECTS_GROUPS)
			&& !Feature::canTurnOnTrial(Feature::PROJECTS_GROUPS)
		;

		if ($restricted)
		{
			return new EventResult(
				EventResult::SUCCESS,
				[
					'userIds' => $event->getUserIds(),
					'error' => new Error('COLLAB_TARIFF_RESTRICTED', 'COLLAB_TARIFF_RESTRICTED'),
				]
			);
		}

		// Phase 0 (task 718250): superadmin project chat access
		if ($event->isBatchRelationFilterContext())
		{
			return null;
		}

		$chat = $event->getChat();
		if (!self::isClosedCollabChat($chat))
		{
			return null;
		}

		$projectId = Container::getInstance()->getProjectChatResolver()->getProjectIdByChatId((int)$chat->getId());
		if ($projectId === null)
		{
			return null;
		}

		$accessService = Container::getInstance()->getProjectAccessService();
		$copilotBotId = Container::getInstance()->getCopilotBotResolver()->getCopilotBotId();

		$allowedUserIds = array_values(array_filter(
			$event->getUserIds(),
			static fn($userId): bool => ($copilotBotId !== null && (int)$userId === $copilotBotId)
				|| $accessService->canRead((int)$userId, $projectId),
		));

		return new EventResult(EventResult::SUCCESS, ['userIds' => $allowedUserIds]);
	}

	private static function isClosedCollabChat(ExternalChat $chat): bool
	{
		return $chat instanceof CollabChat && !($chat instanceof OpenCollabChat);
	}
}
