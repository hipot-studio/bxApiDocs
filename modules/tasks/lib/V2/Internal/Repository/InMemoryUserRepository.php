<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\V2\Internal\Entity;

class InMemoryUserRepository implements UserRepositoryInterface
{
	private UserRepositoryInterface $userRepository;

	protected Entity\UserCollection $cache;
	protected ?Entity\UserCollection $adminCache = null;
	protected array $existenceCache = [];
	protected array $nameCache = [];

	public function __construct(UserRepository $userRepository)
	{
		$this->userRepository = $userRepository;
		$this->cache = new Entity\UserCollection();
	}

	public function getByIds(array $userIds): Entity\UserCollection
	{
		$users = Entity\UserCollection::mapFromIds($userIds);
		$stored = $this->cache->findAllByIds($userIds);

		$notStoredIds = $users->diff($stored)->getIdList();

		if (empty($notStoredIds))
		{
			return $stored;
		}

		$users = $this->userRepository->getByIds($notStoredIds);

		$this->cache->merge($users);
		foreach ($users as $user) {
			$this->existenceCache[$user->getId()] = true;
			$this->nameCache[$user->getId()] = $user->name;
		}

		return $this->cache->findAllByIds($userIds);
	}

	public function getNamesByIds(array $userIds): array
	{
		$result = [];

		if (empty($userIds))
		{
			return $result;
		}

		Collection::normalizeArrayValuesByInt($userIds, false);

		if (empty($userIds))
		{
			return $result;
		}

		$missingUserIdMap = array_flip($userIds);

		foreach ($missingUserIdMap as $userId => $_)
		{
			if (isset($this->nameCache[$userId]))
			{
				$result[$userId] = $this->nameCache[$userId];

				unset($missingUserIdMap[$userId]);
			}
		}

		if (empty($missingUserIdMap))
		{
			return $result;
		}

		$usersInCache = $this->cache->findAllByIds(array_keys($missingUserIdMap));
		foreach ($usersInCache as $user)
		{
			$userId = $user->getId();
			$userName = $user->name;

			$this->nameCache[$userId] = $userName;
			$this->existenceCache[$userId] = true;

			$result[$userId] = $userName;

			unset($missingUserIdMap[$userId]);
		}

		if (empty($missingUserIdMap))
		{
			return $result;
		}

		$userNames = $this->userRepository->getNamesByIds(array_keys($missingUserIdMap));
		foreach ($userNames as $id => $name)
		{
			$this->nameCache[$id] = $name;
			$this->existenceCache[$id] = true;

			$result[$id] = $name;
		}

		return $result;
	}

	public function getAdmins(): Entity\UserCollection
	{
		if ($this->adminCache === null)
		{
			$this->adminCache = $this->userRepository->getAdmins();
		}

		return $this->adminCache;
	}

	public function isExists(int $userId): bool
	{
		if ($userId < 1)
		{
			return false;
		}

		if (!empty($this->existenceCache[$userId]))
		{
			return true;
		}

		$cached = $this->cache->findOneById($userId);

		if ($cached)
		{
			$this->existenceCache[$userId] = true;

			return true;
		}

		$isExists = $this->userRepository->isExists($userId);

		if (!$isExists)
		{
			return false;
		}

		$this->existenceCache[$userId] = true;

		return true;
	}
}
