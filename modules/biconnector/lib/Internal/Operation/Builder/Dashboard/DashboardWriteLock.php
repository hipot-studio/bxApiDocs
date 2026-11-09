<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Result;

final class DashboardWriteLock
{
	private const MAX_TIMEOUT = 5;

	private Connection $connection;

	public function __construct(string $connectionName = 'biconnector_dashboard_write')
	{
		$pool = Application::getInstance()->getConnectionPool();
		$this->connection = $pool->getConnection($connectionName)
			?? $pool->cloneConnection($pool::DEFAULT_CONNECTION_NAME, $connectionName);
	}

	public function withLock(int $dashboardId, \Closure $work, int $timeout = 5): ?Result
	{
		$lockName = 'biconnector_dashboard_write_' . $dashboardId;
		if (!$this->connection->lock($lockName, max(0, min(self::MAX_TIMEOUT, $timeout))))
		{
			return null;
		}

		try
		{
			return $work();
		}
		finally
		{
			$this->connection->unlock($lockName);
		}
	}
}
