<?php

namespace Bitrix\BIConnector\DB;

use Bitrix\Main;

/**
 * Result of a ClickHouse query.
 *
 * Rows are read from the response stream one by one, so a wide selection is never held in memory
 * as a whole. That makes it usable on the BI read path without a separate memory saving mode.
 */
class ClickhouseResult extends Main\DB\Result
{
	private ?array $resultFields = null;
	private int $fetchedRowsCount = 0;
	private bool $exhausted = false;
	private bool $biMode = false;

	/**
	 * @param ClickhouseQueryResult $result
	 * @param Main\DB\Connection|null $dbConnection
	 * @param Main\Diag\SqlTrackerQuery|null $trackerQuery
	 * @param bool $biMode Return rows as lists of values instead of name to value maps.
	 */
	public function __construct(
		$result,
		?Main\DB\Connection $dbConnection = null,
		?Main\Diag\SqlTrackerQuery $trackerQuery = null,
		bool $biMode = false,
	)
	{
		// Assigned before the parent constructor: that one calls getFields(), whose answer depends on
		// the mode.
		$this->biMode = $biMode;

		parent::__construct($result, $dbConnection, $trackerQuery);
	}

	/**
	 * @return Main\ORM\Fields\ScalarField[]
	 */
	public function getFields()
	{
		if ($this->biMode)
		{
			// In this mode a row is a list of values, so a description keyed by column name would only
			// make the base class build converters for keys the rows do not carry.
			return [];
		}

		if ($this->resultFields === null)
		{
			$this->resultFields = [];

			$queryResult = $this->getQueryResult();
			if ($queryResult && $this->connection)
			{
				$helper = $this->connection->getSqlHelper();
				$types = $queryResult->getColumnTypes();

				foreach ($queryResult->getColumnNames() as $index => $columnName)
				{
					$this->resultFields[$columnName] = $helper->getFieldByColumnType(
						$columnName,
						$types[$index] ?? 'String'
					);
				}
			}
		}

		return $this->resultFields;
	}

	/**
	 * Number of rows read so far.
	 *
	 * The HTTP interface streams the answer, so the total count is not known upfront and the value
	 * grows while the result is being read.
	 *
	 * @return int
	 */
	public function getSelectedRowsCount()
	{
		return $this->fetchedRowsCount;
	}

	/**
	 * @return int
	 */
	public function getFieldsCount(): int
	{
		$queryResult = $this->getQueryResult();

		return $queryResult ? count($queryResult->getColumnNames()) : 0;
	}

	/**
	 * @return array|false
	 * @throws Main\DB\SqlQueryException
	 */
	protected function fetchRowInternal()
	{
		if ($this->exhausted)
		{
			return false;
		}

		$queryResult = $this->getQueryResult();
		if (!$queryResult)
		{
			$this->exhausted = true;

			return false;
		}

		$row = $this->biMode ? $queryResult->fetchValues() : $queryResult->fetchRow();
		if ($row === null)
		{
			$this->exhausted = true;
			$queryResult->close();

			return false;
		}

		$this->fetchedRowsCount++;

		return $row;
	}

	private function getQueryResult(): ?ClickhouseQueryResult
	{
		return $this->resource instanceof ClickhouseQueryResult ? $this->resource : null;
	}
}
