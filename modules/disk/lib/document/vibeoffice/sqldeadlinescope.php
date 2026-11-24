<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class SqlDeadlineScope
{
	/** @var \WeakMap<\Bitrix\Main\DB\Connection, float>|null */
	private static ?\WeakMap $deadlines = null;

	/** Bound native row waits only for an operation that owns its transaction and session settings. */
	public static function run(float $deadline, callable $work): Result
	{
		$connection = Application::getConnection();
		$deadline = min($deadline, self::$deadlines[$connection] ?? INF);
		$remaining = $deadline - hrtime(true) / 1e9;
		if (!is_finite($remaining) || $remaining < 1.1 || DocumentStateRepository::hasOpenTransaction($connection)
			|| DocumentStateRepository::holdsAnyLock($connection))
		{
			return (new Result())->addError(new Error('The operation has no remaining budget.', 'vibeoffice_lock_timeout'));
		}
		if (isset(self::$deadlines[$connection]))
		{
			// The outer owner already bounded SQL waits to one second; a shorter remainder is rejected above.
			return $work();
		}
		$restore = [];
		try
		{
			if ($connection->getType() === 'mysql')
			{
				$old = (int)$connection->queryScalar('SELECT @@SESSION.innodb_lock_wait_timeout');
				$restore[] = 'SET SESSION innodb_lock_wait_timeout = ' . $old;
				$connection->queryExecute('SET SESSION innodb_lock_wait_timeout = 1');
			}
			elseif ($connection->getType() === 'pgsql')
			{
				foreach (['lock_timeout', 'statement_timeout'] as $setting)
				{
					$old = (string)array_values($connection->query('SHOW ' . $setting)->fetch())[0];
					$restore[] = 'SET ' . $setting . " = '" . $connection->getSqlHelper()->forSql($old) . "'";
					$connection->queryExecute('SET ' . $setting . " = '1000ms'");
				}
			}
			self::$deadlines ??= new \WeakMap();
			self::$deadlines[$connection] = $deadline;
			return $deadline - hrtime(true) / 1e9 >= 1.1 ? $work()
				: (new Result())->addError(new Error('The operation has no remaining budget.', 'vibeoffice_lock_timeout'));
		}
		finally
		{
			if (self::$deadlines !== null)
			{
				unset(self::$deadlines[$connection]);
			}
			foreach (array_reverse($restore) as $sql)
			{
				$connection->queryExecute($sql);
			}
		}
	}
}
