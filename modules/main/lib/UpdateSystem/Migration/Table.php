<?php

namespace Bitrix\Main\UpdateSystem\Migration;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Ddl\Column\ColumnInterface;
use Bitrix\Main\DB\Ddl\IndexColumn;
use Bitrix\Main\DB\Ddl\Renderer\RendererFactory;

class Table
{
	private ?string $changeTableType = null;

	public function __construct(
		private readonly string $tableName,
		private readonly Context $context,
	)
	{
	}

	/**
	 * @param \Closure(\Bitrix\Main\UpdateSystem\Migration\CreateTableBuilder $table): void $callback
	 * @return self
	 * @throws Exception
	 */
	public function create(\Closure $callback): self
	{
		if ($this->context->getDatabaseUpdateMode() === DatabaseUpdateMode::ModuleUninstall)
		{
			$this->drop();

			return $this;
		}

		$this->checkIfTableAlreadyChanged('create');

		if (!$this->context->getDatabaseUpdateMode()->executesDdl())
		{
			return $this;
		}

		$builder = $this->createBuilder();
		$callback($builder);

		$data = $builder->toData();

		$mode = $this->context->getDatabaseUpdateMode();
		if ($data->isPreliminaryExecutionDisabled() && $mode->isPreliminary())
		{
			return $this;
		}

		// During module install the module's tables do not exist yet — that's the
		// whole point of running tables.php. Skip the moduleTablesExist gate.
		if ($mode->isModuleInstall() || $this->context->moduleTablesExist($this->tableName))
		{
			$queries = RendererFactory::get($this->context->getDbType())->renderCreateTable($data);
			foreach ($queries as $query)
			{
				$this->executeQuery($query);
			}
		}

		return $this;
	}

	/**
	 * @param \Closure(\Bitrix\Main\UpdateSystem\Migration\AlterTableBuilder $table): void $callback
	 * @return self
	 * @throws Exception
	 */
	public function alter(\Closure $callback): self
	{
		$this->checkIfTableAlreadyChanged('alter');

		$mode = $this->context->getDatabaseUpdateMode();
		// Module install only creates tables (nothing to alter yet); module uninstall
		// only drops tables (no auto-reverse for column/index changes). Both skip alter.
		if (!$mode->executesDdl() || $mode->isModuleInstall() || $mode === DatabaseUpdateMode::ModuleUninstall)
		{
			return $this;
		}

		$builder = $this->alterBuilder();
		$callback($builder);

		$data = $builder->toData();

		if ($data->isPreliminaryExecutionDisabled() && $mode->isPreliminary())
		{
			return $this;
		}

		if ($mode->usesRealDatabase() && !$this->context->tableExists($this->tableName))
		{
			return $this;
		}

		$queries = RendererFactory::get($this->context->getDbType())->renderAlterTable($data);
		foreach ($queries as $query)
		{
			$this->executeQuery($query);
		}

		return $this;
	}

	/**
	 * @param ?\Closure(\Bitrix\Main\UpdateSystem\Migration\DropTableBuilder $table): void $callback
	 *    Optional — pass a callback only when you need to tweak the drop (e.g. {@see DropTableBuilder::disablePreliminaryExecution()}).
	 * @return self
	 * @throws Exception
	 */
	public function drop(?\Closure $callback = null): self
	{
		$this->checkIfTableAlreadyChanged('drop');

		$mode = $this->context->getDatabaseUpdateMode();
		// Module install only creates tables — drop is skipped even though the mode
		// otherwise allows forward writes.
		if ($mode->isModuleInstall())
		{
			return $this;
		}

		$builder = new DropTableBuilder($this->tableName);
		if ($callback !== null)
		{
			$callback($builder);
		}

		$data = $builder->toData();

		if ($data->isPreliminaryExecutionDisabled() && $mode->isPreliminary())
		{
			return $this;
		}

		if ($mode->canUpdateDatabase() || $mode === DatabaseUpdateMode::ModuleUninstall)
		{
			$sql = RendererFactory::get($this->context->getDbType())->renderDropTable($this->tableName);
			$this->executeQuery($sql);
		}

		return $this;
	}

	/**
	 * Inserts a row with data `$fieldsValues` into the table. The row will be inserted only if `$conditionsCallback` returns true.
	 * @param array $fieldsValues
	 * @param \Closure(\Bitrix\Main\UpdateSystem\Migration\Context $context): ?string $conditionsCallback
	 * @return self
	 */
	public function insertRow(
		array $fieldsValues,
		\Closure $conditionsCallback,
	): self
	{
		if (
			$this->context->getDatabaseUpdateMode()->canUpdateDatabase()
			&& $this->context->tableExists($this->tableName)
			&& !empty($fieldsValues)
			&& $conditionsCallback())
		{
			$sqlHelper = Application::getConnection()->getSqlHelper();
			$insert = $sqlHelper->prepareInsert($this->tableName, $fieldsValues);
			$sql = 'INSERT INTO ' . $sqlHelper->quote($this->tableName) . '(' . $insert[0] . ') ' . 'VALUES (' . $insert[1] . ')';

			$this->executeQuery($sql);
		}

		return $this;
	}

	/**
	 * Bulk-inserts multiple rows in a single `INSERT INTO … VALUES (…), (…), …` statement.
	 * All rows must share the same set of columns. The whole batch is wrapped by `$conditionsCallback`
	 *
	 * @param array<int, array<string, mixed>> $rows List of rows. Each row is a `'COLUMN' => value` map.
	 * @param \Closure(): bool $conditionsCallback Returns true when the batch should be inserted.
	 * @return self
	 * @throws Exception If `$rows` contain mismatched column sets (code 1020).
	 */
	public function insertRows(
		array $rows,
		\Closure $conditionsCallback,
	): self
	{
		if (
			!$this->context->getDatabaseUpdateMode()->canUpdateDatabase()
			|| !$this->context->tableExists($this->tableName)
			|| empty($rows)
			|| !$conditionsCallback()
		)
		{
			return $this;
		}

		$rows = array_values(array_filter($rows, static fn(array $row): bool => !empty($row)));
		if (empty($rows))
		{
			return $this;
		}

		$sqlHelper = Application::getConnection()->getSqlHelper();
		$columnsList = null;
		$valueGroups = [];
		foreach ($rows as $row)
		{
			$insert = $sqlHelper->prepareInsert($this->tableName, $row);
			if ($columnsList === null)
			{
				$columnsList = $insert[0];
			}
			elseif ($columnsList !== $insert[0])
			{
				throw new Exception(
					$this->context->getModuleId(),
					1020,
					'All rows in insertRows() must share the same column set',
					['table' => $this->tableName],
				);
			}
			$valueGroups[] = '(' . $insert[1] . ')';
		}

		$sql = 'INSERT INTO ' . $sqlHelper->quote($this->tableName)
			. ' (' . $columnsList . ') VALUES '
			. implode(', ', $valueGroups);

		$this->executeQuery($sql);

		return $this;
	}

	/**
	 * @param \Closure(\Bitrix\Main\UpdateSystem\Migration\Context $context): ?string $sqlQueryCallback
	 * @return self
	 */
	public function query(
		\Closure $sqlQueryCallback,
	): self
	{
		if ($this->context->getDatabaseUpdateMode()->canUpdateDatabase() && $this->context->tableExists($this->tableName))
		{
			$sql = $sqlQueryCallback();
			if (!empty($sql))
			{
				$this->executeQuery($sql);
			}
		}

		return $this;
	}

	private function createBuilder(): CreateTableBuilder
	{
		$context = $this->context;
		$tableName = $this->tableName;

		return new CreateTableBuilder(
			tableName: $tableName,
			tableExistsFilter: static fn(): bool => $context->getDatabaseUpdateMode()->usesRealDatabase()
				&& $context->tableExists($tableName),
			addedIndexFilter: static function(string $_indexName, array $info) use ($context, $tableName): bool {
				if (!$context->getDatabaseUpdateMode()->usesRealDatabase())
				{
					return true;
				}
				if (!$context->tableExists($tableName))
				{
					return true;
				}
				$columnNames = array_map(
					static fn(IndexColumn $col): string => strtolower($col->getName()),
					$info['columns'],
				);

				return !$context->indexExists($tableName, $columnNames);
			},
		);
	}

	private function alterBuilder(): AlterTableBuilder
	{
		$context = $this->context;
		$tableName = $this->tableName;

		return new AlterTableBuilder(
			tableName: $tableName,
			columnFilter: static function(ColumnInterface $col) use ($context, $tableName): bool {
				if (!$context->getDatabaseUpdateMode()->usesRealDatabase())
				{
					return true;
				}
				if (!$context->tableExists($tableName))
				{
					return true;
				}

				return !$context->columnExists($tableName, $col->getParams()->getName());
			},
			addedIndexFilter: static function(string $_indexName, array $info) use ($context, $tableName): bool {
				if (!$context->getDatabaseUpdateMode()->usesRealDatabase())
				{
					return true;
				}
				if (!$context->tableExists($tableName))
				{
					return true;
				}
				$columnNames = array_map(static fn(IndexColumn $col): string => $col->getName(), $info['columns']);
				if ($context->isPostgreSql())
				{
					$columnNames = array_map(strtolower(...), $columnNames);
				}

				return $context->getIndexName($tableName, $columnNames) === null;
			},
			currentPrimaryKeyResolver: static function() use ($context, $tableName): array {
				if (!$context->getDatabaseUpdateMode()->usesRealDatabase())
				{
					return [];
				}
				if (!$context->tableExists($tableName))
				{
					return [];
				}

				return $context->getPrimaryKeyColumns($tableName);
			},
			dropIndexNameResolver: static function(array $columnNames) use ($context, $tableName): ?string {
				if (!$context->getDatabaseUpdateMode()->usesRealDatabase())
				{
					return null;
				}
				if (!$context->tableExists($tableName))
				{
					return null;
				}

				return $context->getIndexName($tableName, $columnNames);
			},
		);
	}

	private function checkIfTableAlreadyChanged(string $changeTableType): void
	{
		if (!is_null($this->changeTableType) && $this->context->isDevMode())
		{
			throw new Exception(
				$this->context->getModuleId(),
				1101,
				'Table "' . $this->tableName . '" was already modified in this migration',
				[
					'table' => $this->tableName,
				],
			);
		}
		$this->changeTableType = $changeTableType;
	}

	protected function executeQuery(string $sql): void
	{
		if (!$this->context->getDatabaseUpdateMode()->usesRealDatabase())
		{
			return;
		}

		global $DB;

		if (
			$this->context->getDatabaseUpdateMode() !== DatabaseUpdateMode::ModuleInstall
			&& $this->context->getDatabaseUpdateMode() !== DatabaseUpdateMode::ModuleUninstall
		)
		{
			\CUpdateSystem::AddMessage2Log("Run updater '" . $this->context->getUpdaterFilename() . "': Query(" . $sql . ', ' . $this->tableName . ')', 'CRUPDCDF2');
		}

		$result = $DB->Query($sql, !$this->context->isDevMode());
		if (!$result)
		{
			if (class_exists(\CUpdater::class))
			{
				\CUpdater::addError($DB->db_Error);
			}
			else
			{
				throw new Exception(
					$this->context->getModuleId(),
					1199,
					'Wrong sql query in ' . $this->tableName . ' table: ' . $sql,
					[
						'table' => $this->tableName,
						'sql' => $sql,
					],
				);
			}
		}
	}
}
