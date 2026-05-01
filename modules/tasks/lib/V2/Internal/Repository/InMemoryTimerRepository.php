<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity;

class InMemoryTimerRepository implements TimerRepositoryInterface
{
	/** @var array <string, Entity\Task\Timer> */
	private array $cache = [];

	public function __construct(
		private readonly TimerRepository $timerRepository,
	)
	{

	}

	public function get(int $userId, int $taskId = 0): ?Entity\Task\Timer
	{
		$timer = $this->getFromCache($userId, $taskId);
		if ($timer !== null)
		{
			return $timer;
		}

		$timer = $this->timerRepository->get($userId, $taskId);
		if ($timer !== null)
		{
			$this->addToCache($timer);
		}

		return $timer;
	}

	public function getRunningTimersByTaskId(int $taskId): Entity\Task\TimerCollection
	{
		$collection = $this->timerRepository->getRunningTimersByTaskId($taskId);

		foreach ($collection as $timer)
		{
			$this->addToCache($timer);
		}

		return $collection;
	}

	public function getByUserIds(array $userIds, int $taskId): Entity\Task\TimerCollection
	{
		$userIds = array_values(
			array_unique(
				array_filter(
					array_map('intval', $userIds),
					static fn (int $userId): bool => $userId > 0,
				)
			)
		);

		if (empty($userIds))
		{
			return new Entity\Task\TimerCollection();
		}

		$result = new Entity\Task\TimerCollection();

		$notCachedUserIds = [];
		foreach ($userIds as $userId)
		{
			$timer = $this->getFromCache((int)$userId, $taskId);
			if ($timer !== null)
			{
				$result->add($timer);
			}
			else
			{
				$notCachedUserIds[] = $userId;
			}
		}

		if (!empty($notCachedUserIds))
		{
			$notCachedTimerCollection = $this->timerRepository->getByUserIds($notCachedUserIds, $taskId);
			foreach ($notCachedTimerCollection as $timer)
			{
				$this->addToCache($timer);
				$result->add($timer);
			}
		}

		return $result;
	}

	public function add(Entity\Task\Timer $timer): void
	{
		$this->removeFromCache($timer);
		$this->timerRepository->add($timer);
	}

	public function upsert(Entity\Task\Timer $timer): void
	{
		$this->removeFromCache($timer);
		$this->timerRepository->upsert($timer);
	}

	public function update(Entity\Task\Timer $timer): void
	{
		if ($timer->userId <= 0)
		{
			return;
		}

		$this->removeFromCache($timer);
		$this->timerRepository->update($timer);
	}

	public function invalidateCache(): void
	{
		$this->cache = [];
	}

	private function getCacheKey(Entity\Task\Timer $timer): ?string
	{
		if ($timer->userId === null || $timer->taskId === null)
		{
			return null;
		}

		return sprintf('%s_%s', $timer->userId, $timer->taskId);
	}

	private function addToCache(Entity\Task\Timer $timer): void
	{
		$key = $this->getCacheKey($timer);
		if ($key === null)
		{
			return;
		}

		$this->cache[$key] = $timer;
	}

	private function getFromCache(int $userId, int $taskId): ?Entity\Task\Timer
	{
		$key = $this->getCacheKey(new Entity\Task\Timer($userId, $taskId));
		if ($key === null)
		{
			return null;
		}

		return $this->cache[$key] ?? null;
	}

	private function removeFromCache(Entity\Task\Timer $timer): void
	{
		$key = $this->getCacheKey($timer);
		if ($key === null)
		{
			return;
		}

		unset($this->cache[$key]);
	}
}