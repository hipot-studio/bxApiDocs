<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Main\Type\Collection;

class InMemoryUserRepository implements UserRepositoryInterface
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

		$notStoredIds = array_diff($userIds, array_keys($this->cache));

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
}
