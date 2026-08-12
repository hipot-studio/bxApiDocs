<?php

namespace Bitrix\Crm\Format;

use Bitrix\Crm\Service\Accounting;

class Money
{
	/**
	 * Get money data in the formatted string representation. Format is specified by the Currency module
	 * @param float $sum
	 * @param string $currencyId
	 *
	 * @return string
	 */
	public static function format(float $sum, string $currencyId): string
	{
		return \CCrmCurrency::MoneyToString($sum, $currencyId);
	}

	/**
	 * Get money data in the formatted string representation. The provided custom template is used for formatting.
	 * When $template is not provided, no template is used.
	 * To use default template, please call Money::format
	 * @param float $sum
	 * @param string $currencyId
	 * @param string $template
	 *
	 * @return string
	 */
	public static function formatWithCustomTemplate(float $sum, string $currencyId, string $template = '#'): string
	{
		return \CCrmCurrency::MoneyToString($sum, $currencyId, $template);
	}

	/**
	 * Formats a numeric value as a plain decimal string using DECIMALS of the given currency.
	 *
	 * Unlike {@see format()} / {@see formatWithCustomTemplate()}, this method does NOT apply
	 * locale-specific decimal separator or thousands separator. The output is always shaped as
	 * "<integer>[.<fraction>]" — suitable for JS/CSV parsing and as a fetch-modifier replacement
	 * for hardcoded number_format($v, 2, '.', '').
	 *
	 * Falls back to {@see Accounting::getPricePublicPrecision()} when the currency cannot be
	 * resolved (null/empty currency code, currency module unavailable).
	 *
	 * @param float|int|string $value     Numeric value to format.
	 * @param string|null      $currencyId Currency code or null when unknown.
	 *
	 * @return string Formatted decimal string (e.g. "100.00", "101", "100.123").
	 */
	public static function toNumberString(float|int|string $value, ?string $currencyId): string
	{
		return number_format((float)$value, self::resolveDecimals($currencyId), '.', '');
	}

	/**
	 * Returns DECIMALS of the given currency, with a public-precision fallback.
	 *
	 * Treats 0 (e.g. JPY, KRW) as a legitimate DECIMALS value and does NOT fall back for it —
	 * the fallback is reserved only for the case when the currency itself cannot be resolved
	 * (null/empty code, currency module not available).
	 *
	 * Implemented as a thin wrapper over {@see Accounting::getPricePublicPrecision()}.
	 */
	public static function resolveDecimals(?string $currencyId): int
	{
		return Accounting::getPricePublicPrecision($currencyId);
	}
}