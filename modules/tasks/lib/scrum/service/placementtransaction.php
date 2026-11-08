<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Scrum\Service;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Throwable;

final class PlacementTransaction
{
	private const NESTED_ROLLBACK_MESSAGE = 'Nested rollbacks are unsupported.';

	public static function run(callable $operation): bool
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$success = $operation();
		}
		catch (Throwable $throwable)
		{
			self::rollback($connection);

			throw $throwable;
		}

		if (!$success)
		{
			self::rollback($connection);

			return false;
		}

		$connection->commitTransaction();

		return true;
	}

	private static function rollback(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException $exception)
		{
			// MySQL rolls back to the savepoint before reporting a nested rollback as unsupported.
			if ($exception->getMessage() !== self::NESTED_ROLLBACK_MESSAGE)
			{
				throw $exception;
			}
		}
	}
}
