<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity;

class InMemoryGroupRepository implements GroupRepositoryInterface
{
	protected GroupRepositoryInterface $groupRepository;

	protected Entity\GroupCollection $cache;
	/** @var Entity\UserCollection[] */
	protected array $membersCache = [];
	/** @var Entity\UserCollection[] */
	protected array $filteredMembersCache = [];
	protected array $typeCache = [];
	/** @var array<string, int[]> */
	protected array $groupIdsByTaskIdsCache = [];

	public function __construct(GroupRepository $groupRepository)
	{
		$this->groupRepository = $groupRepository;
		$this->cache = new Entity\GroupCollection();
	}

	public function getById(int $id): ?Entity\Group
	{
		if (!$this->cache->findOneById($id))
		{
			$group = $this->groupRepository->getById($id);
			if ($group !== null)
			{
				$this->cache->add($group);
			}
		}

		return $this->cache->findOneById($id);
	}

	public function getMembers(int $id): Entity\UserCollection
	{
		if (!isset($this->membersCache[$id]))
		{
			$this->membersCache[$id] = $this->groupRepository->getMembers($id);
		}

		return $this->membersCache[$id];
	}

	public function getMemberRoles(array $userIds, int $groupId): Entity\UserCollection
	{
		$data = array_map(
			static fn(int $id): array => [
				'id' => $id,
				'role' => false,
			],
			$userIds
		);

		$members = Entity\UserCollection::mapFromArray($data);

		if (isset($this->membersCache[$groupId]))
		{
			return $this->replaceRoles($members, $this->membersCache[$groupId]);
		}

		if (isset($this->filteredMembersCache[$groupId]))
		{
			$notCachedUserIds = array_diff($userIds, $this->filteredMembersCache[$groupId]->getIdList());
			if (!empty($notCachedUserIds))
			{
				$fetchedMembers = $this->groupRepository->getMemberRoles($notCachedUserIds, $groupId);
				$this->filteredMembersCache[$groupId]->merge($fetchedMembers);
			}

			return $this->replaceRoles($members, $this->filteredMembersCache[$groupId]);
		}

		$this->filteredMembersCache[$groupId] = $this->groupRepository->getMemberRoles($userIds, $groupId);

		return $this->replaceRoles($members, $this->filteredMembersCache[$groupId]);
	}

	public function getType(int $id): ?string
	{
		$group = $this->cache->findOneById($id);
		if ($group !== null)
		{
			$this->typeCache[$id] = $group->type;

			return $this->typeCache[$id];
		}

		if (!isset($this->typeCache[$id]))
		{
			$this->typeCache[$id] = $this->groupRepository->getType($id);
		}

		return $this->typeCache[$id];
	}

	public function getByIds(array $ids): Entity\GroupCollection
	{
		$groups = Entity\GroupCollection::mapFromIds($ids);
		$stored = $this->cache->findAllByIds($ids);

		$notStoredIds = $groups->diff($stored)->getIdList();

		if (empty($notStoredIds))
		{
			return $stored;
		}

		$groups = $this->groupRepository->getByIds($ids);

		$this->cache->merge($groups);

		return $groups;
	}

	public function getGroupIdsByTaskIds(array $taskIds): array
	{
		if (empty($taskIds))
		{
			return [];
		}

		$indices = array_unique(array_map('intval', $taskIds));
		sort($indices);

		$key = implode(',', $indices);

		if (!array_key_exists($key, $this->groupIdsByTaskIdsCache))
		{
			$this->groupIdsByTaskIdsCache[$key] = $this->groupRepository->getGroupIdsByTaskIds($taskIds) ?? [];
		}

		return $this->groupIdsByTaskIdsCache[$key] ?? [];
	}

	protected function replaceRoles(
		Entity\UserCollection $members,
		Entity\UserCollection $cache,
	): Entity\UserCollection
	{
		$ids = $members->getIds();

		return $members->replaceMulti($cache->filter(
			static fn (Entity\User $user) => in_array($user->getId(), $ids, true)
		));
	}
}
