<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * The `aggregation` block of a data request (design §2.2).
 *
 * This class covers the structural part of the contract - the JSON Schema of design §2.6, with
 * `additionalProperties: false` inside the block. Everything that the schema cannot express (the
 * type matrix, alias recomputation, bucket and offset rules, literal grammars, dataset gates) is
 * the job of the validator.
 */
final class Request
{
	public const SUPPORTED_VERSIONS = [1];

	private const TOP_LEVEL_KEYS = [
		'version',
		'from',
		'groupBy',
		'aggregates',
		'having',
		'orderBy',
		'groupLimit',
	];

	private const MAX_GROUP_LIMIT = 2147483647;

	/**
	 * @param GroupItem[] $groupBy
	 * @param AggregateItem[] $aggregates
	 * @param OrderItem[] $orderBy
	 * @param array $rawBlock The block exactly as received; the response fingerprint is computed over it.
	 */
	private function __construct(
		public readonly int $version,
		public readonly string $table,
		public readonly string $relation,
		public readonly array $groupBy,
		public readonly array $aggregates,
		public readonly ?HavingNode $having,
		public readonly array $orderBy,
		public readonly ?int $groupLimit,
		private readonly array $rawBlock,
	)
	{
	}

	/**
	 * @throws AggregationException
	 */
	public static function fromArray(mixed $raw): self
	{
		if (!is_array($raw) || array_is_list($raw))
		{
			throw AggregationException::badRequest('aggregation must be an object');
		}

		foreach (array_keys($raw) as $key)
		{
			if (!in_array($key, self::TOP_LEVEL_KEYS, true))
			{
				throw AggregationException::badRequest("unexpected aggregation key {$key}");
			}
		}
		foreach (self::TOP_LEVEL_KEYS as $key)
		{
			if (!array_key_exists($key, $raw))
			{
				throw AggregationException::badRequest("aggregation.{$key} is required");
			}
		}

		$version = $raw['version'];
		if (!is_int($version) || $version < 1)
		{
			throw AggregationException::badRequest('aggregation.version must be a positive integer');
		}
		if (!in_array($version, self::SUPPORTED_VERSIONS, true))
		{
			throw new AggregationException(ErrorCode::UNSUPPORTED_VERSION, "version {$version}");
		}

		[$table, $relation] = self::parseFrom($raw['from']);

		$groupBy = [];
		foreach (self::asList($raw['groupBy'], 'groupBy') as $item)
		{
			$groupBy[] = GroupItem::fromArray($item);
		}

		$aggregates = [];
		foreach (self::asList($raw['aggregates'], 'aggregates') as $item)
		{
			$aggregates[] = AggregateItem::fromArray($item);
		}

		$orderBy = [];
		foreach (self::asList($raw['orderBy'], 'orderBy') as $item)
		{
			$orderBy[] = OrderItem::fromArray($item);
		}

		$having = $raw['having'] === null ? null : HavingNode::fromArray($raw['having']);

		return new self(
			$version,
			$table,
			$relation,
			$groupBy,
			$aggregates,
			$having,
			$orderBy,
			self::parseGroupLimit($raw['groupLimit']),
			$raw,
		);
	}

	/**
	 * SHA-256 of the canonical form of the received block (design §2.3).
	 */
	public function getFingerprint(): string
	{
		return CanonicalJson::fingerprint($this->rawBlock);
	}

	public function getRawBlock(): array
	{
		return $this->rawBlock;
	}

	/**
	 * Wire column names in response order: group keys, then aggregates with AVG expanded into a pair.
	 *
	 * @return string[]
	 */
	public function getResponseAliases(): array
	{
		$aliases = [];
		foreach ($this->groupBy as $item)
		{
			$aliases[] = $item->alias;
		}
		foreach ($this->aggregates as $item)
		{
			if ($item->isAverage())
			{
				$aliases[] = $item->getSumAlias();
				$aliases[] = $item->getCountAlias();
			}
			else
			{
				$aliases[] = $item->alias;
			}
		}

		return $aliases;
	}

	/**
	 * @return array<string, GroupItem|AggregateItem>
	 */
	public function getItemsByAlias(): array
	{
		$items = [];
		foreach ($this->groupBy as $item)
		{
			$items[$item->alias] = $item;
		}
		foreach ($this->aggregates as $item)
		{
			$items[$item->alias] = $item;
		}

		return $items;
	}

	private static function parseFrom(mixed $from): array
	{
		if (!is_array($from) || !array_is_list($from) || count($from) !== 1)
		{
			throw AggregationException::badRequest('aggregation.from must hold exactly one relation');
		}

		$relation = $from[0];
		if (!is_array($relation))
		{
			throw AggregationException::badRequest('aggregation.from item must be an object');
		}

		foreach (array_keys($relation) as $key)
		{
			if ($key !== 'table' && $key !== 'alias')
			{
				throw AggregationException::badRequest("unexpected from key {$key}");
			}
		}

		$table = $relation['table'] ?? null;
		if (!is_string($table) || $table === '')
		{
			throw AggregationException::badRequest('from.table must be a non-empty string');
		}

		$alias = $relation['alias'] ?? null;
		if (!is_string($alias) || !preg_match('/^t[0-9]+$/', $alias))
		{
			throw AggregationException::badRequest('from.alias must match ^t[0-9]+$');
		}

		return [$table, $alias];
	}

	private static function parseGroupLimit(mixed $groupLimit): ?int
	{
		if ($groupLimit === null)
		{
			return null;
		}

		if (!is_int($groupLimit) || $groupLimit < 1 || $groupLimit > self::MAX_GROUP_LIMIT)
		{
			throw AggregationException::badRequest('aggregation.groupLimit is out of range');
		}

		return $groupLimit;
	}

	private static function asList(mixed $value, string $name): array
	{
		if (!is_array($value) || !array_is_list($value))
		{
			throw AggregationException::badRequest("aggregation.{$name} must be an array");
		}

		return $value;
	}
}
