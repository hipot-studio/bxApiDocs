<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * Structured protocol type: {"base", "precision", "scale"} (design §2.6, $defs/typeSpec).
 */
final class TypeSpec
{
	public const BIGINT = 'BIGINT';
	public const DOUBLE = 'DOUBLE';
	public const DECIMAL = 'DECIMAL';
	public const DATE = 'DATE';
	public const TIMESTAMP = 'TIMESTAMP';
	public const VARCHAR = 'VARCHAR';
	public const BOOLEAN = 'BOOLEAN';

	private const SIMPLE_BASES = [self::BIGINT, self::DOUBLE, self::DATE, self::VARCHAR, self::BOOLEAN];

	/** Exactly the pairs the matrix of design §3 produces. */
	private const DECIMAL_PAIRS = [[19, 4], [38, 4], [38, 0]];

	private const MAX_TIMESTAMP_PRECISION = 6;

	private function __construct(
		public readonly string $base,
		public readonly ?int $precision,
		public readonly ?int $scale,
	)
	{
	}

	public static function simple(string $base): self
	{
		if (!in_array($base, self::SIMPLE_BASES, true))
		{
			throw AggregationException::badRequest("type base {$base} is not a simple base");
		}

		return new self($base, null, null);
	}

	public static function timestamp(int $precision): self
	{
		if ($precision < 0 || $precision > self::MAX_TIMESTAMP_PRECISION)
		{
			throw AggregationException::badRequest("TIMESTAMP precision {$precision} is out of range");
		}

		return new self(self::TIMESTAMP, $precision, null);
	}

	public static function decimal(int $precision, int $scale): self
	{
		foreach (self::DECIMAL_PAIRS as [$allowedPrecision, $allowedScale])
		{
			if ($precision === $allowedPrecision && $scale === $allowedScale)
			{
				return new self(self::DECIMAL, $precision, $scale);
			}
		}

		throw AggregationException::badRequest("DECIMAL({$precision},{$scale}) is not in the type matrix");
	}

	/**
	 * @param mixed $raw Decoded JSON value.
	 *
	 * @throws AggregationException
	 */
	public static function fromArray(mixed $raw): self
	{
		if (!is_array($raw))
		{
			throw AggregationException::badRequest('typeSpec must be an object');
		}

		$base = $raw['base'] ?? null;
		if (!is_string($base))
		{
			throw AggregationException::badRequest('typeSpec.base must be a string');
		}

		$known = array_merge(self::SIMPLE_BASES, [self::TIMESTAMP, self::DECIMAL]);
		if (!in_array($base, $known, true))
		{
			throw AggregationException::badRequest("unknown typeSpec.base {$base}");
		}

		$allowedKeys = match ($base)
		{
			self::TIMESTAMP => ['base', 'precision'],
			self::DECIMAL => ['base', 'precision', 'scale'],
			default => ['base'],
		};
		self::assertNoExtraKeys($raw, $allowedKeys);

		if ($base === self::TIMESTAMP)
		{
			return self::timestamp(self::intValue($raw, 'precision'));
		}

		if ($base === self::DECIMAL)
		{
			return self::decimal(self::intValue($raw, 'precision'), self::intValue($raw, 'scale'));
		}

		return self::simple($base);
	}

	public function toArray(): array
	{
		$result = ['base' => $this->base];
		if ($this->precision !== null)
		{
			$result['precision'] = $this->precision;
		}
		if ($this->scale !== null)
		{
			$result['scale'] = $this->scale;
		}

		return $result;
	}

	public function equals(self $other): bool
	{
		return $this->base === $other->base
			&& $this->precision === $other->precision
			&& $this->scale === $other->scale
		;
	}

	public function isDouble(): bool
	{
		return $this->base === self::DOUBLE;
	}

	public function isDecimal(): bool
	{
		return $this->base === self::DECIMAL;
	}

	public function isTemporal(): bool
	{
		return $this->base === self::DATE || $this->base === self::TIMESTAMP;
	}

	public function isTextual(): bool
	{
		return $this->base === self::VARCHAR;
	}

	public function getScaleOrZero(): int
	{
		return $this->scale ?? 0;
	}

	public function __toString(): string
	{
		if ($this->base === self::DECIMAL)
		{
			return "DECIMAL({$this->precision},{$this->scale})";
		}

		if ($this->base === self::TIMESTAMP)
		{
			return "TIMESTAMP({$this->precision})";
		}

		return $this->base;
	}

	private static function assertNoExtraKeys(array $raw, array $allowed): void
	{
		foreach (array_keys($raw) as $key)
		{
			if (!in_array($key, $allowed, true))
			{
				throw AggregationException::badRequest("unexpected typeSpec key {$key}");
			}
		}

		foreach ($allowed as $key)
		{
			if (!array_key_exists($key, $raw))
			{
				throw AggregationException::badRequest("typeSpec.{$key} is required");
			}
		}
	}

	private static function intValue(array $raw, string $key): int
	{
		$value = $raw[$key] ?? null;
		if (!is_int($value))
		{
			throw AggregationException::badRequest("typeSpec.{$key} must be an integer");
		}

		return $value;
	}
}
