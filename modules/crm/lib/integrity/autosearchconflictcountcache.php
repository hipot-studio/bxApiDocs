<?php

namespace Bitrix\Crm\Integrity;

use Bitrix\Main\Application;

/**
 * Conflict count of the user's automatic dedupe queue, kept in the managed cache.
 * Dropped by every path that changes the queue: status change, item removal, mismatch and entity deletion.
 */
final class AutoSearchConflictCountCache
{
	private const TTL = 600;

	public static function get(int $entityTypeId, int $userId, callable $calculate, bool $forceRecalculate = false): int
	{
		$cache = Application::getInstance()->getManagedCache();
		$key = self::getKey($entityTypeId, $userId);

		// read() registers the entry: without it set() silently stores nothing
		$isCached = $cache->read(self::TTL, $key);
		if ($isCached && !$forceRecalculate)
		{
			return (int)$cache->get($key);
		}

		$count = (int)$calculate();
		$cache->set($key, $count);

		return $count;
	}

	public static function clean(int $entityTypeId, int $userId): void
	{
		Application::getInstance()->getManagedCache()->clean(self::getKey($entityTypeId, $userId));
	}

	private static function getKey(int $entityTypeId, int $userId): string
	{
		return 'crm_dp_autosearch_conflicts_' . $entityTypeId . '_' . $userId;
	}
}
