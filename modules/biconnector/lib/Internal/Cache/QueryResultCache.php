<?php
namespace Bitrix\BIConnector\Internal\Cache;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Cache;

/**
 * Cross-request cache for PBI/DataLens query results (Tier 2).
 * Keyed by the full request shape; stores {body, count}. Query logging is not cached.
 */
final class QueryResultCache
{
	private const TTL = 30;                                   // seconds - knee from prod log analysis
	private const MAX_ENTRY_BYTES_DEFAULT = 8 * 1024 * 1024;  // conservative; dashboard refreshes are small
	private const MAX_ENTRY_BYTES_MEMCACHE = 900 * 1024;      // memcache(d) item limit is ~1MB, stay under it
	private const MAX_ENTRY_BYTES_OPTION = 'pbi_query_cache_max_bytes';
	private const ENABLED_OPTION = 'pbi_query_cache';
	private const CACHE_DIR = '/biconnector/pbi_query/';

	/**
	 * Tier 2 is on only when the kill switch allows it and the cache runs on an in-memory engine.
	 * A file engine (default in box) would write multi-MB entries to disk every TTL, so it is excluded.
	 */
	public static function isEnabled(): bool
	{
		if (Option::get('biconnector', self::ENABLED_OPTION, 'Y') !== 'Y')
		{
			return false;
		}

		return !in_array(Cache::getCacheEngineType(), ['cacheenginefiles', 'cacheenginenone'], true);
	}

	/**
	 * Max cacheable body size. The engine-aware default keeps memcache(d) under its ~1MB item limit
	 * (otherwise 1-8MB bodies would be captured and serialized every request only to fail the write),
	 * while Redis/Valkey get the larger default. The Option overrides both. 0 or invalid falls back.
	 */
	public static function getMaxEntryBytes(): int
	{
		$default = in_array(Cache::getCacheEngineType(), ['cacheenginememcache', 'cacheenginememcached'], true)
			? self::MAX_ENTRY_BYTES_MEMCACHE
			: self::MAX_ENTRY_BYTES_DEFAULT
		;
		$configured = (int)Option::get('biconnector', self::MAX_ENTRY_BYTES_OPTION, (string)$default);

		return $configured > 0 ? $configured : $default;
	}

	/**
	 * Builds a capture sink bounded by {@see getMaxEntryBytes()}.
	 */
	public static function createCapture(): BodyCapture
	{
		return new BodyCapture(self::getMaxEntryBytes());
	}

	public static function buildKey(
		string $accessKey,
		string $connection,
		string $revision,
		string $table,
		array $input,
		int $limit,
		int $licenseLimit,
		string $consumer,
		string $language,
		bool $isV2
	): string
	{
		return md5(serialize([
			$accessKey, $connection, $revision, $table, self::normalize($input),
			$limit, $licenseLimit, $consumer, $language, $isV2,
		]));
	}

	/**
	 * @return array{body:string,count:int}|null
	 */
	public static function get(string $key): ?array
	{
		// The cache is on by default on in-memory engines; a backend hiccup (timeout, dropped
		// connection) must degrade to a miss, not fail the public export with a 500.
		try
		{
			$cache = Cache::createInstance();
			if ($cache->initCache(self::TTL, $key, self::CACHE_DIR))
			{
				$vars = $cache->getVars();
				if (is_array($vars) && isset($vars['body']))
				{
					return $vars;
				}
			}
		}
		catch (\Throwable $e)
		{
		}

		return null;
	}

	public static function set(string $key, array $payload): void
	{
		if (strlen($payload['body'] ?? '') > self::getMaxEntryBytes())
		{
			return; // do not cache pathologically large responses
		}

		// A failed cache write is non-fatal: the response is already streamed to the client.
		try
		{
			$cache = Cache::createInstance();
			$cache->noOutput(); // vars-only entry: skip the internal output buffer
			if ($cache->startDataCache(self::TTL, $key, self::CACHE_DIR))
			{
				$cache->endDataCache($payload);
			}
		}
		catch (\Throwable $e)
		{
		}
	}

	private static function normalize(array $value): array
	{
		ksort($value);
		foreach ($value as $k => $v)
		{
			if (is_array($v))
			{
				$value[$k] = self::normalize($v);
			}
		}

		return $value;
	}
}
