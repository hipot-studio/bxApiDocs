<?php

declare(strict_types=1);

namespace Bitrix\Call\Reaction;

use Bitrix\Call\Settings;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Data\Cache\KeyValueEngine;

/**
 * Coarse floor between two analytics events of the same user.
 *
 * The pace a person actually sees is set by the client, which will not send faster than
 * {@see Settings::getReactionThrottleMs()}; this bounds what a caller reaching the endpoint directly
 * can write into analytics. The quick row history does not depend on it: every use is recorded, and
 * {@see ReactionUsageStore} bounds the option writes itself. The claim is atomic
 * ({@see KeyValueEngine::setNotExists()}), so two parallel requests cannot both take the same window -
 * a get/set pair would let both through.
 *
 * A deployment on file cache has no shared key-value storage, and the window is marked on the ordinary cache there.
 */
final class ReactionThrottle
{
	private const PLAIN_CACHE_DIR = '/call/reaction_use_window';

	public function __construct(
		private readonly ?KeyValueEngine $engine = null,
		private readonly ?Cache $plainCache = null,
	)
	{
	}

	public function claim(int $userId): bool
	{
		return $this->claimWindow('reaction_use', $userId, $this->windowSeconds());
	}

	private function claimWindow(string $name, int $userId, int $seconds): bool
	{
		if ($userId < 1)
		{
			return false;
		}

		$engine = $this->plainCache === null ? ($this->engine ?? self::sharedKeyValueEngine()) : null;
		if ($engine === null)
		{
			return $this->claimOnPlainCache($name, $userId, $seconds);
		}

		return (bool)$engine->setNotExists($this->claimKey($name, $userId), $seconds, 1);
	}

	/**
	 * Unlike setNotExists() this is not atomic: two requests at the very same moment may both pass,
	 * which costs one extra event and still bounds a stream of direct calls.
	 */
	private function claimOnPlainCache(string $name, int $userId, int $seconds): bool
	{
		$cache = $this->plainCache ?? Cache::createInstance();
		$cache->noOutput();
		if (!$cache->startDataCache($seconds, $name . ':' . $userId, self::PLAIN_CACHE_DIR))
		{
			return false;
		}
		$cache->endDataCache([1]);

		return true;
	}

	/**
	 * The claim lives a whole number of seconds while the interval is configured in milliseconds, so a
	 * sub-second interval becomes one second. Rounding it down instead would leave no window at all,
	 * and the sub-second pace is the client's job anyway.
	 */
	private function windowSeconds(): int
	{
		return max(1, (int)ceil(Settings::getReactionThrottleMs() / 1000));
	}

	private function claimKey(string $name, int $userId): string
	{
		$cacheConfig = Configuration::getValue('cache');
		$sid = (is_array($cacheConfig) && !empty($cacheConfig['sid'])) ? $cacheConfig['sid'] : 'BX';

		return $sid . '|call:' . $name . ':' . $userId;
	}

	private static function sharedKeyValueEngine(): ?KeyValueEngine
	{
		$engine = Cache::createCacheEngine();

		return $engine instanceof KeyValueEngine ? $engine : null;
	}
}
