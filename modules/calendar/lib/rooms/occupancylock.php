<?php

namespace Bitrix\Calendar\Rooms;

use Bitrix\Main\Application;
use Bitrix\Main\Data\ConnectionPool;
use Bitrix\Main\DB\Connection;

final class OccupancyLock
{
	private const KEY_PREFIX = 'calendar.room.occupancy.';
	private const LOCK_ERROR_MESSAGE = 'Meeting room occupancy is being changed by another request.';

	private Connection $connection;
	private ?ConnectionPool $connectionPool;
	private array $acquiredLockNames = [];
	private array $additionalLocationConnectionCache = [];
	private bool $masterOnlyEnabled = false;

	public function __construct(?Connection $connection = null, ?ConnectionPool $connectionPool = null)
	{
		$this->connection = $connection ?? Application::getConnection();
		$this->connectionPool = $connectionPool;
	}

	public static function resolveRoomIds(
		mixed $oldLocation,
		mixed $newLocation,
		?array &$additionalLocationConnectionCache = null,
	): array
	{
		$roomIds = [];
		foreach ([$oldLocation, $newLocation] as $location)
		{
			$roomId = (int)(Util::parseLocation($location)['room_id'] ?? 0);
			if ($roomId <= 0)
			{
				continue;
			}

			$roomIds[$roomId] = true;
			if ($additionalLocationConnectionCache !== null && array_key_exists($roomId, $additionalLocationConnectionCache))
			{
				$additionalRoomIds = $additionalLocationConnectionCache[$roomId];
			}
			else
			{
				$additionalRoomIds = AccessibilityManager::getAdditionalLocationAccessibilityConnection($roomId);
				if ($additionalLocationConnectionCache !== null)
				{
					$additionalLocationConnectionCache[$roomId] = $additionalRoomIds;
				}
			}

			foreach ($additionalRoomIds as $additionalRoomId)
			{
				$roomIds[(int)$additionalRoomId] = true;
			}
		}

		$roomIds = array_values(array_filter(array_map('intval', array_keys($roomIds))));
		sort($roomIds, SORT_NUMERIC);

		return $roomIds;
	}

	public function resolveRoomIdsUsingCache(mixed $oldLocation, mixed $newLocation): array
	{
		return self::resolveRoomIds(
			$oldLocation,
			$newLocation,
			$this->additionalLocationConnectionCache,
		);
	}

	public function acquire(array $roomIds): void
	{
		$this->acquireLockNames($this->getLockNames($roomIds));
	}

	public function releaseRoomIds(array $roomIds): bool
	{
		return $this->releaseAcquiredLockNames($this->getLockNames($roomIds));
	}

	private function releaseAcquiredLockNames(array $lockNames): bool
	{
		$lockNames = array_values(array_intersect($lockNames, $this->acquiredLockNames));
		if ($lockNames === [])
		{
			return true;
		}

		$isReleased = $this->releaseLockNames($lockNames);
		$this->acquiredLockNames = array_values(array_diff($this->acquiredLockNames, $lockNames));
		if ($this->acquiredLockNames === [])
		{
			$isReleased = $this->disableMasterOnly() && $isReleased;
		}

		return $isReleased;
	}

	private function acquireLockNames(array $lockNames): void
	{
		$lockNames = array_values(array_diff($lockNames, $this->acquiredLockNames));
		$acquiredLockNames = $this->acquiredLockNames;

		if ($lockNames === [])
		{
			return;
		}

		if (!in_array($this->connection->getType(), ['mysql', 'pgsql'], true))
		{
			throw new OccupancyLockedException(self::LOCK_ERROR_MESSAGE);
		}

		try
		{
			if (!$this->masterOnlyEnabled)
			{
				$this->getConnectionPool()->useMasterOnly(true);
				$this->masterOnlyEnabled = true;
			}

			foreach ($lockNames as $lockName)
			{
				if (!$this->connection->lock($lockName))
				{
					throw new OccupancyLockedException(self::LOCK_ERROR_MESSAGE);
				}

				$this->acquiredLockNames[] = $lockName;
			}
		}
		catch (\Throwable $exception)
		{
			$newlyAcquiredLockNames = array_values(array_diff($this->acquiredLockNames, $acquiredLockNames));
			$this->releaseLockNames($newlyAcquiredLockNames);
			$this->acquiredLockNames = $acquiredLockNames;

			if ($this->acquiredLockNames === [])
			{
				$this->release();
			}

			throw $exception;
		}
	}

	public function hasAcquiredRoomIds(array $roomIds): bool
	{
		return array_diff($this->getLockNames($roomIds), $this->acquiredLockNames) === [];
	}

	public function release(): bool
	{
		$isReleased = $this->releaseLockNames($this->acquiredLockNames);
		$this->acquiredLockNames = [];

		$isReleased = $this->disableMasterOnly() && $isReleased;

		return $isReleased;
	}

	private function disableMasterOnly(): bool
	{
		if (!$this->masterOnlyEnabled)
		{
			return true;
		}

		try
		{
			$this->getConnectionPool()->useMasterOnly(false);

			return true;
		}
		catch (\Throwable)
		{
			return false;
		}
		finally
		{
			$this->masterOnlyEnabled = false;
		}
	}

	private function releaseLockNames(array $lockNames): bool
	{
		$isReleased = true;
		foreach (array_reverse($lockNames) as $lockName)
		{
			try
			{
				$isReleased = $this->connection->unlock($lockName) && $isReleased;
			}
			catch (\Throwable)
			{
				$isReleased = false;
			}
		}

		return $isReleased;
	}

	private function getLockNames(array $roomIds): array
	{
		$roomIds = array_values(array_unique(array_filter(array_map('intval', $roomIds))));
		sort($roomIds, SORT_NUMERIC);

		return array_map(static fn(int $roomId): string => self::KEY_PREFIX . $roomId, $roomIds);
	}

	private function getConnectionPool(): ConnectionPool
	{
		return $this->connectionPool ??= Application::getInstance()->getConnectionPool();
	}

	public function __destruct()
	{
		$this->release();
	}
}
