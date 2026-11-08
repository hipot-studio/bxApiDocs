<?php

declare(strict_types=1);

namespace Bitrix\Main\Messenger\Internals\Storage\Db;

use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Messenger\Entity\MessageBox;
use Bitrix\Main\Messenger\Entity\MessageBoxCollection;
use Bitrix\Main\Messenger\Internals\Config\QueueConfigRegistry;
use Bitrix\Main\Messenger\Internals\Exception\Storage\PersistenceException;
use Bitrix\Main\Messenger\Internals\Storage\Db\Model\MessageStatus;
use Bitrix\Main\Messenger\Internals\Storage\StorageInterface;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

class DbStorage implements StorageInterface
{
	private const LOCK_KEY_PREFIX = 'queueLock_';
	private const REQUEUE_LOCK_PREFIX = 'main_messenger_requeue_';

	private const REQUEUE_CACHE_TTL = 600;
	private const REQUEUE_THRESHOLD_SECONDS = 172800; // 2 days

	private const IDLE_CACHE_PREFIX = 'main_messenger_idle_';
	// With cache use_lock (or actual_data => false) clean() leaves the old value readable by other processes
	// for up to 60 seconds, so there an idle mark may outlive save() by that long
	private const IDLE_CACHE_TTL = 60;
	private const IDLE_CACHE_TTL_SPREAD = 30;

	private MessageRepository $repository;

	private QueueConfigRegistry $queueRegistry;

	private readonly string $lockKey;

	private readonly string $idleCachePrefix;

	/** @var array<string, bool> lock state per queue table */
	private static array $locked = [];

	/** @var array<string, bool> idle cache keys to clean once more after the sender's transaction is closed */
	private static array $pendingIdleResets = [];

	private static bool $idleResetJobScheduled = false;

	public function __construct(Entity $tableEntity)
	{
		$this->repository = new MessageRepository($tableEntity);
		$this->queueRegistry = ServiceLocator::getInstance()->get(QueueConfigRegistry::class);

		$this->lockKey = self::LOCK_KEY_PREFIX . $tableEntity->getDBTableName();
		$this->idleCachePrefix = self::IDLE_CACHE_PREFIX . $tableEntity->getDBTableName() . '_';

		$this->requeueStaleMessages($tableEntity);
	}

	public function __destruct()
	{
		$this->unlock();
	}

	private function requeueStaleMessages(Entity $tableEntity): void
	{
		$cache = Application::getInstance()->getManagedCache();

		$key = 'main_messenger_requeue_' . $tableEntity->getDBTableName();

		if ($cache->read(self::REQUEUE_CACHE_TTL, $key))
		{
			$expiredAt = (int)$cache->get($key);

			if ($expiredAt >= time())
			{
				return;
			}
		}

		// Save the cache BEFORE starting the job so that parallel processes skip the attempt
		// and don't try to execute the same UPDATE at the same time (thundering herd)
		$cache->setImmediate($key, time() + self::REQUEUE_CACHE_TTL);

		// In case of distributed environment or cache invalidation we need the lock
		$connection = Application::getConnection();

		$lockName = self::REQUEUE_LOCK_PREFIX . $tableEntity->getDBTableName();

		if (!$connection->lock($lockName, 0))
		{
			return;
		}

		try
		{
			$this->processRequeue();
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	private function processRequeue(): void
	{
		$thresholdDate = DateTime::createFromTimestamp(time() - self::REQUEUE_THRESHOLD_SECONDS);

		$this->repository->requeueStaleMessages($thresholdDate);
	}

	/**
	 * @throws PersistenceException
	 * @throws SystemException
	 */
	public function getOneByQueue(string $queueId): ?MessageBox
	{
		if (!$this->lock())
		{
			return null;
		}

		$messageBox = $this->repository->getOneByQueue($queueId);

		if ($messageBox !== null)
		{
			$this->repository->updateStatus(new MessageBoxCollection($messageBox), MessageStatus::Processing);
		}

		$this->unlock();

		return $messageBox;
	}

	/**
	 * @throws PersistenceException
	 * @throws SystemException
	 */
	public function getReadyMessagesOfQueue(string $queueId, int $limit = 500): iterable
	{
		self::flushPendingIdleResets();

		if (!$this->hasReadyMessages($queueId))
		{
			return [];
		}

		if (!$this->lock())
		{
			return [];
		}

		$queueConfig = $this->queueRegistry->getQueueConfig($queueId);

		$messageBoxes = $this->repository->getReadyMessagesOfQueue($queueId, $limit, $queueConfig->totalProcessingLimit);

		$this->repository->updateStatus($messageBoxes, MessageStatus::Processing);

		$this->unlock();

		return $messageBoxes;
	}

	/**
	 * @throws PersistenceException
	 */
	public function save(MessageBox $messageBox): void
	{
		$this->repository->save($messageBox);

		$this->resetIdleMark($this->idleCachePrefix . $messageBox->getQueueId());
	}

	private function resetIdleMark(string $cacheKey): void
	{
		$application = Application::getInstance();
		$application->getManagedCache()->clean($cacheKey);

		// Inside an outer transaction the clean comes before the commit, and a worker that does not see
		// the message yet may mark the queue idle again. The second clean runs once the transaction is closed:
		// at the end of the hit, or on the next queue check of a long-running CLI worker.
		self::$pendingIdleResets[$cacheKey] = true;

		if (!self::$idleResetJobScheduled)
		{
			self::$idleResetJobScheduled = true;
			$application->addBackgroundJob(static function (): void {
				self::$idleResetJobScheduled = false;
				self::flushPendingIdleResets();
			});
		}
	}

	private static function flushPendingIdleResets(): void
	{
		if (!self::$pendingIdleResets)
		{
			return;
		}

		$cache = Application::getInstance()->getManagedCache();
		foreach (array_keys(self::$pendingIdleResets) as $cacheKey)
		{
			$cache->clean($cacheKey);
		}

		self::$pendingIdleResets = [];
	}

	/**
	 * @throws PersistenceException
	 */
	public function delete(MessageBox $messageBox): void
	{
		$this->repository->delete($messageBox);
	}

	/**
	 * Checks the queue without the table lock. Only a queue without new messages is cached as idle:
	 * delayed messages may become available outside the broker, so they are checked on every call.
	 *
	 * @throws SystemException
	 */
	private function hasReadyMessages(string $queueId): bool
	{
		$cache = Application::getInstance()->getManagedCache();
		$cacheKey = $this->idleCachePrefix . $queueId;

		// Bypasses the in-process copy so that a long-running CLI worker sees clean() from save() at once
		if ((int)$cache->getImmediate(self::IDLE_CACHE_TTL + self::IDLE_CACHE_TTL_SPREAD, $cacheKey) >= time())
		{
			return false;
		}

		// Registers the read time: setImmediate() skips the write if save() cleans the key after this point
		$cache->read(self::IDLE_CACHE_TTL + self::IDLE_CACHE_TTL_SPREAD, $cacheKey);

		$nextAvailableAt = $this->repository->getNextAvailableAt($queueId);

		if ($nextAvailableAt === null)
		{
			// A stable per-queue offset keeps the queues from being rechecked all on the same hit
			$ttl = self::IDLE_CACHE_TTL + crc32($cacheKey) % self::IDLE_CACHE_TTL_SPREAD;
			$cache->setImmediate($cacheKey, time() + $ttl);

			return false;
		}

		return $nextAvailableAt->getTimestamp() <= time();
	}

	private function lock(): bool
	{
		if (self::$locked[$this->lockKey] ?? false)
		{
			return true;
		}

		return self::$locked[$this->lockKey] = Application::getConnection()->lock($this->lockKey);
	}

	private function unlock(): void
	{
		if (self::$locked[$this->lockKey] ?? false)
		{
			Application::getConnection()->unlock($this->lockKey);

			unset(self::$locked[$this->lockKey]);
		}
	}
}
