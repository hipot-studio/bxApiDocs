<?php

namespace Bitrix\Crm\Service\RuntimeState;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Position of a round-robin responsible queue: the user who was assigned last.
 *
 * The position is runtime state, not a setting: it lives in the persistent storage and expires
 * when the queue is not used for the TTL. A missing position starts the queue from its first user.
 */
final class ResponsibleQueueCursor
{
	private const WEBFORM_SCOPE = 'crm.webform.responsible_queue';

	// Every write moves the expiration forward, so only a queue unused for a year loses its position.
	private const TTL = 365 * 86400;

	private readonly StorageInterface $storage;

	public function __construct(
		private readonly string $scope,
		?StorageInterface $storage = null,
	)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	public static function forWebForm(): self
	{
		return new self(self::WEBFORM_SCOPE);
	}

	public function get(int $queueId): ?int
	{
		// A replica lagging behind would give two consecutive assignments to the same user.
		$connectionPool = Application::getInstance()->getConnectionPool();
		$connectionPool->useMasterOnly(true);
		try
		{
			$userId = (int)$this->storage->get($this->getKey($queueId));
		}
		finally
		{
			$connectionPool->useMasterOnly(false);
		}

		return $userId > 0 ? $userId : null;
	}

	public function set(int $queueId, int $userId): void
	{
		$this->storage->set($this->getKey($queueId), $userId, self::TTL);
	}

	public function delete(int $queueId): void
	{
		$this->storage->delete($this->getKey($queueId));
	}

	private function getKey(int $queueId): string
	{
		return $this->scope . '.' . $queueId;
	}
}
