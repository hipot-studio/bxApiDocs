<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * Per-column serializer of aggregated values, keyed by wire type (design §6.2).
 *
 * It replaces the dataset callbacks of the raw path: the `(int)` cast would destroy wide sums and
 * a CALLBACK would destroy canonical strings.
 *
 * The obligation for a finite DOUBLE is the value, not the lexeme: the forms of PHP `json_encode`
 * and Java `Double.toString` differ systematically, so the portal only guarantees a JSON number
 * with a full-precision representation. Non-finite values travel as the normative tokens.
 */
final class ValueSerializer
{
	public const TOKEN_NAN = 'NaN';
	public const TOKEN_POSITIVE_INFINITY = 'Infinity';
	public const TOKEN_NEGATIVE_INFINITY = '-Infinity';

	/** A finite decimal number, optionally in exponential form. Nothing else is a DOUBLE value. */
	private const NUMERIC_LEXEME = '/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/';

	private const NAN_SPELLINGS = ['nan', '-nan', '+nan'];
	private const POSITIVE_INFINITY_SPELLINGS = ['inf', '+inf', 'infinity', '+infinity'];
	private const NEGATIVE_INFINITY_SPELLINGS = ['-inf', '-infinity'];

	private function __construct()
	{
	}

	/**
	 * Callbacks indexed by column position, in the response column order.
	 *
	 * @return array<int, callable>
	 */
	public static function buildCallbacks(Request $request): array
	{
		$callbacks = [];
		$position = 0;

		foreach ($request->groupBy as $item)
		{
			$callbacks[$position++] = self::forType($item->outputType);
		}

		foreach ($request->aggregates as $item)
		{
			if ($item->isAverage())
			{
				// The AVG output type determines the sum type unambiguously: an exact decimal average
				// comes from an exact decimal sum, a double average from a double one.
				$sumType = $item->outputType->isDecimal()
					? TypeSpec::decimal(38, 4)
					: TypeSpec::simple(TypeSpec::DOUBLE)
				;

				$callbacks[$position++] = self::forType($sumType);
				$callbacks[$position++] = self::forType(TypeSpec::simple(TypeSpec::BIGINT));

				continue;
			}

			$callbacks[$position++] = self::forType($item->wireType ?? $item->outputType);
		}

		return $callbacks;
	}

	public static function forType(TypeSpec $type): callable
	{
		return match ($type->base)
		{
			TypeSpec::DOUBLE => static fn ($value) => self::serializeDouble($value),
			TypeSpec::BIGINT => static fn ($value) => self::serializeBigint($value),
			TypeSpec::DECIMAL => static fn ($value) => self::serializeDecimal($value, $type->getScaleOrZero()),
			TypeSpec::BOOLEAN => static fn ($value) => self::serializeBoolean($value),
			default => static fn ($value) => $value === null ? null : (string)$value,
		};
	}

	/**
	 * The HTTP path of ClickHouse returns numbers as strings, so the value is turned into a PHP
	 * float and Json::encode prints a JSON number rather than a quoted string.
	 */
	private static function serializeDouble(mixed $value): float|string|null
	{
		if ($value === null)
		{
			return null;
		}

		if (is_float($value) || is_int($value))
		{
			return self::classifyDouble((float)$value);
		}

		if (!is_string($value))
		{
			throw AggregationException::badRequest('unexpected DOUBLE value of type ' . get_debug_type($value));
		}

		$text = trim($value);

		$token = self::nonFiniteToken($text);
		if ($token !== null)
		{
			return $token;
		}

		if (!preg_match(self::NUMERIC_LEXEME, $text))
		{
			throw AggregationException::badRequest("malformed DOUBLE value {$value}");
		}

		$number = (float)$text;

		// A finite lexeme whose value overflows is corrupted data, not a source reporting infinity:
		// a genuinely non-finite value arrives as one of the normative tokens.
		if (!is_finite($number))
		{
			throw AggregationException::badRequest("DOUBLE value {$value} is out of range");
		}

		return $number;
	}

	private static function classifyDouble(float $number): float|string
	{
		if (is_nan($number))
		{
			return self::TOKEN_NAN;
		}

		if (is_infinite($number))
		{
			return $number > 0 ? self::TOKEN_POSITIVE_INFINITY : self::TOKEN_NEGATIVE_INFINITY;
		}

		return $number;
	}

	private static function serializeBigint(mixed $value): int|string|null
	{
		if ($value === null)
		{
			return null;
		}

		$text = trim((string)$value);
		if ($text === '')
		{
			return null;
		}

		// Values beyond the PHP integer range stay decimal strings instead of losing precision.
		if (preg_match('/^-?[0-9]+$/', $text) && $text === (string)(int)$text)
		{
			return (int)$text;
		}

		return $text;
	}

	/**
	 * Wide sums and decimals travel as canonical decimal strings: turning them into a PHP number
	 * would silently round them.
	 */
	private static function serializeDecimal(mixed $value, int $scale): ?string
	{
		if ($value === null)
		{
			return null;
		}

		$text = trim((string)$value);
		if ($text === '')
		{
			return null;
		}

		if (!preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]*))?$/', $text, $matches))
		{
			return $text;
		}

		$sign = $matches[1] === '-' ? '-' : '';
		$integer = ltrim($matches[2], '0');
		if ($integer === '')
		{
			$integer = '0';
		}
		$fraction = $matches[3] ?? '';

		if (strlen($fraction) < $scale)
		{
			$fraction = str_pad($fraction, $scale, '0');
		}
		elseif (strlen($fraction) > $scale)
		{
			$extra = substr($fraction, $scale);
			// Dropping a non-zero digit would be a silent rounding, so the value is left untouched
			// and the strict parser of the plugin reports the mismatch.
			if (trim($extra, '0') !== '')
			{
				return $text;
			}
			$fraction = substr($fraction, 0, $scale);
		}

		if ($sign === '-' && $integer === '0' && trim($fraction, '0') === '')
		{
			$sign = '';
		}

		return $scale === 0 ? $sign . $integer : $sign . $integer . '.' . $fraction;
	}

	private static function serializeBoolean(mixed $value): ?bool
	{
		if ($value === null)
		{
			return null;
		}

		if (is_bool($value))
		{
			return $value;
		}

		$text = mb_strtolower(trim((string)$value));

		return match ($text)
		{
			'true', '1', 'y', 't' => true,
			'false', '0', 'n', 'f' => false,
			'' => null,
			default => throw AggregationException::badRequest("unexpected boolean value {$text}"),
		};
	}

	private static function nonFiniteToken(string $value): ?string
	{
		$normalized = mb_strtolower($value);

		if (in_array($normalized, self::NAN_SPELLINGS, true))
		{
			return self::TOKEN_NAN;
		}

		if (in_array($normalized, self::POSITIVE_INFINITY_SPELLINGS, true))
		{
			return self::TOKEN_POSITIVE_INFINITY;
		}

		if (in_array($normalized, self::NEGATIVE_INFINITY_SPELLINGS, true))
		{
			return self::TOKEN_NEGATIVE_INFINITY;
		}

		return null;
	}
}
