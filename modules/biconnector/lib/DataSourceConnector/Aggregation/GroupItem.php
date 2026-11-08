<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * One grouping key of the block (design §2.2).
 */
final class GroupItem
{
	public function __construct(
		public readonly string $relation,
		public readonly string $field,
		public readonly ?DateTruncSpec $transform,
		public readonly string $alias,
		public readonly TypeSpec $outputType,
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
			throw AggregationException::badRequest('groupBy item must be an object');
		}

		$allowed = ['relation', 'field', 'transform', 'alias', 'outputType'];
		foreach (array_keys($raw) as $key)
		{
			if (!in_array($key, $allowed, true))
			{
				throw AggregationException::badRequest("unexpected groupBy key {$key}");
			}
		}
		foreach ($allowed as $key)
		{
			if (!array_key_exists($key, $raw))
			{
				throw AggregationException::badRequest("groupBy.{$key} is required");
			}
		}

		$relation = $raw['relation'];
		if (!is_string($relation))
		{
			throw AggregationException::badRequest('groupBy.relation must be a string');
		}

		$field = $raw['field'];
		if (!is_string($field) || $field === '')
		{
			throw AggregationException::badRequest('groupBy.field must be a non-empty string');
		}

		$alias = $raw['alias'];
		if (!is_string($alias) || !preg_match(AliasGenerator::PATTERN, $alias))
		{
			throw AggregationException::badRequest('groupBy.alias does not match the alias grammar');
		}

		$transform = $raw['transform'] === null ? null : DateTruncSpec::fromArray($raw['transform']);

		return new self($relation, $field, $transform, $alias, TypeSpec::fromArray($raw['outputType']));
	}
}
