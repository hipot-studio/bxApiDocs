<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

use Bitrix\Main\DB\Ddl\Column\ColumnInterface;
use Bitrix\Main\DB\Ddl\Exception;
use Bitrix\Main\DB\Ddl\IndexColumn;

class AlterTableBuilder extends \Bitrix\Main\DB\Ddl\Builder\AlterTableBuilder
{
	private bool $preliminaryExecutionDisabled = false;

	public function dropColumn(string $columnName): self
	{
		throw new Exception(
			1010,
			'Drop columns is forbidden',
			[
				'table' => $this->tableName,
				'column' => $columnName,
			],
		);
	}

	public function renameColumn(string $oldName, string $newName): self
	{
		throw new Exception(
			1011,
			'Rename columns is forbidden',
			[
				'table' => $this->tableName,
				'oldName' => $oldName,
				'newName' => $newName,
			],
		);
	}

	public function disablePreliminaryExecution(): self
	{
		$this->preliminaryExecutionDisabled = true;

		return $this;
	}

	public function toData(): AlterTableData
	{
		/** @var AlterTableData */
		return parent::toData();
	}

	/**
	 * @param ColumnInterface[] $addedColumns
	 * @param ColumnInterface[] $modifiedColumns
	 * @param string[] $dropIndexNames
	 * @param string[] $primaryKeys
	 * @param array<string, array{type: string, columns: IndexColumn[]}> $addedIndexes
	 * @param string[] $droppedColumns
	 * @param array<string, string> $renamedColumns
	 */
	protected function createData(
		string $tableName,
		array $addedColumns,
		array $modifiedColumns,
		array $dropIndexNames,
		bool $dropPrimaryKey,
		array $primaryKeys,
		array $addedIndexes,
		array $droppedColumns,
		array $renamedColumns,
	): AlterTableData
	{
		return new AlterTableData(
			tableName: $tableName,
			addedColumns: $addedColumns,
			modifiedColumns: $modifiedColumns,
			dropIndexNames: $dropIndexNames,
			dropPrimaryKey: $dropPrimaryKey,
			primaryKeys: $primaryKeys,
			addedIndexes: $addedIndexes,
			droppedColumns: $droppedColumns,
			renamedColumns: $renamedColumns,
			preliminaryExecutionDisabled: $this->preliminaryExecutionDisabled,
		);
	}
}
