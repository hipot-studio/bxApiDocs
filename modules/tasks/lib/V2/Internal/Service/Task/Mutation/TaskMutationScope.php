<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Mutation;

use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\MysqlCommonConnection;
use Bitrix\Main\DB\TransactionException;

class TaskMutationScope
{
	private array $acquiredTaskIds = [];
	private bool $committed = false;

	public function __construct(private readonly Connection $connection)
	{
	}

	public function getConnection(): Connection
	{
		return $this->connection;
	}

	public function rememberTask(int $taskId): void
	{
		$this->acquiredTaskIds[$taskId] = true;
	}

	public function holds(int $taskId): bool
	{
		return isset($this->acquiredTaskIds[$taskId]);
	}

	public function markCommitted(): void
	{
		$this->committed = true;
	}

	public function isCommitted(): bool
	{
		return $this->committed;
	}

	public static function rollbackTransaction(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException $error)
		{
			if (
				$connection instanceof MysqlCommonConnection
				&& get_class($error) === TransactionException::class
				&& $error->getMessage() === 'Nested rollbacks are unsupported.'
			)
			{
				return;
			}

			throw $error;
		}
	}
}
