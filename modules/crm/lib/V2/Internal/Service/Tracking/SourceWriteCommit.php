<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\UI\Webpack\CallTracker;
use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceRefusal;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingSourceRefusedException;
use Bitrix\Crm\V2\Internal\Repository\Tracking\SourceWriteRepository;
use Bitrix\Main\Application;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One write of the sources the way the UI saves them: the write runs in a transaction, a write that may create
 * a record of a built-in code runs under the named lock of the code, so parallel requests do not create two
 * records of it. After the commit the cache of the legacy readers is cleaned once more and the call tracker of
 * the sites is rebuilt, unless the write changes nothing the tracker carries.
 *
 * The write is already committed when the tracker is rebuilt, so a failed rebuild is logged and the write
 * still succeeds, as in the UI. The rebuild is then left to the agent of legacy that rebuilds the tracker until
 * it succeeds: a build refused while a parallel build holds its lock would otherwise leave the tracker without
 * the write until the next one.
 *
 * @internal
 */
final class SourceWriteCommit
{
	private const LOCK_PREFIX = 'crm_tracking_source_';
	private const LOCK_TIMEOUT = 10;
	private const LOGGER_ID = 'Default';
	private const MODULE_ID = 'crm';
	private const REBUILD_AGENT_DELAY = 60;
	private const REBUILD_AGENT_INTERVAL = 60;

	/**
	 * @param Closure(): mixed|null $rebuild rebuilds the call tracker of the sites, false or a throw is a
	 * failure; null - CallTracker::rebuildEnabled()
	 * @param LoggerInterface|null $logger null - the default logger of CRM
	 * @param int $lockTimeout seconds a write waits for the lock of its code
	 * @param Closure(): mixed|null $scheduleRebuild leaves the rebuild to later after a failed one, false or a throw
	 * is a failure; null - the agent CallTracker::rebuildAgent()
	 */
	public function __construct(
		private readonly ?Closure $rebuild = null,
		private readonly ?LoggerInterface $logger = null,
		private readonly int $lockTimeout = self::LOCK_TIMEOUT,
		private readonly SourceWriteRepository $repository = new SourceWriteRepository(),
		private readonly ?Closure $scheduleRebuild = null,
	)
	{
	}

	/**
	 * @template T
	 * @param string|null $lockCode the built-in code the write may create a record of; null - no lock
	 * @param callable(): T $write
	 * @param bool $changesTracker false - the write changes nothing the call tracker carries, it is not rebuilt
	 * @return T
	 * @throws TrackingSourceRefusedException Busy - a parallel request holds the lock of the code
	 */
	public function run(?string $lockCode, callable $write, bool $changesTracker = true): mixed
	{
		if ($lockCode === null)
		{
			$result = TraceTransaction::run($write);
		}
		else
		{
			$this->lock($lockCode);
			try
			{
				$result = TraceTransaction::run($write);
			}
			finally
			{
				$this->unlock($lockCode);
			}
		}

		$this->repository->cleanCache();
		if ($changesTracker)
		{
			$this->rebuildTracker();
		}

		return $result;
	}

	/**
	 * The connection is bound to the master while the lock is held: the write reads under the lock what a
	 * parallel request has just committed.
	 *
	 * @throws TrackingSourceRefusedException
	 */
	private function lock(string $code): void
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);

		$isLocked = false;
		try
		{
			$isLocked = Application::getConnection()->lock(self::LOCK_PREFIX . $code, $this->lockTimeout);
		}
		finally
		{
			if (!$isLocked)
			{
				$pool->useMasterOnly(false);
			}
		}

		if (!$isLocked)
		{
			throw new TrackingSourceRefusedException(SourceRefusal::Busy, null);
		}
	}

	private function unlock(string $code): void
	{
		try
		{
			Application::getConnection()->unlock(self::LOCK_PREFIX . $code);
		}
		finally
		{
			Application::getInstance()->getConnectionPool()->useMasterOnly(false);
		}
	}

	private function rebuildTracker(): void
	{
		try
		{
			$isRebuilt = $this->rebuild !== null ? ($this->rebuild)() : CallTracker::rebuildEnabled();
		}
		catch (Throwable $exception)
		{
			$this->getLogger()->error(
				'The call tracker of the sites was not rebuilt after a write of the tracking sources: {message}',
				['message' => $exception->getMessage(), 'exception' => $exception],
			);
			$this->deferRebuild();

			return;
		}

		if ($isRebuilt === false)
		{
			$this->getLogger()->error(
				'The call tracker of the sites was not rebuilt after a write of the tracking sources.',
			);
			$this->deferRebuild();
		}
	}

	private function deferRebuild(): void
	{
		try
		{
			$isScheduled = $this->scheduleRebuild !== null ? ($this->scheduleRebuild)() : self::addRebuildAgent();
		}
		catch (Throwable $exception)
		{
			$this->getLogger()->error(
				'The deferred rebuild of the call tracker of the sites was not scheduled: {message}',
				['message' => $exception->getMessage(), 'exception' => $exception],
			);

			return;
		}

		if ($isScheduled === false)
		{
			$this->getLogger()->error('The deferred rebuild of the call tracker of the sites was not scheduled.');
		}
	}

	/**
	 * The agent is added the way the updaters of the module add it; an agent already waiting is kept, so a series
	 * of failures leaves one deferred rebuild.
	 */
	private static function addRebuildAgent(): bool
	{
		$agentId = \CAgent::AddAgent(
			'\\' . CallTracker::class . '::rebuildAgent();',
			self::MODULE_ID,
			'N',
			self::REBUILD_AGENT_INTERVAL,
			'',
			'Y',
			\ConvertTimeStamp(time() + \CTimeZone::GetOffset() + self::REBUILD_AGENT_DELAY, 'FULL'),
			existError: false,
		);

		return (int)$agentId > 0;
	}

	private function getLogger(): LoggerInterface
	{
		return $this->logger ?? Container::getInstance()->getLogger(self::LOGGER_ID);
	}
}
