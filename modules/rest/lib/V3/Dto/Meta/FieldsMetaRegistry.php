<?php

namespace Bitrix\Rest\V3\Dto\Meta;

/**
 * Process-wide FieldsMeta per description cache key.
 * An entry is valid while its source array is identical (===) to the array the managed cache returns,
 * so any cache reset that changes the description rebuilds it in the same process.
 *
 * @internal
 */
final class FieldsMetaRegistry
{
	/** @var array<string, array{source: array, meta: FieldsMeta}> */
	private static array $entries = [];

	private static int $buildCount = 0;

	public static function get(string $cacheKey, array $source): FieldsMeta
	{
		if (isset(self::$entries[$cacheKey]) && self::$entries[$cacheKey]['source'] === $source)
		{
			// An equal array re-read from the cache file takes the place of the old one,
			// so the next comparison ends on the same-array shortcut of ===.
			self::$entries[$cacheKey]['source'] = $source;

			return self::$entries[$cacheKey]['meta'];
		}

		$meta = FieldsMeta::fromCacheArray($source);
		self::$entries[$cacheKey] = [
			'source' => $source,
			'meta' => $meta,
		];
		self::$buildCount++;

		return $meta;
	}

	/**
	 * @internal for tests
	 */
	public static function getBuildCount(): int
	{
		return self::$buildCount;
	}
}
