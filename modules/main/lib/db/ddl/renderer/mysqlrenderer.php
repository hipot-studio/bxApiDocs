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
		$lines = [];

		if ($data->isDropPrimaryKey())
		{
			$lines[] = 'DROP PRIMARY KEY';
		}

		foreach ($data->getDropIndexNames() as $name)
		{
			$lines[] = 'DROP INDEX `' . $name . '`';
		}

		foreach ($data->getDroppedColumns() as $name)
		{
			$lines[] = 'DROP COLUMN `' . $name . '`';
		}

		foreach ($data->getRenamedColumns() as $oldName => $newName)
		{
			$lines[] = 'RENAME COLUMN `' . $oldName . '` TO `' . $newName . '`';
		}

		$columnTypeMap = [];
		foreach ($data->getAddedColumns() as $col)
		{
			$lines[] = 'ADD COLUMN ' . $this->renderColumnDefinition($col);
			$columnTypeMap[$col->getParams()->getName()] = $col;
		}

		foreach ($data->getModifiedColumns() as $col)
		{
			$lines[] = 'MODIFY COLUMN ' . $this->renderColumnDefinition($col);
			$columnTypeMap[$col->getParams()->getName()] = $col;
		}

		$primaryKeys = $data->getPrimaryKeys();
		if (!empty($primaryKeys))
		{
			$this->validatePrimaryKeyColumns($data->getTableName(), $primaryKeys, $columnTypeMap);
			$keys = array_map(static fn(string $key): string => '`' . $key . '`', $primaryKeys);
			$lines[] = 'ADD PRIMARY KEY (' . implode(', ', $keys) . ')';
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
			$lines[] = $prefix . ' `' . $indexName . '` (' . implode(', ', $cols) . ')';
		}

		if (empty($lines))
		{
			return [];
		}

		return [
			'ALTER TABLE `' . $data->getTableName() . "`\n" . implode(",\n", $lines),
			'ANALYZE TABLE `' . $data->getTableName() . '`',
		];
	}

	public function renderDropTable(string $tableName): string
	{
		return 'DROP TABLE IF EXISTS `' . $tableName . '`';
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
