<?php
namespace Bitrix\BIConnector\DB;

use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Diag;
use Bitrix\Main;
use Bitrix\BIConnector;

class PgsqlConnection extends Main\DB\PgsqlConnection
{
	protected $biMode = false;

	protected function createSqlHelper()
	{
		return new PgsqlSqlHelper($this);
	}

	protected ?string $lastBiError = null;

	/**
	 * Executes query in special memory saving mode.
	 *
	 * @param string $sql Sql query.
	 *
	 * @return Main\DB\PgsqlResult | bool
	 */
	public function biQuery($sql)
	{
		$this->biMode = true;
		$this->lastBiError = null;

		$result = false;

		try
		{
			$result = parent::query($sql);
		}
		catch (Main\DB\ConnectionException $e)
		{
			$this->lastBiError = $e->getMessage();
		}
		catch (Main\DB\SqlQueryException $e)
		{
			$this->lastBiError = $e->getMessage();
		}
		finally
		{
			$this->biMode = false;
		}

		return $result;
	}

	public function getLastBiError(): ?string
	{
		return $this->lastBiError;
	}

	/**
	 * @param $sql
	 * @param array|null $binds
	 * @param Diag\SqlTrackerQuery|null $trackerQuery
	 * @return \PgSql\Result|resource
	 * @throws Main\DB\ConnectionException
	 * @throws SqlQueryException
	 */
	protected function queryInternal($sql, ?array $binds = null, ?Diag\SqlTrackerQuery $trackerQuery = null)
	{
		$this->connectInternal();

		$trackerQuery?->startQuery($sql, $binds);

		set_error_handler(static fn() => null);

		if ($this->biMode)
		{
			$statementName = 'bi_query_' . substr(sha1($sql), 0, 12);
			$prepared = pg_prepare($this->resource, $statementName, $sql);
			if ($prepared)
			{
				$result = pg_execute($this->resource, $statementName, []);
			}
			else
			{
				$result = false;
			}
		}
		else
		{
			$result = pg_query($this->resource, $sql);
		}

		restore_error_handler();
		$trackerQuery?->finishQuery();

		$this->lastQueryResult = $result;

		if (!$result)
		{
			throw new SqlQueryException('Postgresql query error', $this->getErrorMessage(), $sql);
		}

		return $result;
	}

	/**
	 * @inheritDoc
	 */
	protected function createResult($result, ?Diag\SqlTrackerQuery $trackerQuery = null)
	{
		if ($this->biMode)
		{
			return new BIConnector\DB\PgsqlResult($result, $this, $trackerQuery);
		}

		return new Main\DB\PgsqlResult($result, $this, $trackerQuery);
	}
}
