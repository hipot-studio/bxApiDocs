<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest;

use Bitrix\Main\ArgumentException;

/**
 * The period value a digest agent stores: either a code of {@see DigestPeriod}, which stays floating
 * and is resolved into dates on every run, or a fixed range `range:YYYY-MM-DD:YYYY-MM-DD` with
 * inclusive bounds, which is pinned as it is. The range comes either from the report screen or from the
 * dates a user sets in the selector footer; either way it is a form of the stored value only and never
 * becomes an entry of the manual list.
 */
final class DigestPeriodSelection
{
	private const RANGE_PREFIX = 'range:';
	private const RANGE_PATTERN = '/^range:(\d{4}-\d{2}-\d{2}):(\d{4}-\d{2}-\d{2})$/';
	private const DATE_FORMAT = 'Y-m-d';

	private function __construct(
		private readonly ?DigestPeriod $period,
		private readonly ?string $from = null,
		private readonly ?string $to = null,
	)
	{
	}

	/**
	 * A legacy rolling code is read as the entry it was moved to ({@see DigestPeriod::tryFromStored()}),
	 * so it is stored back under the new code.
	 *
	 * @return self|null Null for a value that is neither a known code nor a well-formed range.
	 */
	public static function fromStored(string $value): ?self
	{
		$period = DigestPeriod::tryFromStored($value);
		if ($period !== null)
		{
			return new self($period);
		}

		if (!preg_match(self::RANGE_PATTERN, $value, $matches))
		{
			return null;
		}

		return self::isValidRange($matches[1], $matches[2]) ? new self(null, $matches[1], $matches[2]) : null;
	}

	/**
	 * @param array{from: string, to: string} $bounds Inclusive bounds, ISO-8601 dates.
	 *
	 * @throws ArgumentException
	 */
	public static function fromBounds(array $bounds): self
	{
		$from = $bounds['from'] ?? null;
		$to = $bounds['to'] ?? null;
		if (!is_string($from) || !is_string($to) || !self::isValidRange($from, $to))
		{
			throw new ArgumentException('Period bounds must be two ISO-8601 dates, the start not after the end.');
		}

		return new self(null, $from, $to);
	}

	/**
	 * Tells a value meant as a fixed range, well-formed or not, from a period code.
	 */
	public static function isRangeForm(string $value): bool
	{
		return str_starts_with($value, self::RANGE_PREFIX);
	}

	public function isFixedRange(): bool
	{
		return $this->period === null;
	}

	/**
	 * The value the run resolves the period by: the filter code of a floating period (empty when the
	 * report decides), or the stored range itself, whose bounds are read back by {@see self::toBounds()}.
	 */
	public function toFilterPeriod(): string
	{
		return $this->period?->toFilterPeriod() ?? $this->toStored();
	}

	/**
	 * @return array{from: string, to: string}|null Bounds of a fixed range; null for a floating period.
	 */
	public function toBounds(): ?array
	{
		if ($this->period !== null)
		{
			return null;
		}

		return ['from' => $this->from, 'to' => $this->to];
	}

	public function toStored(): string
	{
		return $this->period?->value ?? self::RANGE_PREFIX . $this->from . ':' . $this->to;
	}

	private static function isValidRange(string $from, string $to): bool
	{
		return self::isValidDate($from) && self::isValidDate($to) && $from <= $to;
	}

	private static function isValidDate(string $date): bool
	{
		$parsed = \DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $date);

		return $parsed !== false && $parsed->format(self::DATE_FORMAT) === $date;
	}
}
