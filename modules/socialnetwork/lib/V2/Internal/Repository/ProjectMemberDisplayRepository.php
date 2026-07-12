<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Socialnetwork\V2\Internal\Entity\User;
use Bitrix\Socialnetwork\V2\Internal\Entity\User\Role;
use Bitrix\Socialnetwork\V2\Internal\Entity\UserCollection;
use Bitrix\Socialnetwork\V2\Internal\Service\UserServiceInterface;
use Bitrix\Socialnetwork\UserToGroupTable;

class ProjectMemberDisplayRepository
{
	private const VISIBLE_HEADS = 3;
	private const VISIBLE_MEMBERS = 3;

	public function __construct(
		private readonly UserServiceInterface $userService,
	)
	{
	}

	/**
	 * @param int[] $projectIds
	 * @return array{members: array<int, UserCollection>, headsCount: array<int, int>}
	 */
	public function getByProjectIds(array $projectIds): array
	{
		if (empty($projectIds))
		{
			return ['members' => [], 'headsCount' => []];
		}

		$allRoleMap = $this->loadRoleMap($projectIds, UserToGroupTable::getRolesMember());
		$displayData = $this->buildDisplayData($projectIds, $allRoleMap);
		$displayRoleMap = $displayData['displayRoleMap'];
		$userIds = $this->collectUserIds($displayRoleMap);

		$usersById = empty($userIds)
			? []
			: $this->userService->getUsers($userIds)->indexById()
		;

		return $this->buildDisplayResult(
			projectIds: $projectIds,
			displayRoleMap: $displayRoleMap,
			usersById: $usersById,
			headsCountByProject: $displayData['headsCountByProject'],
		);
	}

	public function getPagedMembers(int $projectId, MemberFilterType $type, int $limit, int $offset): UserCollection
	{
		$roles = $this->resolveRolesForFilter($type);

		$query = UserToGroupTable::query()
			->setSelect(['USER_ID', 'ROLE'])
			->where('GROUP_ID', $projectId)
			->whereIn('ROLE', $roles)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->setOffset($offset)
		;

		$userRoles = [];
		$rows = $query->exec();
		while ($row = $rows->fetchObject())
		{
			$userRoles[$row->getUserId()] = Role::tryFrom($row->getRole());
		}

		if (empty($userRoles))
		{
			return new UserCollection();
		}

		$users = $this->userService->getUsers(array_keys($userRoles));

		return $this->buildUserCollectionFromIndex($userRoles, $users->indexById());
	}

	/**
	 * @param string[] $roles
	 * @return array<int, array<int, Role|null>> projectId => [userId => Role]
	 */
	private function loadRoleMap(array $projectIds, array $roles): array
	{
		$query = UserToGroupTable::query()
			->setSelect(['USER_ID', 'GROUP_ID', 'ROLE'])
			->whereIn('GROUP_ID', $projectIds)
			->whereIn('ROLE', $roles)
		;

		$rows = $query->exec();

		$roleMap = [];
		while ($row = $rows->fetchObject())
		{
			$roleMap[$row->getGroupId()][$row->getUserId()] = Role::tryFrom($row->getRole());
		}

		return $roleMap;
	}

	/**
	 * @param int[] $projectIds
	 * @param array<int, array<int, Role|null>> $allRoleMap
	 * @return array{
	 *     displayRoleMap: array<int, array<int, Role|null>>,
	 *     headsCountByProject: array<int, int>
	 * }
	 */
	private function buildDisplayData(array $projectIds, array $allRoleMap): array
	{
		$displayRoleMap = [];
		$headsCountByProject = array_fill_keys($projectIds, 0);

		foreach ($projectIds as $projectId)
		{
			$heads = [];
			$members = [];

			foreach (($allRoleMap[$projectId] ?? []) as $userId => $role)
			{
				if ($role === Role::Owner || $role === Role::Moderator)
				{
					$heads[$userId] = $role;

					$headsCountByProject[$projectId]++;

					continue;
				}

				$members[$userId] = $role;
			}

			$displayRoleMap[$projectId] =
				$this->takeVisibleUsers($this->moveOwnerToFront($heads), self::VISIBLE_HEADS)
				+ $this->takeVisibleUsers($members, self::VISIBLE_MEMBERS)
			;
		}

		return [
			'displayRoleMap' => $displayRoleMap,
			'headsCountByProject' => $headsCountByProject,
		];
	}

	/**
	 * @param array<int, array<int, Role|null>> $displayRoleMap
	 * @param array<int, User> $usersById
	 * @param array<int, int> $headsCountByProject
	 * @return array{members: array<int, UserCollection>, headsCount: array<int, int>}
	 */
	private function buildDisplayResult(
		array $projectIds,
		array $displayRoleMap,
		array $usersById,
		array $headsCountByProject,
	): array
	{
		$members = [];

		foreach ($projectIds as $projectId)
		{
			$members[$projectId] = $this->buildUserCollectionFromIndex(
				$displayRoleMap[$projectId] ?? [],
				$usersById,
			);
		}

		return [
			'members' => $members,
			'headsCount' => $headsCountByProject,
		];
	}

	/**
	 * @param array<int, Role|null> $heads
	 * @return array<int, Role|null>
	 */
	private function moveOwnerToFront(array $heads): array
	{
		$sortedHeads = [];

		foreach ($heads as $userId => $role)
		{
			if ($role === Role::Owner)
			{
				$sortedHeads = [$userId => $role] + $sortedHeads;

				continue;
			}

			$sortedHeads[$userId] = $role;
		}

		return $sortedHeads;
	}

	/**
	 * @param array<int, Role|null> $roleMap
	 * @return array<int, Role|null>
	 */
	private function takeVisibleUsers(array $roleMap, int $limit): array
	{
		return array_slice($roleMap, 0, $limit, true);
	}

	/**
	 * @param array<int, Role|null> $roleMap
	 * @param array<int, User> $usersById
	 */
	private function buildUserCollectionFromIndex(array $roleMap, array $usersById): UserCollection
	{
		$items = [];

		foreach ($roleMap as $userId => $role)
		{
			$user = $usersById[$userId] ?? null;
			if ($user === null || $role === null)
			{
				continue;
			}

			$items[] = $user->cloneWith(['role' => $role]);
		}

		return new UserCollection(...$items);
	}

	/**
	 * @param array<int, array<int, Role|null>> $displayRoleMap
	 * @return int[]
	 */
	private function collectUserIds(array $displayRoleMap): array
	{
		$userIds = [];

		foreach ($displayRoleMap as $projectMembers)
		{
			foreach ($projectMembers as $userId => $_role)
			{
				$userIds[$userId] = $userId;
			}
		}

		return array_values($userIds);
	}

	/**
	 * @return string[]
	 */
	private function resolveRolesForFilter(MemberFilterType $type): array
	{
		return match ($type) {
			MemberFilterType::Heads => [
				UserToGroupTable::ROLE_OWNER,
				UserToGroupTable::ROLE_MODERATOR,
			],
			MemberFilterType::Members => [UserToGroupTable::ROLE_USER],
			default => UserToGroupTable::getRolesMember(),
		};
	}
}
