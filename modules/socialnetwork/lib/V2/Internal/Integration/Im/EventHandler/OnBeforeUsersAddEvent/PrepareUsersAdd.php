<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnBeforeUsersAddEvent;

use Bitrix\Socialnetwork\Collab\Control\ExternalMembershipValidator;
use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Im\V2\Chat\OpenCollabChat;
use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeUsersAddEvent;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserFactory;
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

		$isBySharingLink = $event->getAddUsersConfig()->bySharingLink();

		$config
			= $event->getAddUsersConfig()
				->setWithMessage(false)
				->setHideHistory(self::shouldHideHistory($projectId))
		;

		// Phase 0 (task 718250): silent auto-join for closed project chat: join non-members hidden, skip invite checks
		if ($event->getAddUsersConfig()->byAutoJoin)
		{
			$existingMemberIds = self::resolveExistingMemberIds($projectId, $event->getUserIds());
			$notMembers = array_values(array_diff(
				array_map('intval', $event->getUserIds()),
				$existingMemberIds,
			));
			$config = $config->addHiddenUserIds($notMembers);

			return new EventResult(EventResult::SUCCESS, ['config' => $config]);
		}

		$initiatorId = $chat->getContext()->getUserId();

		if ($isBySharingLink)
		{
			$authorId = (int)($config->sharingLink?->getAuthorId() ?? 0);
			if ($authorId > 0)
			{
				$initiatorId = $authorId;
			}
		}

		$requestedUserIds = Loader::includeModule('extranet')
			? $event->getUserIds()
			: self::getExistingActiveUserIds($event->getUserIds());
		$hiddenUserIds = [];
		if (!$isBySharingLink && !$chat instanceof OpenCollabChat)
		{
			$hiddenUserIds = self::resolveAdminAccessSelfJoinUserIds($initiatorId, $projectId, $requestedUserIds);
		}
		$projectUserIds = array_filter(
			$requestedUserIds,
			static fn ($userId): bool => !$config->isHidden((int)$userId)
				&& !in_array((int)$userId, $hiddenUserIds, true),
		);
		$preflight = ExternalMembershipValidator::validateUsers($projectId, $projectUserIds);
		if (!$preflight->isSuccess())
		{
			return new EventResult(EventResult::ERROR);
		}

		// $initiatorId === 0 marks a system-initiated chat add.
		// Skip permission checks on this path.
		if ($initiatorId === 0)
		{
			return new EventResult(EventResult::SUCCESS, ['config' => $config, 'userIds' => $requestedUserIds]);
		}

		// Phase 0 (task 718250): a superadmin self-joining a closed project chat keeps a hidden relation
		// (admin-access read grant, not project membership) so it is never promoted to a real member
		// and never mirrored into the project.
		if ($hiddenUserIds !== [])
		{
			return new EventResult(EventResult::SUCCESS, [
				'config' => $config->addHiddenUserIds($hiddenUserIds),
				'userIds' => $requestedUserIds,
			]);
		}

		$membersToAdd = self::resolveNewProjectMembers($projectId, $requestedUserIds);
		if ($membersToAdd->isEmpty())
		{
			return new EventResult(EventResult::SUCCESS, ['config' => $config, 'userIds' => $requestedUserIds]);
		}

		$deniedMemberIdsMap = self::getDeniedMemberIdsMap(
			$initiatorId,
			$projectId,
			$membersToAdd,
		);

		$allMembersAllowed = empty($deniedMemberIdsMap);
		if ($allMembersAllowed)
		{
			return new EventResult(EventResult::SUCCESS, ['config' => $config, 'userIds' => $requestedUserIds]);
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

	private static function getExistingActiveUserIds(array $userIds): array
	{
		$userIds = array_values(array_filter(array_unique(array_map('intval', $userIds)),
			static fn(int $id): bool => $id > 0,
		));
		$users = method_exists(UserFactory::class, 'getUsersByIds')
			? UserFactory::getInstance()->getUsersByIds($userIds)
			: array_map(static fn(int $id): User => User::getInstance($id), $userIds);
		$validUserIds = [];
		foreach ($users as $user)
		{
			if ($user->isExist() && $user->isActive())
			{
				$validUserIds[] = (int)$user->getId();
			}
		}

		return $validUserIds;
	}

	/**
	 * Non-members can only self-join a closed project chat by admin access: only the acting user
	 * (self-join, initiator === added user) who is not a project member yet passes the authoritative
	 * read grant (superadmin under admin mode). Invited users (initiator !== added user), sharing-link
	 * joins and existing members are excluded so their normal membership flow is untouched. The set is
	 * returned only when every requested user matches, so permission checks are never skipped for a
	 * mixed add.
	 *
	 * @param int[] $requestedUserIds
	 * @return int[]
	 */
	private static function resolveAdminAccessSelfJoinUserIds(
		int $initiatorId,
		int $projectId,
		array $requestedUserIds,
	): array
	{
		if ($initiatorId <= 0 || $requestedUserIds === [])
		{
			return [];
		}

		$existingMemberIdsMap = array_flip(self::resolveExistingMemberIds($projectId, $requestedUserIds));
		$accessService = Container::getInstance()->getProjectAccessService();

		$hiddenUserIds = [];
		foreach ($requestedUserIds as $userId)
		{
			$userId = (int)$userId;
			if (
				$userId <= 0
				|| $userId !== $initiatorId
				|| isset($existingMemberIdsMap[$userId])
				|| !$accessService->canRead($userId, $projectId)
			)
			{
				return [];
			}

			$hiddenUserIds[] = $userId;
		}

		return $hiddenUserIds;
	}

	private static function shouldHideHistory(int $projectId): bool
	{
		$options
			= Container::getInstance()
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
