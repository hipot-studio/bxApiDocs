<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Renderer;

use Bitrix\Main\DB\Ddl\Builder\AlterTableData;
use Bitrix\Main\DB\Ddl\Builder\CreateTableData;
use Bitrix\Main\DB\Ddl\Column\AbstractIntColumn;
use Bitrix\Main\DB\Ddl\Column\BigIntColumn;
use Bitrix\Main\DB\Ddl\Column\BlobColumn;
use Bitrix\Main\DB\Ddl\Column\BooleanColumn;
use Bitrix\Main\DB\Ddl\Column\CharColumn;
use Bitrix\Main\DB\Ddl\Column\ColumnInterface;
use Bitrix\Main\DB\Ddl\Column\ColumnParams;
use Bitrix\Main\DB\Ddl\Column\DateColumn;
use Bitrix\Main\DB\Ddl\Column\DateTimeColumn;
use Bitrix\Main\DB\Ddl\Column\DecimalColumn;
use Bitrix\Main\DB\Ddl\Column\DoubleColumn;
use Bitrix\Main\DB\Ddl\Column\FloatColumn;
use Bitrix\Main\DB\Ddl\Column\IntColumn;
use Bitrix\Main\DB\Ddl\Column\LongBlobColumn;
use Bitrix\Main\DB\Ddl\Column\LongTextColumn;
use Bitrix\Main\DB\Ddl\Column\MediumBlobColumn;
use Bitrix\Main\DB\Ddl\Column\MediumIntColumn;
use Bitrix\Main\DB\Ddl\Column\MediumTextColumn;
use Bitrix\Main\DB\Ddl\Column\NumericColumn;
use Bitrix\Main\DB\Ddl\Column\SmallIntColumn;
use Bitrix\Main\DB\Ddl\Column\TextColumn;
use Bitrix\Main\DB\Ddl\Column\TimestampColumn;
use Bitrix\Main\DB\Ddl\Column\TinyIntColumn;
use Bitrix\Main\DB\Ddl\Column\TinyTextColumn;
use Bitrix\Main\DB\Ddl\Column\VarbinaryColumn;
use Bitrix\Main\DB\Ddl\Column\VarcharColumn;
use Bitrix\Main\DB\Ddl\Exception;
use Bitrix\Main\DB\Ddl\IndexColumn;

final class MysqlRenderer implements RendererInterface
{
	private const LEFT_QUOTE = '`';
	private const RIGHT_QUOTE = '`';

	public function renderColumnDefinition(ColumnInterface $col): string
	{
		$params = $col->getParams();
		$type = $this->renderColumnType($col, $params);
		$result = $this->quoteIdent($params->getName()) . ' ' . $type;

		if ($col instanceof AbstractIntColumn && $params->isUnsigned())
		{
			$result .= ' UNSIGNED';
		}

		if ($params->isNotNull())
		{
			$result .= ' NOT NULL';
		}

		$default = $params->getDefaultValue();
		if ($default !== null)
		{
			$result .= ' DEFAULT ' . $this->renderDefaultLiteral($col, $params, $default);
		}
		elseif ($col instanceof DateTimeColumn && $params->isCurrentTimestampAsDefault())
		{
			$result .= ' DEFAULT CURRENT_TIMESTAMP';
		}

		if ($col instanceof AbstractIntColumn && $params->isAutoincrement())
		{
			$result .= ' AUTO_INCREMENT';
		}

		// DateTime ON UPDATE CURRENT_TIMESTAMP
		if ($col instanceof DateTimeColumn && $params->isCurrentTimestampOnUpdate())
		{
			$result .= ' ON UPDATE CURRENT_TIMESTAMP';
		}

		return $result;
	}

	public function renderCreateTable(CreateTableData $data): array
	{
		$columns = $data->getColumns();
		if (empty($columns))
		{
			return [];
		}

		$lines = [];

		$columnTypeMap = [];
		foreach ($columns as $column)
		{
			$lines[] = "\t" . $this->renderColumnDefinition($column);
			$columnTypeMap[$column->getParams()->getName()] = $column;
		}

		$primaryKeys = $data->getPrimaryKeys();
		if (!empty($primaryKeys))
		{
			$this->validatePrimaryKeyColumns($data->getTableName(), $primaryKeys, $columnTypeMap);
			$keys = array_map(
				static fn(string $key): string => '`' . $key . '`',
				$primaryKeys,
			);
			$lines[] = "\tPRIMARY KEY (" . implode(', ', $keys) . ')';
		}

		foreach ($data->getAddedIndexes() as $indexName => $index)
		{
			$this->validateIndexColumns($data->getTableName(), $indexName, $index, $columnTypeMap);
			$cols = $this->renderIndexColumns($index['columns']);
			$prefix = match ($index['type'])
			{
				'unique' => 'UNIQUE INDEX',
				'fulltext' => 'FULLTEXT INDEX',
				default => 'INDEX',
			};
			$lines[] = "\t" . $prefix . ' `' . $indexName . '` (' . implode(', ', $cols) . ')';
		}

		$sql = 'CREATE TABLE `' . $data->getTableName() . '` (' . "\n"
			. implode(",\n", $lines) . "\n"
			. ')';

		$tableOptions = [];
		// The counter is a table option in MySQL, hoisted here from the autoincrement column.
		$startWith = $this->findAutoincrementStartWith($columns);
		if ($startWith !== null)
		{
			$tableOptions[] = 'AUTO_INCREMENT=' . $startWith;
		}
		if ($data->isDelayKeyWrite())
		{
			$tableOptions[] = 'DELAY_KEY_WRITE=1';
		}
		if ($data->isDynamicRowFormat())
		{
			$tableOptions[] = 'ROW_FORMAT=DYNAMIC';
		}
		if (!empty($tableOptions))
		{
			$sql .= ' ' . implode(' ', $tableOptions);
		}

		return [$sql];
	}

	public function renderAlterTable(AlterTableData $data): array
	{
		$allLines = [];
		$columnLines = [];

		if ($data->isDropPrimaryKey())
		{
			$allLines[] = 'DROP PRIMARY KEY';
			$columnLines[] = 'DROP PRIMARY KEY';
		}

		$indexLines = [];
		foreach ($data->getDropIndexNames() as $name)
		{
			$line = 'DROP INDEX `' . $name . '`';
			$allLines[] = $line;
			$indexLines[] = $line;
		}

		foreach ($data->getDroppedColumns() as $name)
		{
			$line = 'DROP COLUMN `' . $name . '`';
			$allLines[] = $line;
			$columnLines[] = $line;
		}

		foreach ($data->getRenamedColumns() as $oldName => $newName)
		{
			$line = 'RENAME COLUMN `' . $oldName . '` TO `' . $newName . '`';
			$allLines[] = $line;
			$columnLines[] = $line;
		}

		$columnTypeMap = [];
		foreach ($data->getAddedColumns() as $col)
		{
			$line = 'ADD COLUMN ' . $this->renderColumnDefinition($col);
			$allLines[] = $line;
			$columnLines[] = $line;
			$columnTypeMap[$col->getParams()->getName()] = $col;
		}

		foreach ($data->getModifiedColumns() as $col)
		{
			$line = 'MODIFY COLUMN ' . $this->renderColumnDefinition($col);
			$allLines[] = $line;
			$columnLines[] = $line;
			$columnTypeMap[$col->getParams()->getName()] = $col;
		}

		$primaryKeys = $data->getPrimaryKeys();
		if (!empty($primaryKeys))
		{
			$this->validatePrimaryKeyColumns($data->getTableName(), $primaryKeys, $columnTypeMap);
			$keys = array_map(static fn(string $key): string => '`' . $key . '`', $primaryKeys);
			$line = 'ADD PRIMARY KEY (' . implode(', ', $keys) . ')';
			$allLines[] = $line;
			$columnLines[] = $line;
		}

		// InnoDB never lowers the counter below MAX(id) + 1, so the clause is raising-only by itself.
		$startWith = $this->findAutoincrementStartWith(
			array_merge($data->getAddedColumns(), $data->getModifiedColumns()),
		);
		if ($startWith !== null)
		{
			$line = 'AUTO_INCREMENT = ' . $startWith;
			$allLines[] = $line;
			$columnLines[] = $line;
		}

		foreach ($data->getAddedIndexes() as $indexName => $index)
		{
			$this->validateIndexColumns($data->getTableName(), $indexName, $index, $columnTypeMap);
			$cols = $this->renderIndexColumns($index['columns']);
			$prefix = match ($index['type'])
			{
				'unique' => 'ADD UNIQUE INDEX',
				'fulltext' => 'ADD FULLTEXT INDEX',
				default => 'ADD INDEX',
			};
			$line = $prefix . ' `' . $indexName . '` (' . implode(', ', $cols) . ')';
			$allLines[] = $line;
			$indexLines[] = $line;
		}

		if (empty($allLines))
		{
			return [];
		}

		$hasSecondaryIndexOperations = !empty($indexLines);
		$hasDependentOperations = $data->isDropPrimaryKey()
			|| !empty($primaryKeys)
			|| !empty($data->getModifiedColumns())
			|| !empty($data->getDroppedColumns())
			|| !empty($data->getRenamedColumns())
			|| $this->hasAutoincrementColumnSupportedByAddedSecondaryIndex($data)
		;

		$lineGroups = $hasSecondaryIndexOperations && $hasDependentOperations
			? [$allLines]
			: [$columnLines, $indexLines]
		;

		$queries = [];
		foreach ($lineGroups as $lines)
		{
			if (!empty($lines))
			{
				$queries[] = 'ALTER TABLE `' . $data->getTableName() . "`\n" . implode(",\n", $lines);
			}
		}

		$queries[] = 'ANALYZE TABLE `' . $data->getTableName() . '`';

		return $queries;
	}

	private function hasAutoincrementColumnSupportedByAddedSecondaryIndex(AlterTableData $data): bool
	{
		$autoincrementColumnNames = [];
		foreach (array_merge($data->getAddedColumns(), $data->getModifiedColumns()) as $column)
		{
			$params = $column->getParams();
			if ($params->isAutoincrement())
			{
				$autoincrementColumnNames[] = $params->getName();
			}
		}

		if (empty($autoincrementColumnNames))
		{
			return false;
		}

		foreach ($data->getAddedIndexes() as $index)
		{
			$firstColumn = $index['columns'][0] ?? null;
			if ($firstColumn === null)
			{
				continue;
			}

			foreach ($autoincrementColumnNames as $columnName)
			{
				if (strcasecmp($firstColumn->getName(), $columnName) === 0)
				{
					return true;
				}
			}
		}

		return false;
	}

	public function renderDropTable(string $tableName): string
	{
		return 'DROP TABLE IF EXISTS `' . $tableName . '`';
	}

	/**
	 * @param ColumnInterface[] $columns
	 */
	private function findAutoincrementStartWith(array $columns): ?int
	{
		foreach ($columns as $column)
		{
			$params = $column->getParams();
			$startWith = $params->getAutoincrementStartWith();
			// Unresolved closures are dropped: the value exists only when resolved against the live DB.
			if ($params->isAutoincrement() && is_int($startWith))
			{
				return $startWith;
			}
		}

		return null;
	}

	/**
	 * @param IndexColumn[] $columns
	 * @return string[]
	 */
	private function renderIndexColumns(array $columns): array
	{
		$result = [];
		foreach ($columns as $col)
		{
			$line = '`' . $col->getName() . '`';
			if ($col->length !== null)
			{
				$line .= '(' . $col->length . ')';
			}
			if ($col->sort !== null)
			{
				$line .= ' ' . strtoupper($col->sort);
			}
			$result[] = $line;
		}
		return $result;
	}

	/**
	 * @param string[] $primaryKeys
	 * @param array<string, ColumnInterface> $columnTypeMap
	 *
	 * @throws Exception
	 */
	private function validatePrimaryKeyColumns(string $tableName, array $primaryKeys, array $columnTypeMap): void
	{
		foreach ($primaryKeys as $name)
		{
			$columnDef = $columnTypeMap[$name] ?? null;
			if ($columnDef === null)
			{
				continue;
			}
			if ($columnDef instanceof TextColumn || $columnDef instanceof BlobColumn)
			{
				throw new Exception(
					1031,
					"Primary key references TEXT/BLOB column without an explicit length prefix is forbidden",
					[
						'table' => $tableName,
						'column' => $name,
					],
				);
			}
		}
	}

	/**
	 * Validates that TEXT/BLOB columns in an index have an explicit key length prefix.
	 *
	 * @param array{type: string, columns: IndexColumn[]} $index
	 * @param array<string, ColumnInterface> $columnTypeMap Map of column name → column definition for columns declared in the current statement. Columns missing from the map (e.g. existing columns referenced from ALTER TABLE) are skipped — their types are unknown to the renderer.
	 *
	 * @throws Exception When a non-fulltext index references a TEXT/BLOB column without explicit length.
	 */
	private function validateIndexColumns(string $tableName, string $indexName, array $index, array $columnTypeMap): void
	{
		if ($index['type'] === 'fulltext')
		{
			return;
		}
		foreach ($index['columns'] as $col)
		{
			if ($col->length !== null)
			{
				continue;
			}
			$columnDef = $columnTypeMap[$col->getName()] ?? null;
			if ($columnDef === null)
			{
				continue;
			}
			if ($columnDef instanceof TextColumn || $columnDef instanceof BlobColumn)
			{
				throw new Exception(
					1030,
					"Index references a TEXT/BLOB column without a key length",
					[
						'table' => $tableName,
						'index' => $indexName,
						'column' => $col->getName(),
					],
				);
			}
		}
	}

	private function renderColumnType(ColumnInterface $col, ColumnParams $params): string
	{
		return match (true)
		{
			$col instanceof IntColumn => 'INT' . $this->renderSizeSuffix($params->getSize()),
			$col instanceof BigIntColumn => 'BIGINT' . $this->renderSizeSuffix($params->getSize()),
			$col instanceof TinyIntColumn => 'TINYINT' . $this->renderSizeSuffix($params->getSize()),
			$col instanceof SmallIntColumn => 'SMALLINT' . $this->renderSizeSuffix($params->getSize()),
			$col instanceof MediumIntColumn => 'MEDIUMINT' . $this->renderSizeSuffix($params->getSize()),
			$col instanceof BooleanColumn => 'BOOLEAN',
			$col instanceof DecimalColumn => 'DECIMAL(' . $params->getMantissaLength() . ',' . $params->getExponentLength() . ')',
			$col instanceof NumericColumn => 'NUMERIC(' . $params->getMantissaLength() . ',' . $params->getExponentLength() . ')',
			$col instanceof FloatColumn => 'FLOAT' . $this->renderFloatPrecision($params),
			$col instanceof DoubleColumn => 'DOUBLE' . $this->renderFloatPrecision($params),
			$col instanceof VarcharColumn => 'VARCHAR(' . $params->getLength() . ')',
			$col instanceof CharColumn => 'CHAR(' . $params->getLength() . ')',
			$col instanceof VarbinaryColumn => 'VARBINARY(' . $params->getLength() . ')',
			$col instanceof TimestampColumn => 'TIMESTAMP',
			$col instanceof DateTimeColumn => 'DATETIME',
			$col instanceof DateColumn => 'DATE',
			$col instanceof TinyTextColumn => 'TINYTEXT',
			$col instanceof MediumTextColumn => 'MEDIUMTEXT',
			$col instanceof LongTextColumn => 'LONGTEXT',
			$col instanceof TextColumn => 'TEXT',
			$col instanceof MediumBlobColumn => 'MEDIUMBLOB',
			$col instanceof LongBlobColumn => 'LONGBLOB',
			$col instanceof BlobColumn => 'BLOB',
			default => throw new Exception(
				1200,
				'Unsupported column type',
				['class' => $col::class],
			),
		};
	}

	private function renderSizeSuffix(?int $size): string
	{
		return $size !== null ? '(' . $size . ')' : '';
	}

	private function renderFloatPrecision(ColumnParams $params): string
	{
		// Emit precision/scale only when the caller explicitly provided both; otherwise render the plain type.
		if ($params->getMantissaLength() === null || $params->getExponentLength() === null)
		{
			return '';
		}

		return '(' . $params->getMantissaLength() . ',' . $params->getExponentLength() . ')';
	}

	private function renderDefaultLiteral(ColumnInterface $col, ColumnParams $params, string $rawDefault): string
	{
		if ($col instanceof DateTimeColumn && $params->isCurrentTimestampAsDefault())
		{
			return 'CURRENT_TIMESTAMP';
		}
		return "'" . $rawDefault . "'";
	}

	private function quoteIdent(string $name): string
	{
		return self::LEFT_QUOTE . $name . self::RIGHT_QUOTE;
	}
}
