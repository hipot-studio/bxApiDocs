<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Services\Share;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Raises the reports instance for a guest who opened a public link.
 *
 * A guest has no employee behind them, so nobody else is going to open the instance for them.
 * The attempt is therefore made by the guest path itself, but at most once per WINDOW_SECONDS
 * per portal: a link posted in a chat brings hundreds of guests at the same moment, and every
 * one of them would otherwise send a startup request of its own.
 */
final class GuestInstanceWakeupService
{
	public const WINDOW_SECONDS = 60;

	public const LAST_ATTEMPT_KEY = 'biconnector.share.instance_wakeup_attempt';

	private const LOCK_KEY = 'biconnector_share_instance_wakeup';

	private readonly StorageInterface $storage;

	public function __construct(?StorageInterface $storage = null)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	/**
	 * @return string The instance status, read again after the attempt.
	 */
	public function wakeUpIfNeeded(): string
	{
		$status = SupersetInitializer::getSupersetStatus();
		if (!$this->isWakeUpApplicable($status))
		{
			return $status;
		}

		// Reading the window and closing it are two statements, so the window is read only under the lock:
		// a guest that read it before the lock could find it closed by the time the lock is theirs. The others
		// of a burst are refused right away and answer with the status they already read.
		$connection = Application::getConnection();
		if (!$connection->lock(self::LOCK_KEY, 0))
		{
			return $status;
		}

		try
		{
			// The attempt is marked before the call, not after it: a request that dies inside the startup
			// would otherwise leave the window open and let every following guest start another one.
			if (!$this->isWindowOpen() || !$this->markAttempt())
			{
				return $status;
			}

			SupersetInitializer::initializeOrCheckSupersetStatus();
		}
		finally
		{
			$connection->unlock(self::LOCK_KEY);
		}

		return SupersetInitializer::getSupersetStatus();
	}

	/**
	 * An instance is raised only from the states it can come back from. The states of a missing or
	 * deleted instance are not among them: creating one is an employee's decision, never a guest's.
	 */
	private function isWakeUpApplicable(string $status): bool
	{
		return $status === SupersetInitializer::SUPERSET_STATUS_ERROR
			|| $status === SupersetInitializer::SUPERSET_STATUS_LOAD
		;
	}

	/**
	 * The mark is kept in the storage rather than in an option: options are loaded once per request, so
	 * a mark left by another guest after that would not be seen under the lock.
	 *
	 * The mark is read from the primary: the lock is taken there, and a replica lagging behind it may not
	 * hold the mark the previous holder of the lock has just left.
	 *
	 * A storage that is not answering keeps the window closed: without the mark nothing would hold the
	 * following guests back, and the instance is still raised by the portal pages of the employees.
	 */
	private function isWindowOpen(): bool
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);

		try
		{
			$lastAttempt = (int)$this->storage->get(self::LAST_ATTEMPT_KEY, 0);
		}
		catch (\Throwable)
		{
			return false;
		}
		finally
		{
			$pool->useMasterOnly(false);
		}

		return (time() - $lastAttempt) > self::WINDOW_SECONDS;
	}

	private function markAttempt(): bool
	{
		try
		{
			return $this->storage->set(self::LAST_ATTEMPT_KEY, time(), self::WINDOW_SECONDS);
		}
		catch (\Throwable)
		{
			return false;
		}
	}
}
