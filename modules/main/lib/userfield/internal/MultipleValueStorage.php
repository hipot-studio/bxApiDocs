<?php

namespace Bitrix\Main\UserField\Internal;

use Bitrix\Main\Application;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\MysqlCommonConnection;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ORM\Entity;

/** @internal */
class MultipleValueStorage
{
	private const LOCK_TIMEOUT = 2;

	/** @param \Closure(): Entity $getEntity */
	public function __construct(
		private readonly Connection $connection,
		private readonly string $tableName,
		private readonly \Closure $getEntity,
		private readonly int $typeId,
		private readonly int $fieldId,
	)
	{
	}

	public function save(int $id, array $values, bool $isUpdate): void
	{
		try
		{
			$this->write($id, $values, $isUpdate);
		}
		catch (SqlQueryException $exception)
		{
			if (
				!($this->connection instanceof MysqlCommonConnection)
				|| (int)$this->connection->getErrorCode() !== 1146
				|| $this->connection->isTableExists($this->tableName)
				|| !$this->restore()
			)
			{
				throw $exception;
			}

			$this->connection->clearCaches($this->tableName);
			$this->write($id, $values, $isUpdate);
		}
	}

	private function write(int $id, array $values, bool $isUpdate): void
	{
		if ($isUpdate)
		{
			$helper = $this->connection->getSqlHelper();
			$this->connection->query(sprintf(
				'DELETE FROM %s WHERE %s = %d',
				$helper->quote($this->tableName), $helper->quote('ID'), $id,
			));
		}

		foreach ($values as $value)
		{
			$this->connection->add($this->tableName, ['ID' => $id, 'VALUE' => $value]);
		}
	}

	protected function restore(): bool
	{
		$lockName = 'userfield-storage:' . $this->connection->getDatabase() . ':' . $this->tableName;
		try
		{
			if (!$this->connection->lock($lockName, self::LOCK_TIMEOUT))
			{
				return false;
			}

			try
			{
				// Another request may have restored the table while we waited for the lock.
				if (!$this->connection->isTableExists($this->tableName))
				{
					$entity = ($this->getEntity)();
					if ($entity->getDBTableName() !== $this->tableName)
					{
						throw new InvalidOperationException('User field storage table does not match its entity.');
					}
					// MySQL DDL implicitly commits the transaction, but retains the named lock.
					$this->createTable($this->connection, $entity);
				}

				return true;
			}
			finally
			{
				$this->connection->unlock($lockName);
			}
		}
		catch (\Throwable $exception)
		{
			$this->logFailure($exception);

			return false;
		}
	}

	protected function logFailure(\Throwable $exception): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($exception);
	}

	private function createTable(Connection $connection, Entity $entity): void
	{
		$helper = $connection->getSqlHelper();
		$connection->disableQueryExecuting();
		try
		{
			$connection->createTable($this->tableName, $entity->getScalarFields(), $entity->getPrimaryArray());
			$queries = $connection->getDisabledQueryExecutingDump();
		}
		finally
		{
			$connection->enableQueryExecuting();
		}

		if (count($queries) !== 1 || ($end = strrpos($queries[0], ')')) === false)
		{
			throw new InvalidOperationException('Unsupported user field storage DDL.');
		}

		$indexes = [];
		foreach (['ID', 'VALUE'] as $column)
		{
			$index = 'IX_UTM_HL' . $this->typeId . '_' . $this->fieldId . '_' . $column;
			$indexes[] = 'INDEX ' . $helper->quote($index) . ' (' . $helper->quote($column) . ')';
		}

		// Publish the table with both indexes, never an intermediate table without indexes.
		$sql = substr_replace($queries[0], ', ' . implode(', ', $indexes), $end, 0);
		$connection->queryExecute($sql);
	}
}
