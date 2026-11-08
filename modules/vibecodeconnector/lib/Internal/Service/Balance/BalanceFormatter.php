<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Balance;

final class BalanceFormatter
{
	public static function format(string $raw, string $decimalSeparator, string $thousandsSeparator): string
	{
		if (preg_match('/^[+-]?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $raw) !== 1)
		{
			$raw = '0';
		}

		$negative = $raw[0] === '-';
		$unsigned = ltrim($raw, '+-');
		[$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
		$digits = $integer . substr($fraction . '00', 0, 2);
		if (isset($fraction[2]) && $fraction[2] >= '5')
		{
			$digits = self::increment($digits);
		}

		$integer = substr($digits, 0, -2);
		$fraction = substr($digits, -2);
		$groups = [];
		for ($end = strlen($integer); $end > 0; $end -= 3)
		{
			$start = max(0, $end - 3);
			$groups[] = substr($integer, $start, $end - $start);
		}

		$sign = $negative && trim($digits, '0') !== '' ? "\u{2212}" : '';

		return $sign . implode($thousandsSeparator, array_reverse($groups)) . $decimalSeparator . $fraction;
	}

	private static function increment(string $digits): string
	{
		for ($index = strlen($digits) - 1; $index >= 0; $index--)
		{
			if ($digits[$index] !== '9')
			{
				$digits[$index] = chr(ord($digits[$index]) + 1);

				return $digits;
			}

			$digits[$index] = '0';
		}

		return '1' . $digits;
	}
}
