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

	/** @var int Monotonic counter for unique cursor names within this connection */
	protected int $biCursorCounter = 0;

	/** Batch size for FETCH FORWARD in cursor mode */
	const BI_CURSOR_BATCH_SIZE = 1000;

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
			// Prefer the raw PG error captured in openBiCursor() before cleanup;
			// fall back to the exception message (e.g. connection errors).
			$this->lastBiError = $this->lastBiError ?: $e->getMessage();
		}
		catch (Main\DB\SqlQueryException $e)
		{
			$this->lastBiError = $this->lastBiError ?: $e->getMessage();
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
	 * In BI cursor mode the cleanup queries (ROLLBACK / COMMIT / CLOSE) run before
	 * the error is surfaced and reset pg_last_error(). openBiCursor() captures the
	 * real error into $this->lastBiError beforehand, so prefer it here. Reset per
	 * queryInternal() call keeps this from leaking into unrelated queries.
	 *
	 * @return string
	 */
	public function getErrorMessage()
	{
		if ($this->lastBiError !== null && $this->lastBiError !== '')
		{
			return $this->lastBiError;
		}

		return parent::getErrorMessage();
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
		$this->lastBiError = null;

		$this->connectInternal();

		$trackerQuery?->startQuery($sql, $binds);

		set_error_handler(static fn() => null);

		if ($this->biMode)
		{
			$result = $this->openBiCursor($sql);
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
	 * Opens a server-side cursor for the given SQL and returns the first batch result.
	 * Uses WITH HOLD so the cursor survives COMMIT and does not hold locks.
	 *
	 * @param string $sql
	 * @return \PgSql\Result|false
	 * @throws SqlQueryException
	 */
	protected function openBiCursor(string $sql)
	{
		$cursorName = 'bi_cursor_' . (++$this->biCursorCounter) . '_' . substr(sha1($sql), 0, 8);
		$batch = static::BI_CURSOR_BATCH_SIZE;

		// Check whether we are already inside an external transaction.
		// PGSQL_TRANSACTION_INTRANS = 2, PGSQL_TRANSACTION_INERROR = 3,
		// PGSQL_TRANSACTION_ACTIVE = 1 (query in progress)
		$txStatus = pg_transaction_status($this->resource);
		$externalTransaction = ($txStatus === PGSQL_TRANSACTION_INTRANS || $txStatus === PGSQL_TRANSACTION_ACTIVE);

		if (!$externalTransaction)
		{
			// Start our own transaction so we can DECLARE a cursor, then COMMIT
			// with WITH HOLD to release locks while keeping the cursor alive.
			$beginResult = pg_query($this->resource, 'BEGIN');
			if ($beginResult === false)
			{
				$this->lastBiError = pg_last_error($this->resource);

				return false;
			}
		}

		// Escape cursor name (it is constructed from safe chars, but be explicit)
		$escapedCursorName = pg_escape_identifier($this->resource, $cursorName);

		// WITH HOLD: cursor survives COMMIT, data is spooled server-side.
		// Without HOLD: cursor is only valid inside the transaction (no lock release until close).
		// We prefer WITH HOLD when we started our own transaction.
		$holdClause = $externalTransaction ? '' : ' WITH HOLD';
		$declareResult = pg_query(
			$this->resource,
			"DECLARE {$escapedCursorName} NO SCROLL CURSOR{$holdClause} FOR {$sql}"
		);

		if ($declareResult === false)
		{
			// Capture the real DB error BEFORE any cleanup query wipes pg_last_error().
			$this->lastBiError = pg_last_error($this->resource);
			if (!$externalTransaction)
			{
				pg_query($this->resource, 'ROLLBACK');
			}
			return false;
		}

		if (!$externalTransaction)
		{
			$commitResult = pg_query($this->resource, 'COMMIT');
			if ($commitResult === false)
			{
				// Capture the real DB error BEFORE the cleanup ROLLBACK wipes pg_last_error().
				$this->lastBiError = pg_last_error($this->resource);
				// Cursor might be gone; try to clean up
				pg_query($this->resource, 'ROLLBACK');
				return false;
			}
		}

		// Fetch the first batch
		$fetchResult = pg_query(
			$this->resource,
			"FETCH FORWARD {$batch} FROM {$escapedCursorName}"
		);

		if ($fetchResult === false)
		{
			// Capture the real DB error BEFORE the cleanup CLOSE wipes pg_last_error().
			$this->lastBiError = pg_last_error($this->resource);
			// Attempt to close the cursor to avoid leaking it
			pg_query($this->resource, "CLOSE {$escapedCursorName}");
			return false;
		}

		// Store cursor metadata so PgsqlResult can fetch more batches
		$this->biLastCursorName = $cursorName;
		$this->biLastCursorBatch = $batch;

		return $fetchResult;
	}

	/** @var string|null Name of the most recently opened bi cursor */
	public ?string $biLastCursorName = null;

	/** @var int Batch size used for the most recently opened bi cursor */
	public int $biLastCursorBatch = 0;

	/**
	 * Fetches the next batch from a named server-side cursor.
	 * Called by PgsqlResult when the current batch is exhausted.
	 *
	 * @param string $cursorName
	 * @param int $batch
	 * @return \PgSql\Result|false
	 */
	public function fetchNextBiCursorBatch(string $cursorName, int $batch)
	{
		$escapedCursorName = pg_escape_identifier($this->resource, $cursorName);
		return pg_query($this->resource, "FETCH FORWARD {$batch} FROM {$escapedCursorName}");
	}

	/**
	 * Closes a named server-side cursor. Called by PgsqlResult when data is exhausted.
	 *
	 * @param string $cursorName
	 */
	public function closeBiCursor(string $cursorName): void
	{
		$escapedCursorName = pg_escape_identifier($this->resource, $cursorName);
		pg_query($this->resource, "CLOSE {$escapedCursorName}");
	}

	/**
	 * @inheritDoc
	 */
	protected function createResult($result, ?Diag\SqlTrackerQuery $trackerQuery = null)
	{
		if ($this->biMode)
		{
			return new BIConnector\DB\PgsqlResult(
				$result,
				$this,
				$trackerQuery,
				$this->biLastCursorName,
				$this->biLastCursorBatch
			);
		}

		return new Main\DB\PgsqlResult($result, $this, $trackerQuery);
	}
}
