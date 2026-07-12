<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\Trait;

use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;

trait ResolveProjectMembersTrait
{
	protected static function resolveNewProjectMembers(
		int $projectId,
		array $userIds,
		bool $includeInvited = false,
	): MemberEntityCollection
	{
		$existingIdsMap = self::getExistingMemberIdsMap($projectId);

		$newIds = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0 && !isset($existingIdsMap[$userId]))
			{
				$newIds[] = $userId;
			}
		}

		if (empty($newIds))
		{
			return new MemberEntityCollection();
		}

		$memberEntityMapper = Container::getInstance()->get(MemberEntityMapper::class);
		$newMembers = $memberEntityMapper->fromUserIds($newIds);

		if ($includeInvited)
		{
			return $newMembers;
		}

		$projectMemberRepository = Container::getInstance()->getProjectMemberRepository();

		$invitedUsers = $projectMemberRepository->getInvitedUserIds($projectId, $newIds);

		if ($invitedUsers->count() === count($newIds))
		{
			return new MemberEntityCollection();
		}

		return $newMembers->diff($invitedUsers);
	}

	protected static function resolveExistingProjectMembers(int $projectId, array $userIds): MemberEntityCollection
	{
		$existingMemberIds = self::resolveExistingMemberIds($projectId, $userIds);

		$memberEntityMapper = Container::getInstance()->get(MemberEntityMapper::class);

		return $memberEntityMapper->fromUserIds($existingMemberIds);
	}

	/**
	 * @return int[]
	 */
	protected static function resolveExistingMemberIds(int $projectId, array $userIds): array
	{
		$existingIdsMap = self::getExistingMemberIdsMap($projectId);

		$matchedIds = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0 && isset($existingIdsMap[$userId]))
			{
				$matchedIds[] = $userId;
			}
		}

		return $matchedIds;
	}

	protected static function excludeDeniedMembers(array $requestedUserIds, array $deniedIdsMap): array
	{
		$allowedIds = [];
		foreach ($requestedUserIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0 && !isset($deniedIdsMap[$userId]))
			{
				$allowedIds[] = $userId;
			}
		}

		return $allowedIds;
	}

	private static function getExistingMemberIdsMap(int $projectId): array
	{
		$projectMemberRepository = Container::getInstance()->getProjectMemberRepository();

		$existingMemberIds = $projectMemberRepository->getMemberUserIds($projectId);

		return array_flip($existingMemberIds);
	}
}
