<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity;

class InMemoryGroupRepository implements GroupRepositoryInterface
{
	private GroupRepositoryInterface $groupRepository;

	private array $cache = [];
	private array $membersCache = [];
	private array $typeCache = [];

	public function __construct(GroupRepository $groupRepository)
	{
		$this->groupRepository = $groupRepository;
	}

	public function getById(int $id): ?Entity\Group
	{
		if (!isset($this->cache[$id]))
		{
			$this->cache[$id] = $this->groupRepository->getById($id);
		}

		return $this->cache[$id];
	}

	public function getMembers(int $id): Entity\UserCollection
	{
		if (!isset($this->membersCache[$id]))
		{
			$this->membersCache[$id] = $this->groupRepository->getMembers($id);
		}

		return $this->membersCache[$id];
	}

	public function getType(int $id): ?string
	{
		if (isset($this->cache[$id]))
		{
			/** @var Entity\Group $group */
			$group = $this->cache[$id];

			$this->typeCache[$id] = $group?->type;

			return $this->typeCache[$id];
		}

		if (!isset($this->typeCache[$id]))
		{
			$this->typeCache[$id] = $this->groupRepository->getType($id);
		}

		return $this->typeCache[$id];
	}
}