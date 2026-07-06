<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Builder;

use Bitrix\Main\DB\Ddl\Column\ColumnInterface;
use Bitrix\Main\DB\Ddl\IndexColumn;

class CreateTableData
{
	/**
	 * @param ColumnInterface[] $columns Columns for CREATE TABLE. Empty when the table already
	 *    exists
	 * @param string[] $primaryKeys
	 * @param array<string, array{type: string, columns: IndexColumn[]}> $addedIndexes
	 */
	public function __construct(
		private readonly string $tableName,
		private readonly array $columns,
		private readonly array $primaryKeys,
		private readonly array $addedIndexes,
		private readonly bool $delayKeyWrite,
		private readonly bool $dynamicRowFormat,
	)
	{
	}

	public function getTableName(): string
	{
		return $this->tableName;
	}

	/** @return ColumnInterface[] */
	public function getColumns(): array
	{
		return $this->columns;
	}

	/** @return string[] */
	public function getPrimaryKeys(): array
	{
		return $this->primaryKeys;
	}

	/** @return array<string, array{type: string, columns: IndexColumn[]}> */
	public function getAddedIndexes(): array
	{
		return $this->addedIndexes;
	}

	public function isDelayKeyWrite(): bool
	{
		return $this->delayKeyWrite;
	}

	public function isDynamicRowFormat(): bool
	{
		return $this->dynamicRowFormat;
	}
}
