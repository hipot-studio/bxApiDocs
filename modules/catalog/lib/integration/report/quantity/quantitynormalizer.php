<?php

namespace Bitrix\Catalog\Integration\Report\Quantity;

final class QuantityNormalizer
{
	public const SCALE = 6;
	public const MAX_SIGNIFICANT_DIGITS = 15;

	public static function normalize(float $value): float
	{
		$rounded = round($value, self::SCALE);

		return $rounded === 0.0 ? 0.0 : $rounded;
	}

	public static function format(float $value): string
	{
		$decimals = max(0, min(self::SCALE, self::MAX_SIGNIFICANT_DIGITS - self::countIntegerDigits($value)));

		$formatted = sprintf('%.' . $decimals . 'F', $value);
		if (str_contains($formatted, '.'))
		{
			$formatted = rtrim(rtrim($formatted, '0'), '.');
		}

		return $formatted === '-0' ? '0' : $formatted;
	}

	private static function countIntegerDigits(float $value): int
	{
		return strlen(sprintf('%.0F', floor(abs($value))));
	}
}
