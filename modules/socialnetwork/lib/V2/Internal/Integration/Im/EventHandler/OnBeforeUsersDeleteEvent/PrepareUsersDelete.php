<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnBeforeUsersDeleteEvent;

use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeUsersDeleteEvent;
use Bitrix\Main\EventResult;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\Trait\ResolveProjectMembersTrait;

class PrepareUsersDelete
{
	use ResolveProjectMembersTrait;

	public static function execute(BeforeUsersDeleteEvent $event): EventResult
	{
		$chat = $event->getChat();
		if (!$chat instanceof CollabChat)
		{
			return new EventResult(EventResult::SUCCESS);
		}

		$initiatorId = $chat->getContext()->getUserId();

		// $initiatorId === 0 marks a system-initiated chat delete.
		// Skip permission checks on this path.
		if ($initiatorId === 0)
		{
			return new EventResult(EventResult::SUCCESS);
		}

		$projectId = (int)$chat->getEntityId();

		$requestedUserIds = $event->getUserIds();

		$membersToDelete = self::resolveExistingProjectMembers($projectId, $requestedUserIds);
		if ($membersToDelete->isEmpty())
		{
			return new EventResult(EventResult::SUCCESS);
		}

		$deniedMemberIdsMap = self::getDeniedMemberIdsMap(
			$initiatorId,
			$projectId,
			$membersToDelete,
		);

		$allMembersAllowed = empty($deniedMemberIdsMap);
		if ($allMembersAllowed)
		{
			return new EventResult(EventResult::SUCCESS);
		}

		$allMembersDenied = count($deniedMemberIdsMap) === $membersToDelete->count();
		if ($allMembersDenied)
		{
			return new EventResult(EventResult::ERROR);
		}

		return new EventResult(EventResult::SUCCESS, [
			'userIds' => self::excludeDeniedMembers($requestedUserIds, $deniedMemberIdsMap),
		]);
	}

	private static function getDeniedMemberIdsMap(
		int $initiatorId,
		int $projectId,
		MemberEntityCollection $membersToDelete,
	): array
	{
		$projectAccessService = Container::getInstance()->getProjectAccessService();

		$deniedIdsMap = [];

		$memberToLeave = $membersToDelete->findOneById($initiatorId);
		if ($memberToLeave !== null && !$projectAccessService->canLeave($initiatorId, $projectId))
		{
			$deniedIdsMap[$initiatorId] = true;
		}

		$membersToExclude = $membersToDelete->filter(
			static fn (MemberEntity $member): bool => $member->getId() !== $initiatorId,
		);
		if ($membersToExclude->isEmpty())
		{
			return $deniedIdsMap;
		}

		$canExcludeAllMembers = $projectAccessService->canExclude(
			$initiatorId,
			$projectId,
			['members' => $membersToExclude->toArray()],
		);

		if ($canExcludeAllMembers)
		{
			return $deniedIdsMap;
		}

		foreach ($membersToExclude as $member)
		{
			$userId = (int)$member->getId();
			if ($userId <= 0)
			{
				continue;
			}

			$canExclude = $projectAccessService->canExclude(
				$initiatorId,
				$projectId,
				['members' => [$member->toArray()]],
			);

			if (!$canExclude)
			{
				$deniedIdsMap[$userId] = true;
			}
		}

		return $deniedIdsMap;
	}
}
