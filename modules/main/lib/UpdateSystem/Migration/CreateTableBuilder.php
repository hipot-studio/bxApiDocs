<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

use Bitrix\Main\DB\Ddl\Column\ColumnInterface;
use Bitrix\Main\DB\Ddl\IndexColumn;

class CreateTableBuilder extends \Bitrix\Main\DB\Ddl\Builder\CreateTableBuilder
{
	private bool $preliminaryExecutionDisabled = false;

	public function disablePreliminaryExecution(): self
	{
		$this->preliminaryExecutionDisabled = true;

		return $this;
	}

	public function toData(): CreateTableData
	{
		/** @var CreateTableData */
		return parent::toData();
	}

	/**
	 * @param ColumnInterface[] $columns
	 * @param string[] $primaryKeys
	 * @param array<string, array{type: string, columns: IndexColumn[]}> $addedIndexes
	 */
	protected function createData(
		string $tableName,
		array $columns,
		array $primaryKeys,
		array $addedIndexes,
		bool $delayKeyWrite,
		bool $dynamicRowFormat,
	): CreateTableData
	{
		return new CreateTableData(
			tableName: $tableName,
			columns: $columns,
			primaryKeys: $primaryKeys,
			addedIndexes: $addedIndexes,
			delayKeyWrite: $delayKeyWrite,
			dynamicRowFormat: $dynamicRowFormat,
			preliminaryExecutionDisabled: $this->preliminaryExecutionDisabled,
		);
	}
}
