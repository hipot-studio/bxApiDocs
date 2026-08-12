<?php
namespace Bitrix\BIConnector\DB;

class PgsqlResult extends \Bitrix\Main\DB\PgsqlResult
{
	protected $rowData = null;
	protected $rowDataReference = null;

	/** @var int[]|false */
	protected $byteaFieldIndexes = false;

	/** @var string|null Server-side cursor name; null when not in cursor mode */
	protected ?string $biCursorName;

	/** @var int Batch size for FETCH FORWARD */
	protected int $biCursorBatch;

	/** @var bool Whether the cursor has been fully consumed / closed */
	protected bool $biCursorExhausted = false;

	/**
	 * @param \PgSql\Result|resource $result     First batch result resource
	 * @param PgsqlConnection        $connection  BI connection (used for FETCH/CLOSE)
	 * @param \Bitrix\Main\Diag\SqlTrackerQuery|null $trackerQuery
	 * @param string|null            $cursorName  Server-side cursor name
	 * @param int                    $cursorBatch Batch size
	 */
	public function __construct(
		$result,
		$connection = null,
		$trackerQuery = null,
		?string $cursorName = null,
		int $cursorBatch = 0
	)
	{
		parent::__construct($result, $connection, $trackerQuery);
		$this->biCursorName = $cursorName;
		$this->biCursorBatch = $cursorBatch;
	}

	/**
	 * Returns null because there is no way to know the fields.
	 *
	 * @return null
	 */
	public function getFields()
	{
		return null;
	}

	/**
	 * Returns next result row or false.
	 *
	 * @return array|false
	 */
	protected function fetchRowInternal()
	{
		if (!$this->resource)
		{
			return false;
		}

		while (true)
		{
			$row = pg_fetch_row($this->resource);

			if ($row !== false)
			{
				// Lazily detect bytea field positions from the current resource
				if ($this->byteaFieldIndexes === false)
				{
					$this->byteaFieldIndexes = [];
					$fieldsCount = pg_num_fields($this->resource);
					for ($i = 0; $i < $fieldsCount; $i++)
					{
						$fieldType = pg_field_type($this->resource, $i);
						if ($fieldType === 'bytea')
						{
							$this->byteaFieldIndexes[] = $i;
						}
					}
				}

				if ($this->byteaFieldIndexes)
				{
					foreach ($this->byteaFieldIndexes as $index)
					{
						if (array_key_exists($index, $row) && $row[$index] !== null)
						{
							$row[$index] = pg_unescape_bytea($row[$index]);
						}
					}
				}

				return $row;
			}

			// Current batch is exhausted - try to fetch the next one via cursor
			if ($this->biCursorName === null || $this->biCursorExhausted)
			{
				return false;
			}

			/** @var PgsqlConnection $conn */
			$conn = $this->connection;

			$nextBatch = $conn->fetchNextBiCursorBatch($this->biCursorName, $this->biCursorBatch);

			if ($nextBatch === false)
			{
				// FETCH failed - the server-side cursor is gone (e.g. an enclosing external
				// transaction was committed/rolled back before the result was fully read).
				// Surface this as an error instead of silently truncating the export as EOF.
				$this->biCursorExhausted = true;
				throw new \Bitrix\Main\DB\SqlQueryException(
					'BI cursor fetch failed: server-side cursor "' . $this->biCursorName . '" is no longer available'
				);
			}

			if (pg_num_rows($nextBatch) === 0)
			{
				// Genuine end of data - free the empty batch, close cursor and signal EOF.
				pg_free_result($nextBatch);
				$this->biCursorExhausted = true;
				$conn->closeBiCursor($this->biCursorName);
				return false;
			}

			// Free the exhausted batch before swapping so libpq releases each consumed batch
			// immediately during long streaming exports, instead of waiting for GC. Detected
			// bytea field positions are preserved across batches (the result shape is identical).
			pg_free_result($this->resource);
			$this->resource = $nextBatch;
		}
	}

	/**
	 * Closes the server-side BI cursor if the result was not fully consumed
	 * (early termination via exception/break/object destruction), so a WITH HOLD
	 * cursor does not leak for the whole lifetime of the connection.
	 */
	public function __destruct()
	{
		if (($this->biCursorName ?? null) !== null && !$this->biCursorExhausted)
		{
			$this->biCursorExhausted = true;
			try
			{
				/** @var PgsqlConnection $conn */
				$conn = $this->connection;
				if ($conn)
				{
					$conn->closeBiCursor($this->biCursorName);
				}
			}
			catch (\Throwable)
			{
				// A destructor must never throw.
			}
		}
	}
}
