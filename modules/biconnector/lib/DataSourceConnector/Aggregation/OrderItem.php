<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * One ORDER BY item over an alias of the block (design §2.2).
 */
final class OrderItem
{
	public function __construct(
		public readonly string $target,
		public readonly bool $ascending,
		public readonly bool $nullsFirst,
	)
	{
	}

	/**
	 * @throws AggregationException
	 */
	public static function fromArray(mixed $raw): self
	{
		if (!is_array($raw))
		{
			throw AggregationException::badRequest('orderBy item must be an object');
		}

		$allowed = ['target', 'direction', 'nulls'];
		foreach (array_keys($raw) as $key)
		{
			if (!in_array($key, $allowed, true))
			{
				throw AggregationException::badRequest("unexpected orderBy key {$key}");
			}
		}
		foreach ($allowed as $key)
		{
			if (!array_key_exists($key, $raw))
			{
				throw AggregationException::badRequest("orderBy.{$key} is required");
			}
		}

		$target = $raw['target'];
		if (!is_string($target) || !preg_match(AliasGenerator::PATTERN, $target))
		{
			throw AggregationException::badRequest('orderBy.target must be an alias');
		}

		$direction = $raw['direction'];
		if ($direction !== 'ASC' && $direction !== 'DESC')
		{
			throw AggregationException::badRequest('orderBy.direction must be ASC or DESC');
		}

		$nulls = $raw['nulls'];
		if ($nulls !== 'FIRST' && $nulls !== 'LAST')
		{
			throw AggregationException::badRequest('orderBy.nulls must be FIRST or LAST');
		}

		return new self($target, $direction === 'ASC', $nulls === 'FIRST');
	}
}
