<?php

namespace Bitrix\BIConnector\DB;

/**
 * Raw outcome of a ClickHouse HTTP query: column metadata plus a stream positioned at the first
 * data row.
 *
 * Plays the role of the driver resource that other connections get from mysqli or pgsql, so that
 * ClickhouseResult can stay a thin reader over it.
 */
final class ClickhouseQueryResult
{
	/**
	 * @param string[] $columnNames Column names in the order the server returned them.
	 * @param string[] $columnTypes ClickHouse type names, aligned with $columnNames.
	 * @param resource $stream Stream positioned at the first data row.
	 */
	public function __construct(
		private readonly array $columnNames,
		private readonly array $columnTypes,
		private $stream,
	)
	{
	}

	/**
	 * @return string[]
	 */
	public function getColumnNames(): array
	{
		return $this->columnNames;
	}

	/**
	 * @return string[]
	 */
	public function getColumnTypes(): array
	{
		return $this->columnTypes;
	}

	/**
	 * @return resource
	 */
	public function getStream()
	{
		return $this->stream;
	}

	/**
	 * Reads the next data row.
	 *
	 * @return array|null Row as a name => value map, or null when the data is exhausted.
	 * @throws \Bitrix\Main\DB\SqlQueryException When the stream carries a server error instead of a row.
	 */
	public function fetchRow(): ?array
	{
		$values = $this->fetchValues();

		return $values === null ? null : $this->combineRow($values);
	}

	/**
	 * Reads the next data row as a list of values in the order of the columns.
	 *
	 * The BI read path addresses values by position and not by name, so it needs the row in this form.
	 *
	 * @return array|null Values of the row, or null when the data is exhausted.
	 * @throws \Bitrix\Main\DB\SqlQueryException When the stream carries a server error instead of a row.
	 */
	public function fetchValues(): ?array
	{
		if (!is_resource($this->stream))
		{
			return null;
		}

		while (($line = fgets($this->stream)) !== false)
		{
			$line = trim($line);
			if ($line === '')
			{
				continue;
			}

			// JSON_BIGINT_AS_STRING keeps a whole number that does not fit a float as its text. The
			// query already asks the server to quote such values, so this is the second line of the
			// same defence: a server that does not honour the setting still must not round a value
			// silently.
			$values = json_decode($line, true, 512, JSON_BIGINT_AS_STRING);
			if (!is_array($values))
			{
				// A failure that happens after the headers were flushed is appended to the body as
				// plain text, so an unparsable line is a server error and not the end of the data.
				$this->close();

				throw new \Bitrix\Main\DB\SqlQueryException('Clickhouse query error', $line);
			}

			return $this->padRow($values);
		}

		$this->close();

		return null;
	}

	/**
	 * Releases the stream.
	 *
	 * @return void
	 */
	public function close(): void
	{
		if (is_resource($this->stream))
		{
			fclose($this->stream);
		}

		$this->stream = null;
	}

	/**
	 * Releases the stream of a result nobody reads any more.
	 *
	 * A caller may stop fetching before the data is exhausted, and Main\DB\Result has no method to
	 * close a result explicitly, so the destructor is the only deterministic cleanup point for such a
	 * case. It matters because a response above the php://temp threshold is spilled to a file.
	 */
	public function __destruct()
	{
		$this->close();
	}

	private function combineRow(array $values): array
	{
		$row = [];
		foreach ($this->columnNames as $index => $columnName)
		{
			$row[$columnName] = self::normalizeValue($values[$index] ?? null);
		}

		return $row;
	}

	/**
	 * Aligns a row with the column list, so that a caller addressing values by position always finds
	 * a value for every column.
	 *
	 * @param array $values
	 *
	 * @return array
	 */
	private function padRow(array $values): array
	{
		$row = [];
		foreach (array_keys($this->columnNames) as $index)
		{
			$row[] = self::normalizeValue($values[$index] ?? null);
		}

		return $row;
	}

	/**
	 * A Bool column arrives as a JSON boolean, while the column is published as a string and the
	 * aggregated path already serializes it as 'true'/'false' (see getBooleanWireExpression()). The
	 * raw path has to agree: a PHP cast would turn false into '' and true into '1'.
	 *
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	private static function normalizeValue(mixed $value): mixed
	{
		if (is_bool($value))
		{
			return $value ? 'true' : 'false';
		}

		return $value;
	}
}
