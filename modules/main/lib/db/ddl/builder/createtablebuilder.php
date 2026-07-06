<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Builder;

use Bitrix\Main\DB\Ddl\Column\ColumnInterface;
use Bitrix\Main\DB\Ddl\IndexColumn;

class CreateTableBuilder
{
	use IndexesTrait;

	private bool $delayKeyWrite = false;
	private bool $dynamicRowFormat = false;

	private ?AddColumnBuilder $addColumnBuilder = null;

	/**
	 * @param string $tableName
	 * @param ?\Closure $tableExistsFilter `fn(): bool`. Return true if the table already exists
	 * @param ?\Closure $addedIndexFilter `fn(string $name, array{type: string, columns: IndexColumn[]} $info): bool`.
	 *    Return false to omit the index
	 */
	public function __construct(
		protected readonly string $tableName,
		private readonly ?\Closure $tableExistsFilter = null,
		private readonly ?\Closure $addedIndexFilter = null,
	)
	{
	}

	public function addColumn(): AddColumnBuilder
	{
		if (!$this->addColumnBuilder)
		{
			$this->addColumnBuilder = new AddColumnBuilder($this->tableName);
		}

		return $this->addColumnBuilder;
	}

	/**
	 * Shortcut: adds an `ID` column (auto-incrementing INT, NOT NULL) and registers
	 * it as the table's primary key.
	 */
	public function addId(): void
	{
		$this->addColumn()->int('ID')->notNull()->autoincrement();
		$this->addPrimaryKey('ID');
	}

	public function useDelayKeyWrite(): self
	{
		$this->delayKeyWrite = true;

		return $this;
	}

	public function useDynamicRowType(): self
	{
		$this->dynamicRowFormat = true;

		return $this;
	}

	/**
	 * @internal
	 */
	public function toData(): CreateTableData
	{
		$columns = $this->addColumnBuilder?->getColumns() ?? [];
		if ($this->resolveTableExists())
		{
			$columns = [];
		}

		return $this->createData(
			tableName: $this->tableName,
			columns: $columns,
			primaryKeys: $this->primaryKeys,
			addedIndexes: $this->filterAddedIndexes($this->addedIndexes),
			delayKeyWrite: $this->delayKeyWrite,
			dynamicRowFormat: $this->dynamicRowFormat,
		);
	}

	protected function resolveTableExists(): bool
	{
		return $this->tableExistsFilter !== null && (bool)($this->tableExistsFilter)();
	}

	/**
	 * @param array<string, array{type: string, columns: IndexColumn[]}> $indexes
	 * @return array<string, array{type: string, columns: IndexColumn[]}>
	 */
	protected function filterAddedIndexes(array $indexes): array
	{
		if ($this->addedIndexFilter === null)
		{
			return $indexes;
		}
		$filter = $this->addedIndexFilter;
		$result = [];
		foreach ($indexes as $name => $info)
		{
			if ($filter($name, $info))
			{
				$result[$name] = $info;
			}
		}

		return $result;
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
		);
	}
}
