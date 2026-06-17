<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Repository;

use Bitrix\Main\Type\Collection;

final class InMemoryUserRepository implements UserRepositoryInterface
{
	private UserRepositoryInterface $userRepository;

	/**
	 * @var array<int, array<string, mixed>>
	 */
	protected array $cache = [];
	protected array $existenceCache = [];

	public function __construct(UserRepository $userRepository)
	{
		$this->userRepository = $userRepository;
	}

	public function getByIds(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		Collection::normalizeArrayValuesByInt($userIds, false);
		$userIds = array_values(array_unique($userIds));

		if (empty($userIds))
		{
			return [];
		}

		$notStoredIds = array_values(array_diff($userIds, array_keys($this->cache)));

		if (!empty($notStoredIds))
		{
			$fetched = $this->userRepository->getByIds($notStoredIds);

			foreach ($fetched as $user)
			{
				$userId = (int)($user['ID'] ?? 0);
				if ($userId > 0)
				{
					$this->cache[$userId] = $user;
					$this->existenceCache[$userId] = true;
				}
			}
		}

		$result = [];
		foreach ($userIds as $userId)
		{
			if (isset($this->cache[$userId]))
			{
				$result[] = $this->cache[$userId];
			}
		}

		return $result;
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

		if (isset($this->cache[$userId]))
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
