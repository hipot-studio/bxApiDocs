<?php

namespace Bitrix\BIConnector\Superset\Cache;

use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Application;
use Bitrix\Main\Error;

/**
 * Drops the caches holding the BI table list once the portal has changed it.
 *
 * The list a portal publishes is answered from cache on two levels: the Trino plugin keeps the
 * `show_tables` answer of the portal, and Superset keeps the table comments of the schema. Both live
 * for an hour, so a table added, renamed or removed on the portal stays invisible — or keeps its
 * previous name and description — until they expire. A user who imports a table and goes straight to
 * Superset to build a dataset from it meets exactly that: the page offers the technical code alone.
 *
 * The clear runs in a background job and its outcome is deliberately ignored. The table is already
 * saved by then, so a Superset that is absent, frozen, or (in the proxy scheme of a development
 * machine) has no controlling controller behind it must never turn a saved table into a failed
 * operation. Without the clear the user simply waits out the caches, which is what would have
 * happened anyway.
 *
 * The sweep is the instance-wide one, so it also moves the timer of the manual "clear cache" button.
 * That is acceptable while the trigger stays a rare, user-initiated save; a targeted invalidation of
 * the table list alone would be the way out if it ever becomes frequent.
 */
final class TableListCacheInvalidator
{
	public static function schedule(): void
	{
		Application::getInstance()->addBackgroundJob(static function (): void {
			try
			{
				$result = CacheManager::getInstance()->clear();
				if (!$result->isSuccess())
				{
					Logger::logWarning($result->getErrors(), [
						'message' => 'Table list cache invalidation failed',
					]);
				}
			}
			catch (\Throwable $e)
			{
				Logger::logErrors([new Error($e->getMessage())], [
					'message' => 'Table list cache invalidation failed',
				]);
			}
		});
	}
}
