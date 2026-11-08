<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

use Bitrix\Main\DB\Ddl\Column\ColumnInterface;
use Bitrix\Main\DB\Ddl\Exception;
use Bitrix\Main\DB\Ddl\IndexColumn;

class AlterTableBuilder extends \Bitrix\Main\DB\Ddl\Builder\AlterTableBuilder
{
	private bool $preliminaryExecutionDisabled = false;

	/**
	 * @param ?\Closure $droppedColumnFilter `fn(string $columnName): bool`. Return false to skip the column
	 *    from DROP COLUMN rendering.
	 */
	public function __construct(
		string $tableName,
		?\Closure $columnFilter = null,
		?\Closure $addedIndexFilter = null,
		?\Closure $currentPrimaryKeyResolver = null,
		?\Closure $dropIndexNameResolver = null,
		?\Closure $modifiedColumnFilter = null,
		?\Closure $columnStateFactory = null,
		private readonly ?IndexNameProcessor $indexNameProcessor = null,
		private readonly bool $dropColumnAllowed = false,
		private readonly ?\Closure $droppedColumnFilter = null,
	)
	{
		parent::__construct(
			$tableName,
			$columnFilter,
			$addedIndexFilter,
			$currentPrimaryKeyResolver,
			$dropIndexNameResolver,
			$modifiedColumnFilter,
			$columnStateFactory,
		);
	}

	public function dropColumn(string $columnName): static
	{
		// The dev-stand cleanup (dev/updates/cleanup.php) undoes schema that never shipped,
		// so dropping columns is legal there and only there.
		if ($this->dropColumnAllowed)
		{
			return parent::dropColumn($columnName);
		}

		throw new Exception(
			1010,
			'Drop columns is forbidden',
			[
				'table' => $this->tableName,
				'column' => $columnName,
			],
		);
	}

	public function renameColumn(string $oldName, string $newName): static
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

	public function disablePreliminaryExecution(): static
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
	 * @param IndexColumn[] $columns
	 */
	protected function prepareIndexName(string $type, string $indexName, array $columns): string
	{
		return $this->indexNameProcessor?->process($type, $this->tableName, $indexName, $columns) ?? $indexName;
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
			droppedColumns: $this->filterDroppedColumns($droppedColumns),
			renamedColumns: $renamedColumns,
			preliminaryExecutionDisabled: $this->preliminaryExecutionDisabled,
			columnStates: $this->getColumnStatesForData(),
		);
	}

	/**
	 * Dropping a column is the only operation aimed at a shape that may already be gone: the
	 * cleanup re-runs on every change of the current updater. The filter keeps it re-entrant,
	 * like the other operations of the facade.
	 *
	 * @param string[] $droppedColumns
	 * @return string[]
	 */
	private function filterDroppedColumns(array $droppedColumns): array
	{
		if ($this->droppedColumnFilter === null)
		{
			return $droppedColumns;
		}

		return array_values(array_filter(
			$droppedColumns,
			fn(string $columnName): bool => ($this->droppedColumnFilter)($columnName),
		));
	}
}
