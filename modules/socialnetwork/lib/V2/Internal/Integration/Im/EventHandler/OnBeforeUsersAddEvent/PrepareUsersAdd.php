<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnBeforeUsersAddEvent;

use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeUsersAddEvent;
use Bitrix\Main\EventResult;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\ShowHistoryOption;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\Trait\ResolveProjectMembersTrait;
use Bitrix\Socialnetwork\V2\Internal\Repository\CollabOptionRepository;

class PrepareUsersAdd
{
	use ResolveProjectMembersTrait;

	public static function execute(BeforeUsersAddEvent $event): EventResult
	{
		$chat = $event->getChat();
		if (!$chat instanceof CollabChat)
		{
			return new EventResult(EventResult::SUCCESS);
		}

		$projectId = (int)$chat->getEntityId();

		$config =
			$event->getAddUsersConfig()
				->setWithMessage(false)
				->setHideHistory(self::shouldHideHistory($projectId))
		;

		$initiatorId = $chat->getContext()->getUserId();

		// $initiatorId === 0 marks a system-initiated chat add.
		// Skip permission checks on this path.
		if ($initiatorId === 0)
		{
			return new EventResult(EventResult::SUCCESS, ['config' => $config]);
		}

		$requestedUserIds = $event->getUserIds();

		$membersToAdd = self::resolveNewProjectMembers($projectId, $requestedUserIds);
		if ($membersToAdd->isEmpty())
		{
			return new EventResult(EventResult::SUCCESS, ['config' => $config]);
		}

		$deniedMemberIdsMap = self::getDeniedMemberIdsMap(
			$initiatorId,
			$projectId,
			$membersToAdd,
		);

		$allMembersAllowed = empty($deniedMemberIdsMap);
		if ($allMembersAllowed)
		{
			return new EventResult(EventResult::SUCCESS, ['config' => $config]);
		}

		$allMembersDenied = count($deniedMemberIdsMap) === $membersToAdd->count();
		if ($allMembersDenied)
		{
			return new EventResult(EventResult::ERROR);
		}

		return new EventResult(EventResult::SUCCESS, [
			'userIds' => self::excludeDeniedMembers($requestedUserIds, $deniedMemberIdsMap),
			'config' => $config,
		]);
	}

	private static function shouldHideHistory(int $projectId): bool
	{
		$options =
			Container::getInstance()
				->get(CollabOptionRepository::class)
				->getOptions($projectId, [ShowHistoryOption::DB_NAME])
		;

		$value = $options[ShowHistoryOption::DB_NAME] ?? ShowHistoryOption::DEFAULT_VALUE;

		return $value !== 'Y';
	}

	private static function getDeniedMemberIdsMap(
		int $initiatorId,
		int $projectId,
		MemberEntityCollection $membersToAdd,
	): array
	{
		$projectAccessService = Container::getInstance()->getProjectAccessService();

		$deniedIdsMap = [];

		$memberToJoin = $membersToAdd->findOneById($initiatorId);
		if ($memberToJoin !== null && !$projectAccessService->canJoin($initiatorId, $projectId))
		{
			$deniedIdsMap[$initiatorId] = true;
		}

		$membersToInvite = $membersToAdd->filter(
			static fn (MemberEntity $member): bool => $member->getId() !== $initiatorId,
		);
		if ($membersToInvite->isEmpty())
		{
			return $deniedIdsMap;
		}

		$canInviteAllMembers = $projectAccessService->canInvite(
			$initiatorId,
			$projectId,
			['members' => $membersToInvite->toArray()],
		);

		if ($canInviteAllMembers)
		{
			return $deniedIdsMap;
		}

		foreach ($membersToInvite as $member)
		{
			$userId = (int)$member->getId();
			if ($userId <= 0)
			{
				continue;
			}

			$canInvite = $projectAccessService->canInvite(
				$initiatorId,
				$projectId,
				['members' => [$member->toArray()]],
			);

			if (!$canInvite)
			{
				$deniedIdsMap[$userId] = true;
			}
		}

		return $deniedIdsMap;
	}
}
