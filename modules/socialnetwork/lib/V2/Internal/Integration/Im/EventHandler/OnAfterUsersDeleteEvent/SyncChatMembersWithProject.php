<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnAfterUsersDeleteEvent;

use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterUsersDeleteEvent;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\Trait\ResolveProjectMembersTrait;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\MemberService;

class SyncChatMembersWithProject
{
	use ResolveProjectMembersTrait;

	public static function execute(AfterUsersDeleteEvent $event): void
	{
		$chat = $event->getChat();
		if (!$chat instanceof CollabChat)
		{
			return;
		}

		$projectId = (int)$chat->getEntityId();

		$membersToDelete = self::resolveExistingProjectMembers($projectId, $event->getUserIds());
		if ($membersToDelete->isEmpty())
		{
			return;
		}

		Container::getInstance()
			->get(MemberService::class)
			->deleteMembers(
				projectId: $projectId,
				members: $membersToDelete,
				userId: $chat->getContext()->getUserId(),
			)
		;
	}
}
