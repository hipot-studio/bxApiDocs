<?php

declare(strict_types=1);

namespace Bitrix\Main\Messenger\Internals\Storage\Db;

use Bitrix\Main\Application;
use Bitrix\Main\Messenger\Entity\MessageBox;
use Bitrix\Main\Messenger\Entity\MessageBoxCollection;
use Bitrix\Main\Messenger\Internals\Exception\Storage\PersistenceException;
use Bitrix\Main\Messenger\Internals\Storage\Db\Model\MessageStatus;
use Bitrix\Main\Messenger\Internals\Storage\StorageInterface;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

class DbStorage implements StorageInterface
{
	private const LOCK_KEY = 'queueLock';

	private const REQUEUE_CACHE_TTL = 600;
	private const REQUEUE_THRESHOLD_SECONDS = 172800; // 2 days

	private MessageRepository $repository;

	private static bool $locked = false;

	public function __construct(Entity $tableEntity)
	{
		$this->repository = new MessageRepository($tableEntity);

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

		$this->processRequeue();

		$cache->set($key, time() + self::REQUEUE_CACHE_TTL);
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
		if (!$this->lock())
		{
			return [];
		}

		$messageBoxes = $this->repository->getReadyMessagesOfQueue($queueId, $limit);

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
	}

	/**
	 * @throws PersistenceException
	 */
	public function delete(MessageBox $messageBox): void
	{
		$this->repository->delete($messageBox);
	}

	private function lock(): bool
	{
		if (self::$locked)
		{
			return self::$locked;
		}

		return self::$locked = Application::getConnection()->lock(self::LOCK_KEY);
	}

	private function unlock(): void
	{
		if (self::$locked)
		{
			Application::getConnection()->unlock(self::LOCK_KEY);

			self::$locked = false;
		}
	}
}
