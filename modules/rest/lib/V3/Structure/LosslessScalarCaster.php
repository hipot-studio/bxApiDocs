<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\Structure;

/**
 * Casts a scalar to the target type only when the value keeps its meaning both ways.
 * Anything else is returned as is, so the strict type check still decides about it.
 */
final class LosslessScalarCaster
{
	private const INT_PATTERN = '/^-?(0|[1-9]\d*)$/D';
	private const JSON_NUMBER_PATTERN = '/^-?(0|[1-9]\d*)(\.\d+)?([eE][+-]?\d+)?$/D';

	public static function cast(string $type, mixed $value): mixed
	{
		return match ($type)
		{
			'string' => is_int($value) ? (string)$value : $value,
			'int' => self::castStringToInt($value),
			'float' => self::castStringToFloat($value),
			default => $value,
		};
	}

	private static function castStringToInt(mixed $value): mixed
	{
		if (!is_string($value) || preg_match(self::INT_PATTERN, $value) !== 1)
		{
			return $value;
		}

		// rejects "-0" and values that overflow int
		return (string)(int)$value === $value ? (int)$value : $value;
	}

	private static function castStringToFloat(mixed $value): mixed
	{
		if (!is_string($value) || preg_match(self::JSON_NUMBER_PATTERN, $value) !== 1)
		{
			return $value;
		}

		$float = (float)$value;

		return is_finite($float) ? $float : $value;
	}
}
