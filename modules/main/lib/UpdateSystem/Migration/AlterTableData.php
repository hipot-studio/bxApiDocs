<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

use Bitrix\Main\DB\Ddl\Column\ColumnInterface;
use Bitrix\Main\DB\Ddl\IndexColumn;

class AlterTableData extends \Bitrix\Main\DB\Ddl\Builder\AlterTableData
{
	/**
	 * @param ColumnInterface[] $addedColumns
	 * @param ColumnInterface[] $modifiedColumns
	 * @param string[] $dropIndexNames
	 * @param string[] $primaryKeys
	 * @param array<string, array{type: string, columns: IndexColumn[]}> $addedIndexes
	 * @param string[] $droppedColumns
	 * @param array<string, string> $renamedColumns oldName → newName
	 */
	public function __construct(
		string $tableName,
		array $addedColumns,
		array $modifiedColumns,
		array $dropIndexNames,
		bool $dropPrimaryKey,
		array $primaryKeys,
		array $addedIndexes,
		array $droppedColumns,
		array $renamedColumns,
		private readonly bool $preliminaryExecutionDisabled = false,
	)
	{
		parent::__construct(
			tableName: $tableName,
			addedColumns: $addedColumns,
			modifiedColumns: $modifiedColumns,
			dropIndexNames: $dropIndexNames,
			dropPrimaryKey: $dropPrimaryKey,
			primaryKeys: $primaryKeys,
			addedIndexes: $addedIndexes,
			droppedColumns: $droppedColumns,
			renamedColumns: $renamedColumns,
		);
	}

	public function isPreliminaryExecutionDisabled(): bool
	{
		return $this->preliminaryExecutionDisabled;
	}
}
