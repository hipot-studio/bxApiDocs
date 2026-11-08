<?php

namespace Bitrix\Crm\Service\EditorAdapter\Normalizer;

use Bitrix\Crm\Field;

final class FieldValueNormalizer extends Base
{
	private const DEFAULT_FLOAT_PRECISION = 6;

	/**
	 * The range the `PRECISION` setting of a double user field is limited to:
	 * the same limits the main.field.double component applies in formatValue()
	 * (@see \Bitrix\Main\UserField\Types\DoubleType).
	 */
	private const MIN_FLOAT_PRECISION = 0;
	private const MAX_FLOAT_PRECISION = 12;

	public function __construct(
		private readonly string $fieldType,
		private readonly ?int $floatPrecision = null,
	)
	{
	}

	public function normalize(mixed $value): mixed
	{
		if (is_array($value))
		{
			$result = [];
			foreach ($value as $singleValue)
			{
				$result[] = $this->prepareSingleValue($singleValue);
			}
		}
		else
		{
			$result = $this->prepareSingleValue($value);
		}

		return $result;
	}

	private function prepareSingleValue($value): mixed
	{
		if (is_float($value))
		{
			$value = $this->formatFloat($value);
		}
		elseif (is_numeric($value))
		{
			$value = (string)$value;
		}
		elseif (is_object($value) && method_exists($value, '__toString'))
		{
			$value = $value->__toString();
		}
		elseif ($this->fieldType === Field::TYPE_BOOLEAN)
		{
			if ($value !== 'Y' && $value !== 'N')
			{
				$value = $value ? 'Y' : 'N';
			}
		}
		elseif ($value === false)
		{
			$value = '';
		}

		return $value;
	}

	/**
	 * Rounds like round() does in main.field.double and DoubleType, then returns the shortest
	 * fixed-point notation that is read back as the same float, without binary noise and trailing zeroes.
	 */
	private function formatFloat(float $value): string
	{
		if (!is_finite($value))
		{
			return (string)$value;
		}

		// The caller is free to pass any number: a negative precision would make round() round
		// to tens and break sprintf() with a ValueError, and one above the PRECISION range of a
		// double user field would keep more decimals than the field itself ever shows.
		$precision = max(
			self::MIN_FLOAT_PRECISION,
			min(self::MAX_FLOAT_PRECISION, $this->floatPrecision ?? self::DEFAULT_FLOAT_PRECISION)
		);
		$value = round($value, $precision);
		if ($value == 0)
		{
			return '0';
		}

		for ($decimals = 0; $decimals < $precision; $decimals++)
		{
			$result = sprintf("%.{$decimals}F", $value);
			if ((float)$result === $value)
			{
				return $result;
			}
		}

		$result = sprintf("%.{$precision}F", $value);
		if (str_contains($result, '.'))
		{
			$result = rtrim(rtrim($result, '0'), '.');
		}

		return $result;
	}
}
