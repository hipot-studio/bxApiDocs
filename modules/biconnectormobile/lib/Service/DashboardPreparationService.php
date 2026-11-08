<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\Service;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Public\Command\Dashboard\InstallDashboardCommand;
use Bitrix\BIConnectorMobile\Dto\DashboardViewState;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Diag\LoggerFactory;

/**
 * Starts the preparation of a report - installing it and bringing the Superset instance up - as a
 * background job of the current request, and answers immediately.
 *
 * Re-entry is refused on the server. The status of a report stays NOT_INSTALLED until the install
 * finishes, so the status alone cannot tell a running preparation from a fresh one; the guard below
 * does. It is a marker in storage, written under a named database lock which makes the check-and-write
 * atomic between parallel requests, and that same lock is held for as long as the preparation runs.
 *
 * The guard is tied to the life of the process rather than to a length of time picked in advance. The
 * job renews the marker between its steps, so a marker no longer renewed means a process that is gone,
 * and the report is free again within a minute instead of half an hour. A step of the preparation the
 * heartbeat cannot reach into - installing a market application is minutes of outbound calls inside one
 * command - is answered by the lock alone: the database drops it the moment the connection of a dead
 * process closes, and holds it while the process works.
 *
 * That leaves one window open, and knowingly so: a connection re-established inside that step takes none
 * of the locks of the old one along, and the marker is not being renewed there either, so a parallel
 * request can start a second preparation behind the back of the first. What it costs is one repeated
 * install of a market application, which is why the step is not broken up to be reported from.
 */
class DashboardPreparationService
{
	private const GUARD_KEY_PREFIX = 'biconnectormobile.dashboard.prepare.';
	private const GUARD_LOCK_PREFIX = 'biconnectormobile_dashboard_prepare_';

	// How long a marker stands for a running preparation without a heartbeat of its own.
	private const GUARD_HEARTBEAT_TTL = 60;

	// The longest a marker may live: a preparation still running after half an hour has failed anyway.
	// It is the very span the guard used to stand for on its own; what changed is that the marker no
	// longer holds the report for all of it - a heartbeat, or the lock of a living process, does.
	private const GUARD_MAX_LIFETIME = 1800;

	private const LOGGER_ID = 'biconnectormobile.dashboard_preparation';

	private StorageInterface $storage;

	public function __construct(?StorageInterface $storage = null)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	/**
	 * @return bool True when this call started the preparation, false when one was already running.
	 */
	public function start(int $dashboardId, DashboardViewState $state): bool
	{
		if (!$this->acquireGuard($dashboardId))
		{
			return false;
		}

		$needsInstall = $state->needsInstall;
		$this->scheduleJob(function() use ($dashboardId, $needsInstall) {
			try
			{
				$this->prepare($dashboardId, $needsInstall, fn() => $this->keepGuardAlive($dashboardId));
			}
			catch (\Throwable $exception)
			{
				$this->logFailure($dashboardId, $exception);
			}
			finally
			{
				$this->releaseGuard($dashboardId);
			}
		});

		return true;
	}

	/**
	 * The single point where the work is handed to the request tail, kept substitutable for tests.
	 */
	protected function scheduleJob(callable $job): void
	{
		Application::getInstance()->addBackgroundJob($job);
	}

	/**
	 * Same two steps the web takes, in the same order: an instance that is not up yet is started first
	 * (saveInitData names the user to notify once it is), and the report is installed on a ready one.
	 * An instance that is still starting leaves the report to the next call - the pull event of the
	 * instance brings the client back here.
	 *
	 * $heartbeat says the process is still alive and is called between the steps, the only places the job
	 * gets the word back.
	 */
	protected function prepare(int $dashboardId, bool $needsInstall, callable $heartbeat): void
	{
		if (!SupersetInitializer::isSupersetReady())
		{
			SupersetInitializer::saveInitData($dashboardId);
			SupersetInitializer::startupSuperset();
			$heartbeat();
		}

		if (!$needsInstall || !SupersetInitializer::isSupersetReady())
		{
			return;
		}

		$result = (new InstallDashboardCommand($dashboardId))->run();
		if (!$result->isSuccess())
		{
			$this->logErrors($dashboardId, $result->getErrorMessages());
		}
	}

	/**
	 * The lock is taken here and released only when the preparation ends, so a busy lock already means a
	 * preparation in flight. The marker is looked at all the same: a lock can be lost while its process
	 * works on - a database connection re-established mid-job takes none of its locks along - and the
	 * heartbeat between the steps of the job is what covers that everywhere except inside the install
	 * itself, the one step nothing is reported from (see the class docblock).
	 */
	private function acquireGuard(int $dashboardId): bool
	{
		if (!$this->acquireLock($dashboardId))
		{
			return false;
		}

		$marker = $this->storage->get($this->getGuardKey($dashboardId));
		if (is_array($marker) && $this->isHeartbeatFresh($marker))
		{
			$this->releaseLock($dashboardId);

			return false;
		}

		$startedAt = time();
		$this->storage->set(
			$this->getGuardKey($dashboardId),
			['startedAt' => $startedAt, 'touchedAt' => $startedAt],
			self::GUARD_MAX_LIFETIME,
		);

		return true;
	}

	/**
	 * A living job says so between its steps. The marker is rewritten with the time left of its maximum
	 * life rather than with a full one, so heartbeats extend nothing past that ceiling; a marker already
	 * gone is not brought back.
	 */
	private function keepGuardAlive(int $dashboardId): void
	{
		try
		{
			$marker = $this->storage->get($this->getGuardKey($dashboardId));
			if (!is_array($marker))
			{
				return;
			}

			$startedAt = (int)($marker['startedAt'] ?? time());
			$timeLeft = self::GUARD_MAX_LIFETIME - (time() - $startedAt);
			if ($timeLeft <= 0)
			{
				return;
			}

			$this->storage->set(
				$this->getGuardKey($dashboardId),
				['startedAt' => $startedAt, 'touchedAt' => time()],
				$timeLeft,
			);
		}
		catch (\Throwable $exception)
		{
			// A heartbeat that did not reach the storage costs the preparation nothing: the lock keeps
			// answering for it as long as this process lives.
			$this->logFailure($dashboardId, $exception);
		}
	}

	private function releaseGuard(int $dashboardId): void
	{
		try
		{
			$this->storage->delete($this->getGuardKey($dashboardId));
		}
		catch (\Throwable $exception)
		{
			// The marker expires on its own, so a failed cleanup delays the next attempt instead of
			// losing it - worth a line in the log, not worth failing the preparation over.
			$this->logFailure($dashboardId, $exception);
		}
		finally
		{
			$this->releaseLock($dashboardId);
		}
	}

	private function isHeartbeatFresh(array $marker): bool
	{
		$touchedAt = (int)($marker['touchedAt'] ?? $marker['startedAt'] ?? 0);

		return (time() - $touchedAt) < self::GUARD_HEARTBEAT_TTL;
	}

	/**
	 * Kept apart from the storage of the guard - and substitutable - because it answers a different
	 * question: not what was written, but whether the process that wrote it is still there.
	 */
	protected function acquireLock(int $dashboardId): bool
	{
		return Application::getConnection()->lock(self::GUARD_LOCK_PREFIX . $dashboardId);
	}

	protected function releaseLock(int $dashboardId): void
	{
		Application::getConnection()->unlock(self::GUARD_LOCK_PREFIX . $dashboardId);
	}

	private function getGuardKey(int $dashboardId): string
	{
		return self::GUARD_KEY_PREFIX . $dashboardId;
	}

	private function logFailure(int $dashboardId, \Throwable $exception): void
	{
		(new LoggerFactory())->createById(self::LOGGER_ID)?->error(
			'{date}: BIConnectorMobile: the preparation of the report {dashboardId} failed: {exception}' . PHP_EOL,
			[
				'dashboardId' => $dashboardId,
				'exception' => $exception,
			],
		);
	}

	/**
	 * @param string[] $messages
	 */
	private function logErrors(int $dashboardId, array $messages): void
	{
		(new LoggerFactory())->createById(self::LOGGER_ID)?->error(
			'{date}: BIConnectorMobile: the report {dashboardId} was not installed: {messages}' . PHP_EOL,
			[
				'dashboardId' => $dashboardId,
				'messages' => implode('; ', $messages),
			],
		);
	}
}
