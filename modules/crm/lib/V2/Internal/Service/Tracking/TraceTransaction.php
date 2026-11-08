<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\DB\TransactionException;
use Throwable;

/**
 * One transaction of the database around a write of the traces: the bindings read under lock inside it
 * stay as they were read until the write is committed.
 *
 * @internal
 */
final class TraceTransaction
{
	/**
	 * @template T
	 * @param callable(): T $write
	 * @return T
	 */
	public static function run(callable $write): mixed
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$result = $write();
		}
		catch (Throwable $failure)
		{
			self::rollBack($connection);

			throw $failure;
		}

		$connection->commitTransaction();

		return $result;
	}

	/**
	 * A nested rollback undoes its savepoint and then reports itself as unsupported
	 * ({@see \Bitrix\Main\DB\MysqlCommonConnection::rollbackTransaction()}); by then the writes are undone,
	 * so the complaint must not replace the failure the caller is answered with.
	 *
	 * The rollback itself may fail too: a statement that commits implicitly (a change of a table made by
	 * an update applied on the fly) ends the transaction of an outer caller, and its savepoint is gone. The
	 * failure of the write - a refusal, most often, raised before anything was written - is still the
	 * answer; the failed rollback is logged.
	 */
	private static function rollBack(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
		}
		catch (SqlQueryException $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
	}
}
