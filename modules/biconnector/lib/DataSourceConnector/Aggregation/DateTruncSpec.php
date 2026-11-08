<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * Time granulation transform of a group key (design §2.2).
 *
 * The four parameters cover all 19 forms of the Superset `_time_grain_expressions` dictionary:
 * `unit` with `bucket` reproduces the modulo buckets, `preOffsetDays`/`postOffsetDays` reproduce the
 * week-shifted forms.
 */
final class DateTruncSpec
{
	public const KIND = 'DATE_TRUNC';

	public const UNIT_SECOND = 'SECOND';
	public const UNIT_MINUTE = 'MINUTE';
	public const UNIT_HOUR = 'HOUR';
	public const UNIT_DAY = 'DAY';
	public const UNIT_WEEK = 'WEEK';
	public const UNIT_MONTH = 'MONTH';
	public const UNIT_QUARTER = 'QUARTER';
	public const UNIT_YEAR = 'YEAR';

	public const UNITS = [
		self::UNIT_SECOND,
		self::UNIT_MINUTE,
		self::UNIT_HOUR,
		self::UNIT_DAY,
		self::UNIT_WEEK,
		self::UNIT_MONTH,
		self::UNIT_QUARTER,
		self::UNIT_YEAR,
	];

	/** Length of the parent unit a bucket must divide. */
	public const BUCKET_DIVISORS = [
		self::UNIT_SECOND => 60,
		self::UNIT_MINUTE => 60,
		self::UNIT_HOUR => 24,
	];

	private const MIN_BUCKET = 1;
	private const MAX_BUCKET = 60;
	private const MAX_OFFSET_DAYS = 7;

	public function __construct(
		public readonly string $unit,
		public readonly int $bucket,
		public readonly int $preOffsetDays,
		public readonly int $postOffsetDays,
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
			throw AggregationException::badRequest('transform must be an object');
		}

		$allowed = ['kind', 'unit', 'bucket', 'preOffsetDays', 'postOffsetDays'];
		foreach (array_keys($raw) as $key)
		{
			if (!in_array($key, $allowed, true))
			{
				throw AggregationException::badRequest("unexpected transform key {$key}");
			}
		}
		foreach ($allowed as $key)
		{
			if (!array_key_exists($key, $raw))
			{
				throw AggregationException::badRequest("transform.{$key} is required");
			}
		}

		if ($raw['kind'] !== self::KIND)
		{
			throw AggregationException::badRequest('transform.kind must be DATE_TRUNC');
		}

		$unit = $raw['unit'];
		if (!is_string($unit) || !in_array($unit, self::UNITS, true))
		{
			throw AggregationException::badRequest('unknown transform.unit');
		}

		$bucket = $raw['bucket'];
		if (!is_int($bucket) || $bucket < self::MIN_BUCKET || $bucket > self::MAX_BUCKET)
		{
			throw AggregationException::badRequest('transform.bucket is out of range');
		}

		$pre = self::offsetValue($raw, 'preOffsetDays');
		$post = self::offsetValue($raw, 'postOffsetDays');

		return new self($unit, $bucket, $pre, $post);
	}

	public function toArray(): array
	{
		return [
			'kind' => self::KIND,
			'unit' => $this->unit,
			'bucket' => $this->bucket,
			'preOffsetDays' => $this->preOffsetDays,
			'postOffsetDays' => $this->postOffsetDays,
		];
	}

	public function hasBucket(): bool
	{
		return $this->bucket > 1;
	}

	public function hasOffsets(): bool
	{
		return $this->preOffsetDays !== 0 || $this->postOffsetDays !== 0;
	}

	private static function offsetValue(array $raw, string $key): int
	{
		$value = $raw[$key];
		if (!is_int($value) || $value < -self::MAX_OFFSET_DAYS || $value > self::MAX_OFFSET_DAYS)
		{
			throw AggregationException::badRequest("transform.{$key} is out of range");
		}

		return $value;
	}
}
