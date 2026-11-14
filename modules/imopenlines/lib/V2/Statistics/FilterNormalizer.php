<?php

namespace Bitrix\ImOpenLines\V2\Statistics;

/**
 * Shared normalization for caller-supplied list filters (configId/operatorId/source)
 * used by the imopenlines.v2 statistics REST methods.
 *
 * These lists come straight from a public REST parameter and are pushed into ORM
 * `IN (...)` predicates - often reused across several aggregate queries - so they must
 * be deduped, type-normalized and bounded before they reach the query builder. Each
 * caller is responsible for turning {@see self::exceedsLimit()} into its own
 * INVALID_FILTER error (so the localized message stays with the calling service).
 */
final class FilterNormalizer
{
	/**
	 * Upper bound on the size of a single list filter. A larger caller-supplied list is
	 * rejected rather than turned into an unbounded `IN (...)`.
	 */
	public const MAX_LIST_SIZE = 1000;

	/**
	 * Normalizes a filter value to a deduped list of positive integers (for id lists).
	 *
	 * @return int[] Reindexed, unique, all > 0.
	 */
	public static function idList(mixed $value): array
	{
		$values = is_array($value) ? $value : [$value];
		$values = array_filter(array_map('intval', $values), static fn($v) => $v > 0);

		return array_values(array_unique($values));
	}

	/**
	 * Normalizes a filter value to a deduped list of non-empty strings (for source lists).
	 *
	 * @return string[] Reindexed, unique, all non-empty.
	 */
	public static function stringList(mixed $value): array
	{
		$values = is_array($value) ? $value : [$value];
		$values = array_filter(array_map('strval', $values), static fn($v) => $v !== '');

		return array_values(array_unique($values));
	}

	/**
	 * Whether a normalized list exceeds {@see self::MAX_LIST_SIZE}.
	 */
	public static function exceedsLimit(array $values): bool
	{
		return count($values) > self::MAX_LIST_SIZE;
	}
}
