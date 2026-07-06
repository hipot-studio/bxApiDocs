<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

use Bitrix\Main\DB\Ddl\Column\ColumnInterface;
use Bitrix\Main\DB\Ddl\IndexColumn;

class CreateTableData extends \Bitrix\Main\DB\Ddl\Builder\CreateTableData
{
	/**
	 * @param ColumnInterface[] $columns
	 * @param string[] $primaryKeys
	 * @param array<string, array{type: string, columns: IndexColumn[]}> $addedIndexes
	 */
	public function __construct(
		string $tableName,
		array $columns,
		array $primaryKeys,
		array $addedIndexes,
		bool $delayKeyWrite,
		bool $dynamicRowFormat,
		private readonly bool $preliminaryExecutionDisabled = false,
	)
	{
		parent::__construct(
			tableName: $tableName,
			columns: $columns,
			primaryKeys: $primaryKeys,
			addedIndexes: $addedIndexes,
			delayKeyWrite: $delayKeyWrite,
			dynamicRowFormat: $dynamicRowFormat,
		);
	}

	public function isPreliminaryExecutionDisabled(): bool
	{
		return $this->preliminaryExecutionDisabled;
	}
}
