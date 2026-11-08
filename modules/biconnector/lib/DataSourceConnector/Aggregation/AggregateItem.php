<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * One aggregate of the block (design §2.2).
 *
 * AVG carries no wireType: it travels as the sum/count pair of design §3.2 and is finalized by the
 * plugin, so there is no single wire representation for it.
 */
final class AggregateItem
{
	public const COUNT = 'COUNT';
	public const SUM = 'SUM';
	public const AVG = 'AVG';
	public const MIN = 'MIN';
	public const MAX = 'MAX';

	public const FUNCTIONS = [self::COUNT, self::SUM, self::AVG, self::MIN, self::MAX];

	/** Wire suffixes of the two columns an AVG alias expands into. */
	public const SUM_SUFFIX = '__s';
	public const COUNT_SUFFIX = '__c';

	public function __construct(
		public readonly string $function,
		public readonly string $relation,
		public readonly ?string $field,
		public readonly bool $distinct,
		public readonly string $alias,
		public readonly TypeSpec $outputType,
		public readonly ?TypeSpec $wireType,
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
			throw AggregationException::badRequest('aggregates item must be an object');
		}

		$allowed = ['function', 'relation', 'field', 'distinct', 'alias', 'outputType', 'wireType'];
		$required = ['function', 'relation', 'field', 'distinct', 'alias', 'outputType'];
		foreach (array_keys($raw) as $key)
		{
			if (!in_array($key, $allowed, true))
			{
				throw AggregationException::badRequest("unexpected aggregates key {$key}");
			}
		}
		foreach ($required as $key)
		{
			if (!array_key_exists($key, $raw))
			{
				throw AggregationException::badRequest("aggregates.{$key} is required");
			}
		}

		$function = $raw['function'];
		if (!is_string($function) || !in_array($function, self::FUNCTIONS, true))
		{
			throw AggregationException::badRequest('unknown aggregates.function');
		}

		$relation = $raw['relation'];
		if (!is_string($relation))
		{
			throw AggregationException::badRequest('aggregates.relation must be a string');
		}

		$field = $raw['field'];
		if ($field !== null && (!is_string($field) || $field === ''))
		{
			throw AggregationException::badRequest('aggregates.field must be a non-empty string or null');
		}

		$distinct = $raw['distinct'];
		if (!is_bool($distinct))
		{
			throw AggregationException::badRequest('aggregates.distinct must be a boolean');
		}

		$alias = $raw['alias'];
		if (!is_string($alias) || !preg_match(AliasGenerator::PATTERN, $alias))
		{
			throw AggregationException::badRequest('aggregates.alias does not match the alias grammar');
		}

		$wireType = array_key_exists('wireType', $raw) ? TypeSpec::fromArray($raw['wireType']) : null;

		return new self(
			$function,
			$relation,
			$field,
			$distinct,
			$alias,
			TypeSpec::fromArray($raw['outputType']),
			$wireType,
		);
	}

	public function isAverage(): bool
	{
		return $this->function === self::AVG;
	}

	public function getSumAlias(): string
	{
		return $this->alias . self::SUM_SUFFIX;
	}

	public function getCountAlias(): string
	{
		return $this->alias . self::COUNT_SUFFIX;
	}
}
