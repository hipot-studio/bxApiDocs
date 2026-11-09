<?php

namespace Bitrix\BIConnector\Internal\Services\Builder;

use Bitrix\BIConnector\Internal\Services\SqlLab\SqlLabLockManager;
use Bitrix\Main\Application;
use Bitrix\Main\Data\ConnectionPool;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\MysqliConnection;

final class BuilderLockManager extends SqlLabLockManager
{
	private const MAX_SESSIONS = 4;

	private ?bool $dedicatedSessions = null;
	private ?ConnectionPool $pool = null;
	/** @var array<int, Connection> */
	private array $sessions = [];
	/** @var array<string, int> */
	private array $owners = [];
	private array $retiredSessions = [];

	public function acquire(string $name, int $timeout = 0): bool
	{
		return $this->withMaster(function (Connection $connection) use ($name, $timeout): bool {
			$this->dedicatedSessions ??= $this->needsDedicatedSessions($connection);
			if (!$this->dedicatedSessions)
			{
				return $connection->lock($name, $timeout);
			}
			if (isset($this->owners[$name]))
			{
				return false;
			}

			$slot = $this->findFreeSession();
			try
			{
				if (!isset($this->sessions[$slot]))
				{
					$this->pool ??= Application::getInstance()->getConnectionPool();
					$this->sessions[$slot] = $this->pool->cloneConnection(
						ConnectionPool::DEFAULT_CONNECTION_NAME,
						$this->sessionName($slot),
						['options' => ($connection->getConfiguration()['options'] ?? 0) & ~Connection::PERSISTENT],
					);
				}
				if (!$this->sessions[$slot]->lock($name, $timeout))
				{
					return false;
				}
				$this->owners[$name] = $slot;

				return true;
			}
			catch (\Throwable $exception)
			{
				$this->closeSession($slot);

				throw $exception;
			}
		});
	}

	public function release(string $name): void
	{
		if ($this->dedicatedSessions !== true)
		{
			parent::release($name);

			return;
		}
		$slot = $this->owners[$name] ?? null;
		if ($slot === null)
		{
			return;
		}
		unset($this->owners[$name]);
		try
		{
			$released = $this->withMaster(fn(): bool => $this->sessions[$slot]->unlock($name));
			if (!$released)
			{
				$this->closeSession($slot);
			}
		}
		catch (\Throwable)
		{
			$this->closeSession($slot);
		}
	}

	public function __destruct()
	{
		$this->close();
	}

	public function close(): void
	{
		foreach (array_keys($this->sessions) as $slot)
		{
			$this->closeSession($slot);
		}
		$this->owners = [];
	}

	private function needsDedicatedSessions(Connection $connection): bool
	{
		if (!$connection instanceof MysqliConnection)
		{
			return false;
		}

		// getVersion() strips the MariaDB marker and its compatibility prefix.
		$version = $connection->getResource()->server_info;
		if (stripos($version, 'MariaDB') !== false)
		{
			return false;
		}

		return preg_match('/^([0-9]+\.[0-9]+\.[0-9]+)/', $version, $match) !== 1
			|| version_compare($match[1], '5.7.5', '<');
	}

	private function findFreeSession(): int
	{
		for ($slot = 0; $slot < self::MAX_SESSIONS; $slot++)
		{
			if (!isset($this->retiredSessions[$slot]) && !in_array($slot, $this->owners, true))
			{
				return $slot;
			}
		}

		throw new \RuntimeException('Builder lock sessions are unavailable');
	}

	private function sessionName(int $slot): string
	{
		return 'biconnector_builder_' . spl_object_id($this) . '_' . $slot;
	}

	private function closeSession(int $slot): void
	{
		try
		{
			if (isset($this->sessions[$slot]))
			{
				$this->sessions[$slot]->disconnect();
				unset($this->sessions[$slot]);
			}
			$this->pool?->setConnectionParameters($this->sessionName($slot), []);
		}
		catch (\Throwable)
		{
			$this->retiredSessions[$slot] = true;
		}
	}
}
