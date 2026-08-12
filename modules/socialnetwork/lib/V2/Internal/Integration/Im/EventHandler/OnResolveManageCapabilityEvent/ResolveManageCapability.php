<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnResolveManageCapabilityEvent;

use Bitrix\Im\V2\Chat\ExternalChat\Event\ResolveManageCapabilityEvent;
use Bitrix\Main\EventResult;
use Bitrix\Socialnetwork\Helper\Feature;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class ResolveManageCapability
{
	// Phase 0 (task 718250): superadmin project chat access
	public static function execute(ResolveManageCapabilityEvent $event): ?EventResult
	{
		if (!\Bitrix\Socialnetwork\V2\Feature::isNewProjectsOn())
		{
			return null;
		}

		// Align the tariff gate with CheckAccess (S1): under a tariff restriction the manage
		// capability is not granted (no result => im defaults to false).
		$restricted =
			!Feature::isFeatureEnabled(Feature::PROJECTS_GROUPS)
			&& !Feature::canTurnOnTrial(Feature::PROJECTS_GROUPS)
		;
		if ($restricted)
		{
			return null;
		}

		$chatIds = $event->getChatIds();
		if ($chatIds === [])
		{
			return null;
		}

		$userId = $event->getUserId();

		$projectIdByChatId = Container::getInstance()->getProjectChatResolver()->getProjectIdsByChatIds($chatIds);
		$accessService = Container::getInstance()->getProjectAccessService();

		$capabilities = [];
		foreach ($chatIds as $chatId)
		{
			$chatId = (int)$chatId;
			$projectId = $projectIdByChatId[$chatId] ?? null;
			$capabilities[$chatId] = $projectId !== null && $accessService->canUpdate($userId, $projectId);
		}

		return new EventResult(EventResult::SUCCESS, ['capabilities' => $capabilities]);
	}
}
