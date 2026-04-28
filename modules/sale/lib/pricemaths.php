<?php

namespace Bitrix\Sale;

use Bitrix\Main\Config\Option;

class PriceMaths
{
	private const DEFAULT_PRECISION = 8;

	public static function getCurrentPrecision(): int
	{
		return max(0, (int)Option::get('sale', 'value_precision_v2', self::DEFAULT_PRECISION));
	}

	/**
	 * @param $value
	 *
	 * @return float
	 */
	public static function roundPrecision($value): float
	{
		return round((float)$value, self::getCurrentPrecision());
	}

	/**
	 * @param $price
	 * @param $currency
	 *
	 * @return float
	 */
	public static function roundByFormatCurrency($price, $currency, ?int $limitRounding = null): float
	{
		$formattedByCurrency = SaleFormatCurrency($price, $currency, false, true);

		return $limitRounding === null || $limitRounding < 0 ? $formattedByCurrency : round($formattedByCurrency, $limitRounding);
	}
}
