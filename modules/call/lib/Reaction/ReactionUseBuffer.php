<?php

declare(strict_types=1);

namespace Bitrix\Call\Reaction;

use Bitrix\Main\Data\Cache;

/**
 * Uses of reactions not yet written to the user options, kept in the ordinary cache.
 *
 * The update is a read-modify-write, not atomic: two devices of one user reacting at the very same
 * moment can lose one use. Only the order of six pictures depends on it.
 */
final class ReactionUseBuffer
{
	// Outlives a pause between two calls: the buffer is written out by the next use or quick row read.
	private const TTL_SECONDS = 604800;
	private const CACHE_DIR = '/call/reaction_use';

	/**
	 * @return array{startedAt: int, counters: array<string, int>, recent: string[]}|null
	 */
	public function read(int $userId): ?array
	{
		$cache = Cache::createInstance();
		if (!$cache->initCache(self::TTL_SECONDS, $this->cacheId($userId), self::CACHE_DIR))
		{
			return null;
		}

		$pending = $cache->getVars();
		if (
			!is_array($pending)
			|| !is_int($pending['startedAt'] ?? null)
			|| !is_array($pending['counters'] ?? null)
			|| !is_array($pending['recent'] ?? null)
		)
		{
			return null;
		}

		return [
			'startedAt' => $pending['startedAt'],
			'counters' => $pending['counters'],
			'recent' => $pending['recent'],
		];
	}

	/**
	 * @param array{startedAt: int, counters: array<string, int>, recent: string[]} $pending
	 */
	public function write(int $userId, array $pending): void
	{
		$cache = Cache::createInstance();
		$cache->noOutput();
		$cache->forceRewriting(true);
		if ($cache->startDataCache(self::TTL_SECONDS, $this->cacheId($userId), self::CACHE_DIR))
		{
			$cache->endDataCache($pending);
		}
	}

	public function clear(int $userId): void
	{
		Cache::createInstance()->clean($this->cacheId($userId), self::CACHE_DIR);
	}

	private function cacheId(int $userId): string
	{
		return 'reaction_use:' . $userId;
	}
}
