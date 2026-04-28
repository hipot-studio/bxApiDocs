<?php
namespace Bitrix\BIConnector\DB;

use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Diag;

class MysqliConnection extends \Bitrix\Main\DB\MysqliConnection
{
	protected $biMode = false;
	protected ?string $lastBiError = null;

	protected function createSqlHelper()
	{
		return new MysqliSqlHelper($this);
	}

	/**
	 * Executes query in special memory saving mode.
	 *
	 * @param string $sql Sql query.
	 *
	 * @return \Bitrix\BIConnector\DB\MysqliResult
	 */
	public function biQuery($sql)
	{
		$this->biMode = true;
		$this->lastBiError = null;

		try
		{
			$result = parent::query($sql);
		}
		catch (\Bitrix\Main\DB\ConnectionException $e)
		{
			$this->lastBiError = $e->getMessage();
			$this->biMode = false;

			return false;
		}
		catch (\Bitrix\Main\DB\SqlQueryException $e)
		{
			$this->lastBiError = $e->getMessage();
			$this->biMode = false;

			return false;
		}

		$this->biMode = false;

		return $result;
	}

	public function getLastBiError(): ?string
	{
		return $this->lastBiError;
	}

	/**
	 * @inheritDoc
	 */
	protected function queryInternal($sql, ?array $binds = null, ?Diag\SqlTrackerQuery $trackerQuery = null)
	{
		$this->connectInternal();

		if ($trackerQuery !== null)
		{
			$trackerQuery->startQuery($sql, $binds);
		}

		if ($this->biMode)
		{
			$result = $this->resource->prepare($sql);
		}
		else
		{
			$result = $this->resource->query($sql, MYSQLI_STORE_RESULT);
		}

		if ($trackerQuery !== null)
		{
			$trackerQuery->finishQuery();
		}

		$this->lastQueryResult = $result;

		if (!$result)
		{
			throw new SqlQueryException('Mysql query error', $this->getErrorMessage(), $sql);
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
			$result = new \Bitrix\BIConnector\DB\MysqliResult($result, $this, $trackerQuery);
		}
		else
		{
			$result = new \Bitrix\Main\DB\MysqliResult($result, $this, $trackerQuery);
		}

		return $result;
	}

	/**
	 * @inheritDoc
	 */
	public function getErrorMessage()
	{
		return parent::getErrorMessage();
	}
}
