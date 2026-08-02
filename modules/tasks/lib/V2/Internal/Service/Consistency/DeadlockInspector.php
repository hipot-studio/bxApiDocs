<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Consistency;

use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlQueryException;

final class DeadlockInspector
{
	public function isDeadlock(Connection $connection, SqlQueryException $exception): ?bool
	{
		$databaseMessage = $exception->getDatabaseMessage();
		if ($databaseMessage === '')
		{
			return false;
		}

		return match ($connection->getType())
		{
			'mysql' => $this->forMySql($databaseMessage),
			'pgsql' => $this->forPostgreSql($databaseMessage),
			default => null,
		};
	}

	private function forMySql(string $databaseMessage): bool
	{
		return
			str_contains($databaseMessage, '(1213)')
			|| str_contains(strtolower($databaseMessage), 'deadlock found')
		;
	}

	private function forPostgreSql(string $databaseMessage): bool
	{
		return str_contains(strtolower($databaseMessage), 'deadlock detected');
	}
}
