<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Sync;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Event;
use Bitrix\Main\Type\DateTime;

/**
 * Server-side retention boundary for b_im_log.
 *
 * log_incomplete_before is the timestamp up to which (inclusive) log rows are
 * not guaranteed to survive. It is read straight from the primary, bypassing
 * Option::get's process/managed cache, which can lag behind a concurrent
 * writer. It only ever moves forward: raiseTo() takes max(old, candidate),
 * and every writer holds LOCK_NAME while doing so, or a "read - compare -
 * write" race could move it backwards.
 */
class LogBoundary
{
	public const LOCK_NAME = 'im_log_cleanup';
	public const OPTION_NAME = 'log_incomplete_before';
	public const PAUSED_OPTION_NAME = 'sync_logger_paused';

	private const MODULE_ID = 'im';

	// The lock must outlive one LogCleanupAgent pass: its time budget
	// (LogCleanupAgent::DEFAULT_TIME_BUDGET, 20 s) plus one portion delete.
	private const LOCK_TIMEOUT = 60;

	/**
	 * Reads the boundary and the current DB time in one round trip on the primary.
	 *
	 * @return array{boundary: ?int, dbNow: int}
	 * @throws LogBoundaryException
	 */
	public static function readFromPrimary(): array
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);

		try
		{
			$connection = Application::getConnection();
			$helper = $connection->getSqlHelper();

			$sql = '
				SELECT
					' . $helper->getDateToCharFunction($helper->getCurrentDateTimeFunction()) . ' AS DB_NOW,
					o.NAME AS BOUNDARY_NAME,
					o.VALUE AS BOUNDARY_VALUE
				FROM (SELECT 1 AS ONE_ROW) d
				LEFT JOIN b_option o
					ON o.MODULE_ID = \'' . self::MODULE_ID . '\'
					AND o.NAME = \'' . self::OPTION_NAME . '\'
					AND o.SITE_ID IS NULL
			';

			try
			{
				$row = $connection->query($sql)->fetch();
			}
			catch (SqlQueryException $exception)
			{
				// Wrapped so the controller can tell this apart from other errors and
				// answer LOG_BOUNDARY_UNAVAILABLE instead of a generic error code.
				throw new LogBoundaryException('Cannot read the log boundary: query failed', 0, '', 0, $exception);
			}
		}
		finally
		{
			$pool->useMasterOnly(false);
		}

		if (!is_array($row))
		{
			throw new LogBoundaryException('Cannot read the log boundary: query returned no row');
		}

		return static::parseRow($row);
	}

	/**
	 * Raises the boundary to at least $timestamp. Never decreases it.
	 *
	 * Caller must hold LOCK_NAME.
	 *
	 * @throws LogBoundaryException
	 */
	public static function raiseTo(int $timestamp): void
	{
		$state = self::readFromPrimary();

		if ($state['boundary'] !== null && $state['boundary'] >= $timestamp)
		{
			return;
		}

		Option::set(self::MODULE_ID, self::OPTION_NAME, (string)$timestamp);
	}

	/**
	 * Acquires LOCK_NAME and raises the boundary to now().
	 *
	 * @throws LogBoundaryException
	 */
	public static function raiseToNow(): void
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);

		try
		{
			$connection = Application::getConnection();

			if (!$connection->lock(self::LOCK_NAME, self::LOCK_TIMEOUT))
			{
				throw new LogBoundaryException('Cannot acquire the log cleanup lock to raise the boundary');
			}

			try
			{
				self::raiseTo(time());
			}
			finally
			{
				$connection->unlock(self::LOCK_NAME);
			}
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	/**
	 * OnAfterSetOption_sync_logger_enable handler.
	 *
	 * main:OnAfterSetOption_<name> fires for that option name regardless of
	 * which module or site wrote it, so the event's "value" parameter cannot
	 * be trusted: rereading SyncService::isEnable() makes the handler a no-op
	 * for anyone else's option of the same name.
	 *
	 * The journal only pauses/resumes through this option, never through a
	 * direct b_option edit or Option::delete - those are excluded by the
	 * operational contract, not handled here.
	 *
	 * The marker check, the raise, and the marker removal all run under
	 * LOCK_NAME so a concurrent enable/disable pair cannot interleave and
	 * leave the marker cleared without the boundary actually raised.
	 *
	 * A raise failure is not swallowed: the paused marker stays set, so
	 * the next enable attempt retries the raise instead of silently leaving a
	 * gap uncovered.
	 *
	 * @throws LogBoundaryException
	 */
	public static function onSyncLoggerOptionChanged(Event $event): void
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);

		try
		{
			$connection = Application::getConnection();

			if (!$connection->lock(self::LOCK_NAME, self::LOCK_TIMEOUT))
			{
				throw new LogBoundaryException('Cannot acquire the log cleanup lock to handle the sync logger option change');
			}

			try
			{
				if (!SyncService::isEnable())
				{
					Option::set(self::MODULE_ID, self::PAUSED_OPTION_NAME, 'Y');

					return;
				}

				if (Option::get(self::MODULE_ID, self::PAUSED_OPTION_NAME, 'N') === 'Y')
				{
					self::raiseTo(time());
					Option::delete(self::MODULE_ID, ['name' => self::PAUSED_OPTION_NAME]);
				}
			}
			finally
			{
				$connection->unlock(self::LOCK_NAME);
			}
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	/**
	 * @return array{boundary: ?int, dbNow: int}
	 * @throws LogBoundaryException
	 */
	protected static function parseRow(array $row): array
	{
		$dbNowRaw = $row['DB_NOW'] ?? null;

		// On MySQL, Result::fetch() already converts a DATETIME/TIMESTAMP-typed
		// column (which NOW() is) into a DateTime object based on the driver's
		// column type metadata, regardless of the alias name - the raw-string
		// branch below is for engines/drivers that hand back the column as text.
		if ($dbNowRaw instanceof DateTime)
		{
			$dbNowDateTime = $dbNowRaw;
		}
		elseif (is_string($dbNowRaw) && $dbNowRaw !== '')
		{
			try
			{
				$helper = Application::getConnection()->getSqlHelper();
				$dbNowDateTime = $helper->convertFromDbDateTime($dbNowRaw);
			}
			catch (\Throwable $exception)
			{
				throw new LogBoundaryException('Cannot parse the current DB time: ' . $dbNowRaw, 0, '', 0, $exception);
			}

			if (!($dbNowDateTime instanceof DateTime))
			{
				throw new LogBoundaryException('Cannot parse the current DB time: ' . $dbNowRaw);
			}
		}
		else
		{
			throw new LogBoundaryException('Cannot read the current DB time for the log boundary');
		}

		$dbNow = $dbNowDateTime->getTimestamp();

		if (($row['BOUNDARY_NAME'] ?? null) === null)
		{
			// No row in b_option: the boundary was never published, no gap for anyone.
			return ['boundary' => null, 'dbNow' => $dbNow];
		}

		$boundary = filter_var($row['BOUNDARY_VALUE'] ?? null, FILTER_VALIDATE_INT);
		if ($boundary === false || $boundary <= 0)
		{
			// A stored but unreadable value is an unknown state, never "no gap".
			throw new LogBoundaryException('The log boundary value is unreadable');
		}

		return ['boundary' => $boundary, 'dbNow' => $dbNow];
	}
}
