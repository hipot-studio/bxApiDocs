<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * Node of the HAVING expression tree (design §2.2, grammar §2.6).
 *
 * The semantics is SQL three-valued logic, which coincides with Trino for the whole dictionary.
 */
final class HavingNode
{
	public const OP_AND = 'and';
	public const OP_OR = 'or';
	public const OP_NOT = 'not';
	public const OP_EQ = 'eq';
	public const OP_NEQ = 'neq';
	public const OP_LT = 'lt';
	public const OP_LE = 'le';
	public const OP_GT = 'gt';
	public const OP_GE = 'ge';
	public const OP_IN = 'in';
	public const OP_BETWEEN = 'between';
	public const OP_ISNULL = 'isnull';

	public const COMPARISON_OPS = [self::OP_EQ, self::OP_NEQ, self::OP_LT, self::OP_LE, self::OP_GT, self::OP_GE];

	/**
	 * @param HavingNode[] $args
	 * @param Literal[] $values
	 */
	private function __construct(
		public readonly string $op,
		public readonly array $args = [],
		public readonly ?HavingNode $arg = null,
		public readonly ?string $ref = null,
		public readonly ?Literal $value = null,
		public readonly array $values = [],
		public readonly ?Literal $low = null,
		public readonly ?Literal $high = null,
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
			throw AggregationException::badRequest('having node must be an object');
		}

		$op = $raw['op'] ?? null;
		if (!is_string($op))
		{
			throw AggregationException::badRequest('having.op must be a string');
		}

		return match ($op)
		{
			self::OP_AND, self::OP_OR => self::parseJunction($op, $raw),
			self::OP_NOT => self::parseNegation($raw),
			self::OP_EQ, self::OP_NEQ, self::OP_LT, self::OP_LE, self::OP_GT, self::OP_GE
				=> self::parseComparison($op, $raw),
			self::OP_IN => self::parseIn($raw),
			self::OP_BETWEEN => self::parseBetween($raw),
			self::OP_ISNULL => self::parseIsNull($raw),
			default => throw AggregationException::badRequest("unknown having.op {$op}"),
		};
	}

	/**
	 * Every literal of the subtree, in traversal order.
	 *
	 * @return Literal[]
	 */
	public function collectLiterals(): array
	{
		$literals = [];
		if ($this->value !== null)
		{
			$literals[] = $this->value;
		}
		foreach ($this->values as $literal)
		{
			$literals[] = $literal;
		}
		if ($this->low !== null)
		{
			$literals[] = $this->low;
		}
		if ($this->high !== null)
		{
			$literals[] = $this->high;
		}
		foreach ($this->args as $child)
		{
			$literals = array_merge($literals, $child->collectLiterals());
		}
		if ($this->arg !== null)
		{
			$literals = array_merge($literals, $this->arg->collectLiterals());
		}

		return $literals;
	}

	/**
	 * Every alias referenced by the subtree.
	 *
	 * @return string[]
	 */
	public function collectReferences(): array
	{
		$references = $this->ref === null ? [] : [$this->ref];
		foreach ($this->args as $child)
		{
			$references = array_merge($references, $child->collectReferences());
		}
		if ($this->arg !== null)
		{
			$references = array_merge($references, $this->arg->collectReferences());
		}

		return $references;
	}

	private static function parseJunction(string $op, array $raw): self
	{
		self::assertKeys($raw, ['op', 'args']);

		$args = $raw['args'];
		if (!is_array($args) || !array_is_list($args) || count($args) < 2)
		{
			throw AggregationException::badRequest("having.{$op} requires at least two arguments");
		}

		$children = [];
		foreach ($args as $child)
		{
			$children[] = self::fromArray($child);
		}

		return new self($op, args: $children);
	}

	private static function parseNegation(array $raw): self
	{
		self::assertKeys($raw, ['op', 'arg']);

		return new self(self::OP_NOT, arg: self::fromArray($raw['arg']));
	}

	private static function parseComparison(string $op, array $raw): self
	{
		self::assertKeys($raw, ['op', 'ref', 'value']);

		return new self($op, ref: self::reference($raw), value: Literal::fromArray($raw['value']));
	}

	private static function parseIn(array $raw): self
	{
		self::assertKeys($raw, ['op', 'ref', 'values']);

		$values = $raw['values'];
		if (!is_array($values) || !array_is_list($values) || $values === [])
		{
			throw AggregationException::badRequest('having.in requires at least one value');
		}

		$literals = [];
		foreach ($values as $value)
		{
			$literals[] = Literal::fromArray($value);
		}

		return new self(self::OP_IN, ref: self::reference($raw), values: $literals);
	}

	private static function parseBetween(array $raw): self
	{
		self::assertKeys($raw, ['op', 'ref', 'low', 'high']);

		return new self(
			self::OP_BETWEEN,
			ref: self::reference($raw),
			low: Literal::fromArray($raw['low']),
			high: Literal::fromArray($raw['high']),
		);
	}

	private static function parseIsNull(array $raw): self
	{
		self::assertKeys($raw, ['op', 'ref']);

		return new self(self::OP_ISNULL, ref: self::reference($raw));
	}

	private static function reference(array $raw): string
	{
		$ref = $raw['ref'];
		if (!is_string($ref) || !preg_match(AliasGenerator::PATTERN, $ref))
		{
			throw AggregationException::badRequest('having.ref must be an alias');
		}

		return $ref;
	}

	private static function assertKeys(array $raw, array $allowed): void
	{
		foreach (array_keys($raw) as $key)
		{
			if (!in_array($key, $allowed, true))
			{
				throw AggregationException::badRequest("unexpected having key {$key}");
			}
		}
		foreach ($allowed as $key)
		{
			if (!array_key_exists($key, $raw))
			{
				throw AggregationException::badRequest("having.{$key} is required");
			}
		}
	}
}
