<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Builder;

class DropTableBuilder
{
	public function __construct(
		protected readonly string $tableName,
	)
	{
	}

	public function getTableName(): string
	{
		return $this->tableName;
	}

	/**
	 * @internal
	 */
	public function toData(): DropTableData
	{
		return $this->createData(tableName: $this->tableName);
	}

	/**
	 * Factory hook for subclasses that need a custom {@see DropTableData} subtype.
	 */
	protected function createData(string $tableName): DropTableData
	{
		return new DropTableData(tableName: $tableName);
	}
}
