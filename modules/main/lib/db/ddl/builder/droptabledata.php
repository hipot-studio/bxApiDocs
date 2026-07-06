<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Builder;

class DropTableData
{
	public function __construct(
		private readonly string $tableName,
	)
	{
	}

	public function getTableName(): string
	{
		return $this->tableName;
	}
}
