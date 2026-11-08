<?php

namespace Bitrix\Catalog\Component;

use Bitrix\Currency\CurrencyClassifier;
use Bitrix\Currency\Integration\IblockMoneyProperty;
use CCurrencyLang;

class MoneyPropertyValueNormalizer
{
	public static function normalize(int|float|string|null $amount, string $currency): string
	{
		$raw = (string)$amount;
		$rawUnited = IblockMoneyProperty::getUnitedValue($raw, $currency);
		if ($raw === '' || preg_match('/^(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D', $raw) !== 1)
		{
			return $rawUnited;
		}

		$format = CCurrencyLang::GetFormatDescription($currency);
		$decimalPoint = (string)$format['DEC_POINT'];
		$decimals = (int)$format['DECIMALS'];
		$fraction = explode('.', $raw, 2)[1] ?? '';
		if ($decimalPoint === '' || $decimalPoint === '.')
		{
			return $rawUnited;
		}
		if (strlen($fraction) < 1 || strlen($fraction) > $decimals)
		{
			return $rawUnited;
		}
		if ((string)$format['THOUSANDS_SEP'] === '.' && strlen($fraction) === 3)
		{
			return $rawUnited;
		}

		$format['THOUSANDS_VARIANT'] = CurrencyClassifier::SEPARATOR_EMPTY;
		$format['THOUSANDS_SEP'] = '';
		$formatInput = str_starts_with($raw, '.') ? '0' . $raw : $raw;
		$formatted = CCurrencyLang::formatEditValue($formatInput, $format);
		$candidateUnited = IblockMoneyProperty::getUnitedValue($formatted, $currency);
		if (IblockMoneyProperty::checkFields([], ['VALUE' => $candidateUnited]) !== [])
		{
			return $rawUnited;
		}

		return $candidateUnited;
	}
}
