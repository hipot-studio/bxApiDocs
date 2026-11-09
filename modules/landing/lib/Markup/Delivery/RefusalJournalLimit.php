<?php

declare(strict_types=1);

namespace Bitrix\Landing\Markup\Delivery;

use Bitrix\Main\Data\Cache;

/**
 * Limit of the refusal lines the file controller writes to the lifecycle journal.
 *
 * The controller answers anyone, so its refusals are counted per minute and the lines over
 * the limit are skipped. Links with a bad signature were not issued by the portal: they are
 * counted apart from the refusals of valid links (a file of an older version, an unpublished
 * site), so a flood of forged links does not hide those. The last written line of a minute
 * tells until when the rest is skipped. The counter is shared by the requests through the cache
 * and is approximate: concurrent requests may write a line or two over the limit.
 */
class RefusalJournalLimit
{
	public const WINDOW = 60;
	public const UNTRUSTED_LIMIT = 20;
	public const TRUSTED_LIMIT = 60;

	private const GROUP_UNTRUSTED = 'untrusted';
	private const GROUP_TRUSTED = 'trusted';
	private const CACHE_DIR = '/landing/markup_file_refusals';

	/**
	 * Takes a place in the journal for a refusal.
	 * @return array|null Fields to add to the journal line; null when the line is skipped.
	 */
	public function take(string $reason): ?array
	{
		$isUntrusted = $reason === FileAccess::ERROR_BAD_SIGNATURE;
		$group = $isUntrusted ? self::GROUP_UNTRUSTED : self::GROUP_TRUSTED;
		$limit = $isUntrusted ? self::UNTRUSTED_LIMIT : self::TRUSTED_LIMIT;
		$window = intdiv($this->now(), self::WINDOW);

		try
		{
			$counter = $this->readCounter($group);
			$count = (int)($counter['window'] ?? -1) === $window ? (int)($counter['count'] ?? 0) : 0;
			if ($count >= $limit)
			{
				return null;
			}

			$count++;
			$this->writeCounter($group, ['window' => $window, 'count' => $count]);
		}
		catch (\Throwable)
		{
			// without the counter the journal of an anonymous endpoint is not bounded
			return null;
		}

		return $count === $limit
			? ['restSkippedUntil' => date('Y-m-d H:i:s', ($window + 1) * self::WINDOW)]
			: [];
	}

	protected function now(): int
	{
		return time();
	}

	/**
	 * @return array{window?: int, count?: int}
	 */
	protected function readCounter(string $group): array
	{
		$cache = Cache::createInstance();
		if ($cache->initCache(self::WINDOW * 2, $group, self::CACHE_DIR))
		{
			$counter = $cache->getVars();

			return is_array($counter) ? $counter : [];
		}

		return [];
	}

	/**
	 * @param array{window: int, count: int} $counter
	 */
	protected function writeCounter(string $group, array $counter): void
	{
		$cache = Cache::createInstance();
		$cache->noOutput();
		$cache->forceRewriting(true);
		if ($cache->startDataCache(self::WINDOW * 2, $group, self::CACHE_DIR))
		{
			$cache->endDataCache($counter);
		}
	}
}
