<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Internal\Service\Lock;

use Bitrix\Calendar\Core\Event\Event;
use Bitrix\Calendar\Synchronization\Internal\Exception\NoLogSynchronizerException;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;

/**
 * Fail-fast advisory lock that serializes the export of one recurrence series over one connection.
 *
 * The loser does not wait for the winner: it throws {@see NoLogSynchronizerException} so the queue
 * retries the message silently instead of blocking a worker on a contended connection/series pair.
 *
 * The lock lives as long as the DB connection holding it: once that connection drops, MySQL releases
 * the lock and the export degrades to its pre-lock behaviour instead of stalling the queue.
 */
class ExportLock
{
	// Namespaces the lock key; the core hashes it into a driver-safe name on its own.
	public const LOCK_NAME_PREFIX = 'calendar:sync:event-export:';

	public function getLockName(int $connectionId, int $seriesId): string
	{
		return self::LOCK_NAME_PREFIX . $connectionId . ':' . $seriesId;
	}

	/**
	 * A master and its instances share one key: exporting an instance sends the whole master to the
	 * vendor and rewrites the master EventConnection, so the series is the resource being locked.
	 *
	 * RECURRENCE_ID of an instance holds PARENT_ID of its master, not the master id
	 * (see EventRepository::getMasterEvent() and EventRepository::getEventInstances()), so the master
	 * resolves through its own PARENT_ID and a standalone event through its id.
	 */
	public function getEventSeriesId(Event $event): int
	{
		return (int)($event->getRecurrenceId() ?: $event->getParentId() ?: $event->getId());
	}

	/**
	 * @throws NoLogSynchronizerException
	 *
	 * @see withLock() for the contract callers must follow
	 */
	public function withEventLock(int $connectionId, Event $event, callable $critical): void
	{
		$this->withLock($connectionId, $this->getEventSeriesId($event), $critical);
	}

	/**
	 * Callers resolve the vendor gateway before entering the lock: resolving it deactivates a broken
	 * connection, and that side effect must not run inside the critical section.
	 *
	 * @throws NoLogSynchronizerException
	 */
	public function withLock(int $connectionId, int $seriesId, callable $critical): void
	{
		$name = $this->getLockName($connectionId, $seriesId);
		$connection = $this->getConnection();

		// Default timeout 0 => acquire immediately or fail, never wait.
		if ($connection->lock($name))
		{
			try
			{
				$critical();
			}
			finally
			{
				$connection->unlock($name);
			}

			return;
		}

		throw new NoLogSynchronizerException('event export is locked: ' . $name);
	}

	protected function getConnection(): Connection
	{
		return Application::getConnection();
	}
}
