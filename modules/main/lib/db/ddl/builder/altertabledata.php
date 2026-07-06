<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Builder;

use Bitrix\Main\DB\Ddl\Column\ColumnInterface;
use Bitrix\Main\DB\Ddl\IndexColumn;

class AlterTableData
{
	/**
	 * @param ColumnInterface[] $addedColumns
	 * @param ColumnInterface[] $modifiedColumns
	 * @param string[] $dropIndexNames
	 * @param bool $dropPrimaryKey
	 * @param string[] $primaryKeys
	 * @param array<string, array{type: string, columns: IndexColumn[]}> $addedIndexes
	 * @param string[] $droppedColumns
	 * @param array<string, string> $renamedColumns oldName → newName
	 */
	public function __construct(
		private readonly string $tableName,
		private readonly array $addedColumns,
		private readonly array $modifiedColumns,
		private readonly array $dropIndexNames,
		private readonly bool $dropPrimaryKey,
		private readonly array $primaryKeys,
		private readonly array $addedIndexes,
		private readonly array $droppedColumns,
		private readonly array $renamedColumns,
	)
	{
	}

	public function getTableName(): string
	{
		return $this->tableName;
	}

	/** @return ColumnInterface[] */
	public function getAddedColumns(): array
	{
		return $this->addedColumns;
	}

	/** @return ColumnInterface[] */
	public function getModifiedColumns(): array
	{
		return $this->modifiedColumns;
	}

	/** @return string[] */
	public function getDropIndexNames(): array
	{
		return $this->dropIndexNames;
	}

	public function isDropPrimaryKey(): bool
	{
		return $this->dropPrimaryKey;
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

	/** @return string[] */
	public function getDroppedColumns(): array
	{
		return $this->droppedColumns;
	}

	/** @return array<string, string> */
	public function getRenamedColumns(): array
	{
		return $this->renamedColumns;
	}
}
