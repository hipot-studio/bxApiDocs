<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Query;

use Bitrix\Im\V2\Integration\Search\SearchError;
use Bitrix\Im\V2\Result;

/**
 * Normalized input of the message search facade.
 *
 * The text of the query reaches the engine as it was typed: special characters, quotes, emoji and
 * BB-like sequences are neither escaped nor stripped here - parsing the query syntax is the work of
 * CSearchQuery, which serves the whole search of the portal.
 */
final class MessageSearchQuery
{
	public const
		MIN_QUERY_LENGTH = 3,
		MAX_QUERY_LENGTH = 300,
		DEFAULT_LIMIT = 20,
		MAX_LIMIT = 50
	;

	private function __construct(
		public readonly string $query,
		public readonly SortOrder $sort,
		public readonly int $offset,
		public readonly int $limit,
	)
	{}

	/**
	 * @return Result<self>
	 */
	public static function parse(string $query, ?string $sort = null, int $offset = 0, int $limit = self::DEFAULT_LIMIT): Result
	{
		$result = new Result();

		$query = trim($query);
		$length = mb_strlen($query);
		if ($length < self::MIN_QUERY_LENGTH)
		{
			return $result->addError(new SearchError(SearchError::QUERY_TOO_SHORT));
		}

		// the query is never truncated silently: a cut phrase would answer a question the user did not ask
		if ($length > self::MAX_QUERY_LENGTH)
		{
			return $result->addError(new SearchError(SearchError::QUERY_TOO_LONG));
		}

		$sortOrder = self::resolveSort($sort);
		if ($sortOrder === null)
		{
			return $result->addError(new SearchError(SearchError::WRONG_SORT));
		}

		return $result->setResult(new self($query, $sortOrder, max($offset, 0), self::normalizeLimit($limit)));
	}

	/**
	 * The default answers an absent parameter and never an unknown value: a silent fallback on a typo
	 * of the client would return an order other than the one the user picked, and nobody would know.
	 */
	private static function resolveSort(?string $sort): ?SortOrder
	{
		if ($sort === null || trim($sort) === '')
		{
			return SortOrder::Relevance;
		}

		return SortOrder::tryFrom(trim($sort));
	}

	private static function normalizeLimit(int $limit): int
	{
		if ($limit <= 0)
		{
			return self::DEFAULT_LIMIT;
		}

		return min($limit, self::MAX_LIMIT);
	}
}
