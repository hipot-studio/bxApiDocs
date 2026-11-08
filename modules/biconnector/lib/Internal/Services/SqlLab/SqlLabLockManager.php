<?php

namespace Bitrix\BIConnector\Internal\Services\SqlLab;

use Bitrix\Main\DB\Connection;

/**
 * Named locks of the master connection: the limiter keeps both its counter locks and its
 * concurrency slots here, so every lock of SQL Lab goes through one place.
 */
class SqlLabLockManager
{
	use MasterConnectionTrait;

	public function acquire(string $name, int $timeout = 0): bool
	{
		return (bool)$this->withMaster(static fn(Connection $connection): bool => $connection->lock($name, $timeout));
	}

	public function release(string $name): void
	{
		$this->withMaster(static fn(Connection $connection): mixed => $connection->unlock($name));
	}
}
