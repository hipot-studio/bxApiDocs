<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Mailbox;

use Bitrix\Main\DB\Connection;
use ReflectionObject;

final class LegacyCrmImapGateTransaction
{
	private static int $savepointSequence = 0;

	private bool $ownsTransaction = false;
	private ?string $savepoint = null;
	private readonly \Closure $transactionActive;

	public function __construct(private readonly Connection $connection, ?\Closure $transactionActive = null)
	{
		$this->transactionActive = $transactionActive ?? $this->hasActiveTransaction(...);
	}

	public function begin(): void
	{
		$type = $this->connection->getType();
		if (in_array($type, ['mssql', 'oracle'], true) && ($this->transactionActive)($type))
		{
			$this->savepoint = 'MAIL_CRM_GATE_' . ++self::$savepointSequence;
			$this->connection->queryExecute($type === 'mssql'
				? 'SAVE TRANSACTION ' . $this->savepoint
				: 'SAVEPOINT ' . $this->savepoint);

			return;
		}

		$this->connection->startTransaction();
		$this->ownsTransaction = true;
	}

	public function commit(): void
	{
		if ($this->ownsTransaction)
		{
			$this->connection->commitTransaction();
			$this->ownsTransaction = false;
		}
		$this->savepoint = null;
	}

	public function rollback(): void
	{
		if ($this->ownsTransaction)
		{
			$this->connection->rollbackTransaction();
			$this->ownsTransaction = false;
		}
		elseif ($this->savepoint !== null)
		{
			$this->connection->queryExecute($this->connection->getType() === 'mssql'
				? 'ROLLBACK TRANSACTION ' . $this->savepoint
				: 'ROLLBACK TO ' . $this->savepoint);
		}
		$this->savepoint = null;
	}

	private function hasActiveTransaction(string $type): bool
	{
		if ($type === 'mssql')
		{
			return (int)$this->connection->queryScalar('SELECT @@TRANCOUNT') > 0;
		}

		$reflection = new ReflectionObject($this->connection);
		do
		{
			if ($reflection->hasProperty('transaction'))
			{
				return defined('OCI_DEFAULT')
					&& $reflection->getProperty('transaction')->getValue($this->connection) === constant('OCI_DEFAULT');
			}
			$reflection = $reflection->getParentClass();
		}
		while ($reflection !== false);

		return false;
	}
}
