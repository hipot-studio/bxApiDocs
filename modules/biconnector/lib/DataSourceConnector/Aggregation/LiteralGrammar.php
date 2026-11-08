<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * Grammar of request literals (design §2.6).
 *
 * The portal mirrors the rules of the producer: a request the plugin had no right to send is
 * rejected rather than interpreted.
 */
final class LiteralGrammar
{
	private const BIGINT = '/^-?(0|[1-9][0-9]*)$/';

	/** The form of Java `Double.toString`, finite values only. */
	private const DOUBLE = '/^-?(0|[1-9][0-9]*)\.[0-9]+(E-?[1-9][0-9]*)?$/';

	private const DATE = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/';
	private const TIMESTAMP = '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/';
	private const BOOLEAN = '/^(true|false)$/';

	private const INT64_MIN = '-9223372036854775808';
	private const INT64_MAX = '9223372036854775807';

	private function __construct()
	{
	}

	/**
	 * @throws AggregationException
	 */
	public static function assertValid(TypeSpec $type, string $value): void
	{
		match ($type->base)
		{
			TypeSpec::BIGINT => self::assertBigint($value),
			TypeSpec::DECIMAL => self::assertDecimal($type, $value),
			TypeSpec::DOUBLE => self::assertDouble($value),
			TypeSpec::DATE => self::assertDate($value),
			TypeSpec::TIMESTAMP => self::assertTimestamp($value),
			TypeSpec::BOOLEAN => self::assertBoolean($value),
			TypeSpec::VARCHAR => null,
			default => throw AggregationException::badRequest("no literal grammar for {$type}"),
		};
	}

	private static function assertBigint(string $value): void
	{
		if (!preg_match(self::BIGINT, $value))
		{
			throw AggregationException::badRequest("malformed BIGINT literal {$value}");
		}

		// Negative zero has no canonical form in the protocol.
		if ($value === '-0')
		{
			throw AggregationException::badRequest('negative zero is not a canonical BIGINT literal');
		}

		if (!self::fitsInt64($value))
		{
			throw AggregationException::badRequest("BIGINT literal {$value} is out of range");
		}
	}

	private static function assertDecimal(TypeSpec $type, string $value): void
	{
		$scale = $type->getScaleOrZero();
		if ($scale === 0)
		{
			if (!preg_match(self::BIGINT, $value))
			{
				throw AggregationException::badRequest("malformed DECIMAL literal {$value}");
			}
		}
		else
		{
			$pattern = '/^-?(0|[1-9][0-9]*)\.[0-9]{' . $scale . '}$/';
			if (!preg_match($pattern, $value))
			{
				throw AggregationException::badRequest("malformed DECIMAL({$type->precision},{$scale}) literal {$value}");
			}
		}

		$digits = ltrim(str_replace(['-', '.'], '', $value), '0');
		if (strlen($digits) > (int)$type->precision)
		{
			throw AggregationException::badRequest("DECIMAL literal {$value} exceeds precision {$type->precision}");
		}

		// Negative zero has no canonical form in the protocol.
		if ($value[0] === '-' && $digits === '')
		{
			throw AggregationException::badRequest('negative zero is not a canonical DECIMAL literal');
		}
	}

	private static function assertDouble(string $value): void
	{
		if (!preg_match(self::DOUBLE, $value))
		{
			throw AggregationException::badRequest("malformed DOUBLE literal {$value}");
		}

		if (!is_finite((float)$value))
		{
			throw AggregationException::badRequest("DOUBLE literal {$value} is not finite");
		}
	}

	private static function assertDate(string $value): void
	{
		if (!preg_match(self::DATE, $value) || !self::isCalendarDate(substr($value, 0, 10)))
		{
			throw AggregationException::badRequest("malformed DATE literal {$value}");
		}
	}

	private static function assertTimestamp(string $value): void
	{
		if (!preg_match(self::TIMESTAMP, $value) || !self::isCalendarDate(substr($value, 0, 10)))
		{
			throw AggregationException::badRequest("malformed TIMESTAMP literal {$value}");
		}

		$hour = (int)substr($value, 11, 2);
		$minute = (int)substr($value, 14, 2);
		$second = (int)substr($value, 17, 2);
		if ($hour > 23 || $minute > 59 || $second > 59)
		{
			throw AggregationException::badRequest("TIMESTAMP literal {$value} is not a valid time");
		}
	}

	private static function assertBoolean(string $value): void
	{
		if (!preg_match(self::BOOLEAN, $value))
		{
			throw AggregationException::badRequest("malformed BOOLEAN literal {$value}");
		}
	}

	private static function isCalendarDate(string $date): bool
	{
		[$year, $month, $day] = array_map('intval', explode('-', $date));

		return $month >= 1 && $month <= 12 && checkdate($month, $day, $year);
	}

	private static function fitsInt64(string $value): bool
	{
		$negative = $value[0] === '-';
		$bound = $negative ? self::INT64_MIN : self::INT64_MAX;

		if (strlen($value) !== strlen($bound))
		{
			return strlen($value) < strlen($bound);
		}

		return $negative ? strcmp($value, $bound) <= 0 : strcmp($value, $bound) <= 0;
	}
}
