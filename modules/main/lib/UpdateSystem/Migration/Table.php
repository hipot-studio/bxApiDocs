<?php

namespace Bitrix\Main\UpdateSystem\Migration;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\Ddl\Column\AbstractIntColumn;
use Bitrix\Main\DB\Ddl\Column\ColumnInterface;
use Bitrix\Main\DB\Ddl\Column\ColumnState;
use Bitrix\Main\DB\Ddl\Column\ColumnStateData;
use Bitrix\Main\DB\Ddl\Column\TimestampColumn;
use Bitrix\Main\DB\Ddl\Exception as DdlException;
use Bitrix\Main\DB\Ddl\IndexColumn;
use Bitrix\Main\DB\Ddl\Renderer\RendererFactory;
use Bitrix\Main\UpdateSystem\Migration\Tools\Database;

class Table
{
	private ?string $changeTableType = null;
	/** @var bool[] Preliminary-execution flags of the alter() blocks already registered. */
	private array $alterPhaseSlots = [];
	/** Name of the column that declares the autoincrement start value: a table has one counter. */
	private ?string $autoincrementStartColumn = null;
	private readonly IndexNameProcessor $indexNameProcessor;

	public function __construct(
		private readonly string $tableName,
		private readonly Context $context,
		?IndexNameProcessor $indexNameProcessor = null,
	)
	{
		$this->indexNameProcessor = $indexNameProcessor ?? new IndexNameProcessor($context);
	}

	/**
	 * @param \Closure(\Bitrix\Main\UpdateSystem\Migration\CreateTableBuilder $table): void $callback
	 * @return self
	 * @throws Exception
	 */
	public function create(\Closure $callback): self
	{
		return $this->withMasterOnly(function () use ($callback): self {
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

			$this->checkColumnDeclarations($builder->addColumn()->getColumns());
			// A closure value is lost here on the collecting pipeline: the created table is skipped
			// by the deploy pass, so the counter would never be applied at all.
			$this->checkAutoincrementClosurePlacement($builder->addColumn()->getColumns(), false);

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
				$this->resolveAutoincrementStartValues($builder->addColumn()->getColumns());

				$queries = RendererFactory::get($this->context->getDbType())->renderCreateTable($data);
				foreach ($queries as $query)
				{
					$this->executeQuery($query);
				}
			}

			return $this;
		});
	}

	/**
	 * @param \Closure(\Bitrix\Main\UpdateSystem\Migration\AlterTableBuilder $table): void $callback
	 * @return self
	 * @throws Exception
	 */
	public function alter(\Closure $callback): self
	{
		return $this->withMasterOnly(function () use ($callback): self {
			$mode = $this->context->getDatabaseUpdateMode();
			// Module install only creates tables (nothing to alter yet); module uninstall
			// only drops tables (no auto-reverse for column/index changes). Both skip alter.
			// The callback is not executed here, so the phase of the block is unknown: a repeated
			// alter() may be a legal phased pair and stays silent, while a change of another kind
			// before it conflicts in any phase.
			if (!$mode->executesDdl() || $mode->isModuleInstall() || $mode === DatabaseUpdateMode::ModuleUninstall)
			{
				if ($this->changeTableType !== 'alter')
				{
					$this->checkIfTableAlreadyChanged('alter');
				}

				return $this;
			}

			$tableExists = null;
			$tableExistsResolver = function () use (&$tableExists): bool {
				$tableExists ??= $this->context->tableExists($this->tableName);

				return $tableExists;
			};

			$builder = $this->alterBuilder($tableExistsResolver);
			$callback($builder);

			$this->checkColumnDeclarations(array_merge(
				$builder->addColumn()->getColumns(),
				$builder->modifyColumn()->getColumns(),
			));

			$data = $builder->toData();

			// The phase of the block is known only after the callback ran: disablePreliminaryExecution()
			// is declared inside it.
			$this->registerAlterPhase($data->isPreliminaryExecutionDisabled(), $builder);

			if ($data->isPreliminaryExecutionDisabled() && $mode->isPreliminary())
			{
				return $this;
			}

			if ($mode->usesRealDatabase() && !$tableExistsResolver())
			{
				return $this;
			}

			// Columns of $data, not of the builder: a column dropped by a filter renders nothing,
			// so its closure has no reason to query the live database.
			$this->resolveAutoincrementStartValues(array_merge(
				$data->getAddedColumns(),
				$data->getModifiedColumns(),
			));

			$queries = RendererFactory::get($this->context->getDbType())->renderAlterTable($data);
			foreach ($queries as $query)
			{
				$this->executeQuery($query);
			}

			return $this;
		});
	}

	/**
	 * @param ?\Closure(\Bitrix\Main\UpdateSystem\Migration\DropTableBuilder $table): void $callback
	 *    Optional — pass a callback only when you need to tweak the drop (e.g. {@see DropTableBuilder::disablePreliminaryExecution()}).
	 * @return self
	 * @throws Exception
	 */
	public function drop(?\Closure $callback = null): self
	{
		return $this->withMasterOnly(function () use ($callback): self {
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
		});
	}

	/**
	 * Inserts a row with data `$fieldsValues` into the table. The row will be inserted only if `$conditionsCallback` returns true.
	 * @param array $fieldsValues
	 * @param \Closure(): bool $conditionsCallback Called with no arguments; capture the context via `use`.
	 * @return self
	 */
	public function insertRow(
		array $fieldsValues,
		\Closure $conditionsCallback,
	): self
	{
		return $this->withMasterOnly(function () use ($fieldsValues, $conditionsCallback): self {
			if (
				$this->context->getDatabaseUpdateMode()->canUpdateDatabase()
				&& $this->context->tableExists($this->tableName)
				&& !empty($fieldsValues)
				&& $conditionsCallback())
			{
				$sqlHelper = $this->context->getConnection()->getSqlHelper();
				$insert = $sqlHelper->prepareInsert($this->tableName, $fieldsValues);
				$sql = 'INSERT INTO ' . $sqlHelper->quote($this->tableName) . '(' . $insert[0] . ') ' . 'VALUES (' . $insert[1] . ')';

				$this->executeQuery($sql);
			}

			return $this;
		});
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
		return $this->withMasterOnly(function () use ($rows, $conditionsCallback): self {
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

			$sqlHelper = $this->context->getConnection()->getSqlHelper();
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
		});
	}

	/**
	 * Runs a raw DML query. DDL statements are rejected:
	 * schema changes belong to {@see create()}, {@see alter()} and {@see drop()}.
	 *
	 * @param \Closure(): ?string $sqlQueryCallback Called with no arguments; capture the context via `use`.
	 * @return self
	 * @throws Exception
	 */
	public function query(
		\Closure $sqlQueryCallback,
	): self
	{
		return $this->withMasterOnly(function () use ($sqlQueryCallback): self {
			if (!$this->context->getDatabaseUpdateMode()->canUpdateDatabase())
			{
				return $this;
			}

			$tableExists = $this->context->tableExists($this->tableName);
			// CREATE TABLE is the typical raw DDL in query(), and its table does not exist yet:
			// the open "current" updater still gets the statement checked, silence would leave
			// the author guessing why nothing happened.
			if (!$tableExists && !$this->context->isCurrentDevUpdater())
			{
				return $this;
			}

			$sql = $sqlQueryCallback();
			if (empty($sql))
			{
				return $this;
			}

			$this->checkQueryIsNotDdl($sql);

			if ($tableExists)
			{
				$this->executeQuery($sql);
			}

			return $this;
		});
	}

	/**
	 * Runs the operation in the master-only scope so every query (schema
	 * introspection and DDL/DML alike) stays on the migration connection.
	 * Without it a SELECT/SHOW issued through an overridden connection would be
	 * routed to the default portal slave on a cluster, ignoring the override.
	 *
	 * @param \Closure(): self $operation
	 * @return self
	 */
	private function withMasterOnly(\Closure $operation): self
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			return $operation();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
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
			indexNameProcessor: $this->indexNameProcessor,
		);
	}

	private function alterBuilder(\Closure $tableExistsResolver): AlterTableBuilder
	{
		$context = $this->context;
		$tableName = $this->tableName;
		$usesRealDatabase = $context->getDatabaseUpdateMode()->usesRealDatabase();

		return new AlterTableBuilder(
			tableName: $tableName,
			columnFilter: static function(ColumnInterface $col) use (
				$context,
				$tableName,
				$tableExistsResolver,
			): bool {
				if (!$context->getDatabaseUpdateMode()->usesRealDatabase())
				{
					return true;
				}
				if (!$tableExistsResolver())
				{
					return true;
				}

				return !$context->columnExists($tableName, $col->getParams()->getName());
			},
			addedIndexFilter: static function(string $_indexName, array $info) use (
				$context,
				$tableName,
				$tableExistsResolver,
			): bool {
				if (!$context->getDatabaseUpdateMode()->usesRealDatabase())
				{
					return true;
				}
				if (!$tableExistsResolver())
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
			currentPrimaryKeyResolver: static function() use (
				$context,
				$tableName,
				$tableExistsResolver,
			): array {
				if (!$context->getDatabaseUpdateMode()->usesRealDatabase())
				{
					return [];
				}
				if (!$tableExistsResolver())
				{
					return [];
				}

				return $context->getPrimaryKeyColumns($tableName);
			},
			dropIndexNameResolver: !$usesRealDatabase ? null : function(
				array $columnNames,
				string $hintName,
			) use ($context, $tableName, $tableExistsResolver): ?string {
				if (empty($columnNames))
				{
					if ($context->isDevMode())
					{
						throw new Exception(
							$context->getModuleId(),
							1104,
							'Index "' . $hintName . '" in table "' . $tableName
								. '" cannot be dropped safely: pass the index columns to dropIndex()',
							[
								'table' => $tableName,
								'index' => $hintName,
							],
						);
					}

					$this->logSkippedOperation('dropIndex', $hintName, 'columns are required');

					return null;
				}
				if (!$tableExistsResolver())
				{
					return $hintName;
				}

				$indexName = $context->getIndexName($tableName, $columnNames);
				if ($indexName === null)
				{
					$this->logSkippedOperation('dropIndex', $hintName, 'missing index');
				}

				return $indexName;
			},
			modifiedColumnFilter: !$usesRealDatabase ? null : function(
				ColumnInterface $column,
			) use ($context, $tableName, $tableExistsResolver): bool {
				if (!$tableExistsResolver())
				{
					return false;
				}

				$columnName = $column->getParams()->getName();
				if (!$context->columnExists($tableName, $columnName))
				{
					$this->logSkippedOperation('modifyColumn', $columnName, 'missing column');

					return false;
				}

				return true;
			},
			columnStateFactory: !$usesRealDatabase || !$context->isPostgreSql()
				? null
				: function(array $columns) use ($tableName): array {
					$candidateNames = [];
					foreach ($columns as $column)
					{
						if ($column instanceof AbstractIntColumn && $column->getParams()->isAutoincrement())
						{
							$candidateNames[] = strtolower($column->getParams()->getName());
						}
					}
					$candidateNames = array_values(array_unique($candidateNames));
					if (empty($candidateNames))
					{
						return [];
					}

					$resolvedStates = null;
					$loader = function () use (&$resolvedStates, $candidateNames): array {
						$resolvedStates ??= $this->resolvePostgreSqlColumnStates($candidateNames);

						return $resolvedStates;
					};

					$states = [];
					foreach ($candidateNames as $columnName)
					{
						$states[$columnName] = new ColumnState(
							$columnName,
							function () use ($loader, $tableName, $columnName): ?ColumnStateData {
								$resolvedStates = $loader();
								if (!isset($resolvedStates[$columnName]))
								{
									if ($this->context->isDevMode())
									{
										throw new DdlException(
											1106,
											'Column state is incomplete',
											[
												'table' => $tableName,
												'column' => $columnName,
												'reason' => 'column state row is missing',
											],
										);
									}

									$this->logSkippedOperation(
										'modifyColumn',
										$columnName,
										'missing column state',
									);

									return null;
								}

								return $resolvedStates[$columnName];
							},
						);
					}

					return $states;
				},
			indexNameProcessor: $this->indexNameProcessor,
			dropColumnAllowed: $this->context->isCurrentDevCleanup(),
			droppedColumnFilter: !$usesRealDatabase ? null : function(
				string $columnName,
			) use ($context, $tableName, $tableExistsResolver): bool {
				if (!$tableExistsResolver())
				{
					return false;
				}

				if (!$context->columnExists($tableName, $columnName))
				{
					$this->logSkippedOperation('dropColumn', $columnName, 'missing column');

					return false;
				}

				return true;
			},
		);
	}

	/**
	 * @param string[] $columnNames
	 * @return array<string, ColumnStateData>
	 */
	protected function resolvePostgreSqlColumnStates(array $columnNames): array
	{
		return Database::getPostgreSqlColumnStates(
			$this->tableName,
			$columnNames,
			$this->context->getConnection(),
		);
	}

	private function logSkippedOperation(string $operation, string $object, string $reason): void
	{
		$this->writeLog(
			"Skip updater '" . $this->context->getUpdaterFilename() . "': table=" . $this->tableName
				. '; operation=' . $operation
				. '; object=' . $object
				. '; reason=' . $reason,
		);
	}

	/**
	 * Declaration-based guards against column types that PostgreSQL cannot represent:
	 * MySQL TIMESTAMP semantics (use datetime instead) and unsigned integers (PostgreSQL
	 * has no unsigned types, its renderer would widen the column and desync the schemas).
	 * Autoincrement start values are checked here too, and their carrier is tracked across all
	 * blocks of the table: the counter is one per table, not per block.
	 * The checks fire only for the module's open "current" updater, so already released
	 * updaters keep working as is.
	 *
	 * @param ColumnInterface[] $columns
	 * @throws Exception
	 */
	private function checkColumnDeclarations(array $columns): void
	{
		if (!$this->context->isCurrentDevUpdater())
		{
			return;
		}

		foreach ($columns as $column)
		{
			$columnName = $column->getParams()->getName();

			$startWith = $column->getParams()->getAutoincrementStartWith();
			if ($startWith !== null)
			{
				if (is_int($startWith) && $startWith <= 0)
				{
					throw new Exception(
						$this->context->getModuleId(),
						1110,
						'Autoincrement start value of column "' . $columnName . '" in table "' . $this->tableName
							. '" must be a positive integer',
						[
							'table' => $this->tableName,
							'column' => $columnName,
							'reason' => 'non-positive value',
						],
					);
				}
				// The carrier lives on the table, not on the block: the counter is one per table,
				// and a paired migration declares its blocks in two separate calls.
				if (
					$this->autoincrementStartColumn !== null
					&& strcasecmp($this->autoincrementStartColumn, $columnName) !== 0
				)
				{
					throw new Exception(
						$this->context->getModuleId(),
						1110,
						'Columns "' . $this->autoincrementStartColumn . '" and "' . $columnName . '" in table "'
							. $this->tableName . '" both declare an autoincrement start value; a table has one counter',
						[
							'table' => $this->tableName,
							'column' => $columnName,
							'reason' => 'more than one start value',
						],
					);
				}
				$this->autoincrementStartColumn = $columnName;
			}

			if ($column instanceof TimestampColumn)
			{
				throw new Exception(
					$this->context->getModuleId(),
					1103,
					'Column "' . $columnName . '" in table "' . $this->tableName
						. '" uses the deprecated timestamp type (not supported by PostgreSQL), use datetime instead',
					[
						'table' => $this->tableName,
						'column' => $columnName,
					],
				);
			}

			if ($column instanceof AbstractIntColumn && $column->getParams()->isUnsigned())
			{
				throw new Exception(
					$this->context->getModuleId(),
					1105,
					'Column "' . $columnName . '" in table "' . $this->tableName
						. '" is declared as unsigned (not supported by PostgreSQL), use a signed type instead',
					[
						'table' => $this->tableName,
						'column' => $columnName,
					],
				);
			}
		}
	}

	/**
	 * DDL does not belong in query(): the dedicated create()/alter()/drop() builders render
	 * cross-database SQL and skip already applied changes on their own. The check fires only
	 * for the module's open "current" updater, so already released updaters keep working as is.
	 *
	 * @throws Exception
	 */
	private function checkQueryIsNotDdl(string $sql): void
	{
		if (!$this->context->isCurrentDevUpdater())
		{
			return;
		}

		// Leading whitespace and comments do not change the statement kind.
		$statement = (string)preg_replace('#\A(?:\s+|--[^\n]*\n?|\#[^\n]*\n?|/\*.*?\*/)+#s', '', $sql);
		// TRUNCATE is deliberately allowed: it is a data cleanup, not a schema change.
		if (!preg_match('#\A(?:CREATE|ALTER|DROP|RENAME)\b#i', $statement))
		{
			return;
		}

		throw new Exception(
			$this->context->getModuleId(),
			1107,
			'DDL statement in query() for table "' . $this->tableName
				. '", use the dedicated methods instead: create(), alter() or drop()',
			[
				'table' => $this->tableName,
				'sql' => $sql,
			],
		);
	}

	/**
	 * A table takes one alter() per execution phase: a regular block (it runs in the preliminary
	 * pass too) may be paired with one disablePreliminaryExecution() block, and so may a create() —
	 * the pair expresses a two-phase migration, e.g. add a NOT NULL column with a backfill default
	 * (or create the table) and finish the schema when the new code is deployed. A second block of
	 * the same phase is one modification too many, exactly as before.
	 *
	 * @throws Exception
	 */
	private function registerAlterPhase(bool $preliminaryExecutionDisabled, AlterTableBuilder $builder): void
	{
		$conflict = match (true)
		{
			$this->changeTableType === null => false,
			$this->changeTableType === 'alter' => in_array($preliminaryExecutionDisabled, $this->alterPhaseSlots, true),
			$this->changeTableType === 'create' =>
				!$preliminaryExecutionDisabled
				|| in_array(true, $this->alterPhaseSlots, true),
			default => true,
		};

		if ($conflict && $this->context->isDevMode())
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

		$this->changeTableType ??= 'alter';
		$this->alterPhaseSlots[] = $preliminaryExecutionDisabled;

		$this->checkAutoincrementClosurePlacement(
			array_merge($builder->addColumn()->getColumns(), $builder->modifyColumn()->getColumns()),
			$preliminaryExecutionDisabled,
		);

	}

	/**
	 * A closure-declared autoincrement start value belongs to the final-phase alter() block alone:
	 * a regular block reaches the preliminary pipeline, where the collecting pass has no value to
	 * render and the executing pass would compute it too early — before the new code is deployed.
	 * Static values are deterministic and stay allowed everywhere. The check fires only for the
	 * module's open "current" updater.
	 *
	 * @param ColumnInterface[] $columns
	 * @throws Exception
	 */
	private function checkAutoincrementClosurePlacement(array $columns, bool $preliminaryExecutionDisabled): void
	{
		if ($preliminaryExecutionDisabled || !$this->context->isCurrentDevUpdater())
		{
			return;
		}

		foreach ($columns as $column)
		{
			$columnName = $column->getParams()->getName();
			if ($column->getParams()->getAutoincrementStartWith() instanceof \Closure)
			{
				throw new Exception(
					$this->context->getModuleId(),
					1110,
					'Closure-declared autoincrement start value of column "' . $columnName . '" in table "'
						. $this->tableName . '" is allowed only in an alter() block with disablePreliminaryExecution():'
						. ' the preliminary pipeline cannot collect or time such a value, use a static integer instead',
					[
						'table' => $this->tableName,
						'column' => $columnName,
						'reason' => 'closure outside the final-phase block',
					],
				);
			}
		}
	}

	/**
	 * Resolves closure-declared autoincrement start values right before rendering. The closure runs
	 * only against the live database; in query-collecting modes the value is dropped from the
	 * declaration. An invalid result skips the counter (loudly in dev mode).
	 *
	 * @param ColumnInterface[] $columns
	 * @throws Exception
	 */
	private function resolveAutoincrementStartValues(array $columns): void
	{
		foreach ($columns as $column)
		{
			$params = $column->getParams();
			$startWith = $params->getAutoincrementStartWith();
			if (!$startWith instanceof \Closure || !$column instanceof AbstractIntColumn)
			{
				continue;
			}

			$value = $this->context->getDatabaseUpdateMode()->usesRealDatabase() ? $startWith() : null;
			if ($value !== null && (!is_int($value) || $value <= 0))
			{
				if ($this->context->isDevMode())
				{
					throw new Exception(
						$this->context->getModuleId(),
						1110,
						'Autoincrement start value of column "' . $params->getName() . '" in table "'
							. $this->tableName . '" resolved to an invalid result: a positive integer or null is expected',
						[
							'table' => $this->tableName,
							'column' => $params->getName(),
							'reason' => 'invalid closure result',
						],
					);
				}

				$this->logSkippedOperation('autoincrementStartWith', $params->getName(), 'invalid closure result');
				$value = null;
			}

			$column->autoincrement($value);
		}
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

		$connection = $this->context->getConnection();

		$mode = $this->context->getDatabaseUpdateMode();
		$isModuleInstallOrUninstall =
			$mode === DatabaseUpdateMode::ModuleInstall
			|| $mode === DatabaseUpdateMode::ModuleUninstall;

		if (!$isModuleInstallOrUninstall)
		{
			$logMessage = "Run updater '" . $this->context->getUpdaterFilename() . "': Query(" . $sql . ', ' . $this->tableName . ')';
			if (!empty($connection->getNodeId()))
			{
				$logMessage .= ' [connection: ' . $this->getConnectionId($connection) . ']';
			}
			$this->writeLog($logMessage);
		}

		try
		{
			$connection->queryExecute($sql);
		}
		catch (\Bitrix\Main\DB\Exception $e)
		{
			$error = $e->getDatabaseMessage();
			if (!empty($connection->getNodeId()))
			{
				$error .= ' [connection: ' . $this->getConnectionId($connection) . ']';
			}

			// In dev mode any failure is loud (Exception 1199). Otherwise the updater flow
			// collects the error in CUpdater, while module install/uninstall must surface it
			// in the Result returned by installMigrations()/uninstallMigrations() - so it throws.
			if (!$isModuleInstallOrUninstall && !$this->context->isDevMode() && class_exists(\CUpdater::class))
			{
				\CUpdater::addError($error);
			}
			else
			{
				throw new Exception(
					$this->context->getModuleId(),
					1199,
					'Wrong sql query in ' . $this->tableName . ' table: ' . $sql . '. ' . $error,
					[
						'table' => $this->tableName,
						'sql' => $sql,
						'error' => $error,
					],
				);
			}
		}
	}

	protected function writeLog(string $message): void
	{
		\CUpdateClient::AddMessage2Log($message, 'CRUPDCDF2');
	}

	private function getConnectionId(Connection $connection): string
	{
		return 'node #' . $connection->getNodeId();
	}
}
