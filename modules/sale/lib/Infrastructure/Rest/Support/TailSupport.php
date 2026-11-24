<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

use Bitrix\Main\DB\Order;
use Bitrix\Rest\V3\Structure\CursorStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;

/**
 * Cursor arithmetic of the `tail` action: one extra row is fetched to learn whether more data
 * follows, then dropped from the answer.
 */
final class TailSupport
{
	public static function getLimit(?CursorStructure $cursor): int
	{
		return $cursor?->getLimit() ?? PaginationStructure::DEFAULT_LIMIT;
	}

	/**
	 * The cursor limit obeys the same rule as the limit of a page, and for the same reason — see
	 * {@see PaginationSupport::isAcceptableLimit()}. Here an unlimited query costs one more thing:
	 * the probe cut turns into "every row but the last".
	 */
	public static function isLimitAcceptable(?CursorStructure $cursor): bool
	{
		return PaginationSupport::isAcceptableLimit(self::getLimit($cursor));
	}

	public static function getProbeLimit(?CursorStructure $cursor): int
	{
		return self::getLimit($cursor) + 1;
	}

	/**
	 * @param list<array<string, mixed>> $rows rows fetched with getProbeLimit()
	 * @return array{items: list<array<string, mixed>>, hasMore: bool}
	 */
	public static function cutProbe(array $rows, int $limit): array
	{
		$hasMore = count($rows) > $limit;

		return [
			'items' => $hasMore ? array_slice($rows, 0, $limit) : array_values($rows),
			'hasMore' => $hasMore,
		];
	}

	/**
	 * @return array<string, mixed> classic ORM filter fragment continuing the page
	 */
	public static function legacyFilter(?CursorStructure $cursor, string $column): array
	{
		if ($cursor === null)
		{
			return [];
		}

		$prefix = $cursor->getOrder() === Order::Desc ? '<' : '>';

		return [$prefix . $column => $cursor->getValue()];
	}

	/**
	 * @return array<string, string>
	 */
	public static function legacyOrder(?CursorStructure $cursor, string $column): array
	{
		return [$column => ($cursor?->getOrder() ?? Order::Asc)->value];
	}
}
