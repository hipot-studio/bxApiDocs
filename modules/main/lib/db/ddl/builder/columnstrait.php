<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Builder;

use Bitrix\Main\DB\Ddl\Exception;
use Bitrix\Main\DB\Ddl\Column\BigIntColumn;
use Bitrix\Main\DB\Ddl\Column\BlobColumn;
use Bitrix\Main\DB\Ddl\Column\BooleanColumn;
use Bitrix\Main\DB\Ddl\Column\CharColumn;
use Bitrix\Main\DB\Ddl\Column\ColumnInterface;
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

trait ColumnsTrait
{
	/**	@var ColumnInterface[] */
	protected array $columns = [];

	// $tableName is expected to be declared by the using class
	// (typically via constructor property promotion).

	/**
	 * @internal Used by sister Ddl builders (CreateTableBuilder, AlterTableBuilder)
	 *           to walk the collected columns when rendering SQL. Not part of the
	 *           public declarative API.
	 *
	 * @return ColumnInterface[]
	 */
	public function getColumns(): array
	{
		return $this->columns;
	}

	public function int(string $columnName, ?int $size = null): IntColumn
	{
		$column = new IntColumn($columnName, $size);
		$this->addColumn($column);

		return $column;
	}

	public function tinyInt(string $columnName, ?int $size = null): TinyIntColumn
	{
		$column = new TinyIntColumn($columnName, $size);
		$this->addColumn($column);

		return $column;
	}

	public function smallInt(string $columnName, ?int $size = null): SmallIntColumn
	{
		$column = new SmallIntColumn($columnName, $size);
		$this->addColumn($column);

		return $column;
	}

	public function mediumInt(string $columnName, ?int $size = null): MediumIntColumn
	{
		$column = new MediumIntColumn($columnName, $size);
		$this->addColumn($column);

		return $column;
	}

	public function bigInt(string $columnName, ?int $size = null): BigIntColumn
	{
		$column = new BigIntColumn($columnName, $size);
		$this->addColumn($column);

		return $column;
	}

	/**
	 * $mLength - the total number of digits (optional)
	 * $eLength - the number of digits following the decimal point (optional)
	 */
	public function float(string $name, ?int $mLength = null, ?int $eLength = null): FloatColumn
	{
		$column = new FloatColumn($name, $mLength, $eLength);
		$this->addColumn($column);

		return $column;
	}

	/**
	 * $mLength - the total number of digits
	 * $eLength - the number of digits following the decimal point
	 */
	public function decimal(string $name, int $mLength, int $eLength): DecimalColumn
	{
		$column = new DecimalColumn($name, $mLength, $eLength);
		$this->addColumn($column);

		return $column;
	}

	/**
	 * $mLength - the total number of digits
	 * $eLength - the number of digits following the decimal point
	 */
	public function numeric(string $name, int $mLength, int $eLength): NumericColumn
	{
		$column = new NumericColumn($name, $mLength, $eLength);
		$this->addColumn($column);

		return $column;
	}

	/**
	 * $mLength - the total number of digits (optional)
	 * $eLength - the number of digits following the decimal point (optional)
 */
	public function double(string $name, ?int $mLength = null, ?int $eLength = null): DoubleColumn
	{
		$column = new DoubleColumn($name, $mLength, $eLength);
		$this->addColumn($column);

		return $column;
	}

	public function varchar(string $columnName, ?int $length): VarcharColumn
	{
		$column = new VarcharColumn($columnName, $length);
		$this->addColumn($column);

		return $column;
	}

	public function char(string $columnName, int $length): CharColumn
	{
		$column = new CharColumn($columnName, $length);
		$this->addColumn($column);

		return $column;
	}

	public function text(string $columnName): TextColumn
	{
		$column = new TextColumn($columnName);
		$this->addColumn($column);

		return $column;
	}

	public function tinyText(string $columnName): TinyTextColumn
	{
		$column = new TinyTextColumn($columnName);
		$this->addColumn($column);

		return $column;
	}

	public function mediumText(string $columnName): MediumTextColumn
	{
		$column = new MediumTextColumn($columnName);
		$this->addColumn($column);

		return $column;
	}

	public function longText(string $columnName): LongTextColumn
	{
		$column = new LongTextColumn($columnName);
		$this->addColumn($column);

		return $column;
	}

	public function date(string $name): DateColumn
	{
		$column = new DateColumn($name);
		$this->addColumn($column);

		return $column;
	}

	public function datetime(string $name): DateTimeColumn
	{
		$column = new DateTimeColumn($name);
		$this->addColumn($column);

		return $column;
	}

	public function timestamp(string $name): TimestampColumn
	{
		$column = new TimestampColumn($name);
		$this->addColumn($column);

		return $column;
	}

	public function blob(string $columnName): BlobColumn
	{
		$column = new BlobColumn($columnName);
		$this->addColumn($column);

		return $column;
	}

	public function varbinary(string $columnName, int $length): VarbinaryColumn
	{
		$column = new VarbinaryColumn($columnName, $length);
		$this->addColumn($column);

		return $column;
	}

	public function mediumBlob(string $columnName): MediumBlobColumn
	{
		$column = new MediumBlobColumn($columnName);
		$this->addColumn($column);

		return $column;
	}

	public function longBlob(string $columnName): LongBlobColumn
	{
		$column = new LongBlobColumn($columnName);
		$this->addColumn($column);

		return $column;
	}

	public function boolean(string $columnName): BooleanColumn
	{
		$column = new BooleanColumn($columnName);
		$this->addColumn($column);

		return $column;
	}

	public function enum(string $columnName): void
	{
		throw new Exception(
			1102,
			'Enum column type is not supported (does not map cleanly to PostgreSQL).',
			[
				'table' => $this->tableName,
				'column' => $columnName,
			],
		);
	}

	private function addColumn(ColumnInterface $column): void
	{
		$this->columns[$column->getParams()->getName()] = $column;
	}
}
