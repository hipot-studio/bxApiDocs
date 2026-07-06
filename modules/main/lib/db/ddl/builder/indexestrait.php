<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Builder;

use Bitrix\Main\DB\Ddl\IndexColumn;

trait IndexesTrait
{
	protected array $primaryKeys = [];
	/** @var array<string, array{type: string, columns: IndexColumn[]}> */
	private array $addedIndexes = [];

	public function addPrimaryKey(string $columnName): self
	{
		return $this->addPrimaryKeys([$columnName]);
	}

	public function addPrimaryKeys(array $columnNames): self
	{
		$this->primaryKeys = $columnNames;

		return $this;
	}

	/**
	 * @param string $mysqlIndexName
	 * @param array<string|IndexColumn> $columns
	 */
	public function addIndex(string $mysqlIndexName, array $columns): self
	{
		$this->addedIndexes[$mysqlIndexName] = [
			'type' => 'index',
			'columns' => IndexColumn::normalizeList($columns),
		];

		return $this;
	}

	/**
	 * @param string $mysqlIndexName
	 * @param array<string|IndexColumn> $columns
	 */
	public function addUniqueIndex(string $mysqlIndexName, array $columns): self
	{
		$this->addedIndexes[$mysqlIndexName] = [
			'type' => 'unique',
			'columns' => IndexColumn::normalizeList($columns),
		];

		return $this;
	}

	/**
	 * @param string $mysqlIndexName
	 * @param array<string|IndexColumn> $columns
	 */
	public function addFulltextIndex(string $mysqlIndexName, array $columns): self
	{
		$this->addedIndexes[$mysqlIndexName] = [
			'type' => 'fulltext',
			'columns' => IndexColumn::normalizeList($columns),
		];

		return $this;
	}
}
