<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnAfterUsersAddEvent;

use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterUsersAddEvent;
use Bitrix\Im\V2\Relation\Reason;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\Trait\ResolveProjectMembersTrait;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\MemberService;

class SyncChatMembersWithProject
{
	use ResolveProjectMembersTrait;

	public static function execute(AfterUsersAddEvent $event): void
	{
		$chat = $event->getChat();
		if (!$chat instanceof CollabChat)
		{
			return;
		}

		$newMemberIds = $event->getChanges()->getNewMembers();

		$structureMemberIds = self::resolveStructureMemberIds($chat, $newMemberIds);

		self::synchronizeStructureFromChatToProject($chat, $structureMemberIds);

		$config = $event->getAddUsersConfig();
		$sharingLinkAuthorId = $config?->bySharingLink() ? (int)($config->sharingLink?->getAuthorId() ?? 0) : 0;

		self::addMembersToProject($chat, $newMemberIds, $structureMemberIds, $sharingLinkAuthorId);
	}

	private static function synchronizeStructureFromChatToProject(CollabChat $chat, array $structureMemberIds): void
	{
		$projectId = (int)$chat->getEntityId();

		$existingStructureUserIds = self::resolveExistingMemberIds(
			$projectId,
			$structureMemberIds,
		);

		if (empty($existingStructureUserIds))
		{
			return;
		}

		Container::getInstance()
			->get(MemberService::class)
			->markMembersAsStructureInitiated($projectId, $existingStructureUserIds)
		;
	}

	private static function addMembersToProject(CollabChat $chat, array $newMemberIds, array $structureMemberIds, int $sharingLinkAuthorId = 0): void
	{
		$projectId = (int)$chat->getEntityId();

		$nonStructureMemberIds = array_values(array_diff($newMemberIds, $structureMemberIds));

		$structureMembers = self::resolveNewProjectMembers($projectId, $structureMemberIds, includeInvited: true);
		$nonStructureMembers = self::resolveNewProjectMembers($projectId, $nonStructureMemberIds);

		if ($structureMembers->isEmpty() && $nonStructureMembers->isEmpty())
		{
			return;
		}

		$memberService = Container::getInstance()->get(MemberService::class);
		$initiatorId = $chat->getContext()->getUserId();

		if (!$structureMembers->isEmpty())
		{
			$memberService->addMembers(
				projectId: $projectId,
				members: $structureMembers,
				userId: $initiatorId,
				initiatedByType: UserToGroupTable::INITIATED_BY_STRUCTURE,
				sharingLinkAuthorId: $sharingLinkAuthorId,
			);
		}

		if (!$nonStructureMembers->isEmpty())
		{
			$memberService->addMembers(
				projectId: $projectId,
				members: $nonStructureMembers,
				userId: $initiatorId,
				sharingLinkAuthorId: $sharingLinkAuthorId,
			);
		}
	}

	/**
	 * @return int[]
	 */
	private static function resolveStructureMemberIds(CollabChat $chat, array $userIds): array
	{
		$structureUserIds = $chat->getRelationByReason(Reason::STRUCTURE)->getUserIds();
		if (empty($structureUserIds))
		{
			return [];
		}

		$structureMemberIdMap = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;

			if ($userId > 0 && isset($structureUserIds[$userId]))
			{
				$structureMemberIdMap[$userId] = true;
			}
		}

		return array_keys($structureMemberIdMap);
	}
}
