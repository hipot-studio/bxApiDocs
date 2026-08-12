<?php

namespace Bitrix\Superset\Internal\Entities;

final class TrinoConnection
{
	public function __construct(
		private readonly int $databaseId,
		private readonly array $connection,
	)
	{
	}

	public function getDatabaseId(): int
	{
		return $this->databaseId;
	}

	public function getConnection(): array
	{
		return $this->connection;
	}
}
