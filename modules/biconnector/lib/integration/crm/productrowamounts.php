<?php

namespace Bitrix\BIConnector\Integration\Crm;

use Bitrix\BIConnector\Manager;
use Bitrix\Crm\Discount;
use Bitrix\Main\DB\SqlHelper;
use Bitrix\Main\Loader;

/**
 * Amount columns of a product row, shared by the deal and the lead product row datasets.
 *
 * The amounts repeat what the product table of the item card computes, so that a BI report does not
 * have to multiply and round the prices itself. Rounding follows the number of decimals of the
 * currency format of the parent item, which is why the scale is a CASE over its currency code
 * rather than a constant.
 */
class ProductRowAmounts
{
	private const QUANTITY = 'PR.QUANTITY';
	private const DEFAULT_DECIMALS = 2;
	private const CURRENCY_FORMAT_CACHE_TTL = 86400;
	private const DATASETS = ['crm_deal_product_row', 'crm_lead_product_row'];
	private const AMOUNT_FIELDS = ['SUM_NETTO', 'SUM_EXCLUSIVE', 'SUM_TOTAL'];
	/** Scale of the price columns of b_crm_product_row, DECIMAL(26,8) (see crm/install/migrations/tables.php). */
	private const PRICE_SCALE = 8;

	/** @var array<string, array<string, int>> Language => currency code => number of decimals. */
	private static array $currencyDecimals = [];

	/**
	 * Returns the description of the amount and currency columns for one owner entity.
	 *
	 * @param SqlHelper&\Bitrix\BIConnector\DB\BiSqlHelperInterface $helper
	 * @param string $languageId Language the currency format is chosen by.
	 * @param string $ownerAlias Alias of the parent table (D for a deal, L for a lead).
	 * @param string|array $join Inner join of the parent table, taken as is.
	 * @param string|array $leftJoin Left join of the parent table, taken as is.
	 *
	 * @return array
	 */
	public static function getFieldsDescription(
		SqlHelper $helper,
		string $languageId,
		string $ownerAlias,
		string|array $join,
		string|array $leftJoin,
	): array
	{
		$scale = self::getScaleExpression($helper, $languageId, $ownerAlias);
		$ownerTable = [
			'TABLE_ALIAS' => $ownerAlias,
			'JOIN' => $join,
			'LEFT_JOIN' => $leftJoin,
		];

		$exclusivePrice = '(' . self::getExclusivePriceExpression() . ')';
		$nettoPrice = '(' . self::getNettoPriceExpression($exclusivePrice) . ')';
		$taxAdded = $exclusivePrice . ' * ' . self::QUANTITY
			. ' * (1 + coalesce(PR.TAX_RATE, 0) / 100)';
		$total = "case when PR.TAX_INCLUDED = 'Y' then coalesce(PR.PRICE, 0) * " . self::QUANTITY
			. ' else ' . $taxAdded . ' end';

		return [
			'SUM_NETTO' => [
				'IS_METRIC' => 'Y',
				'AGGREGATION_TYPE' => 'SUM',
				'FIELD_NAME' => $helper->getRoundExpression(
					$nettoPrice . ' * ' . self::QUANTITY,
					$scale,
				),
				'FIELD_TYPE' => 'double',
			] + $ownerTable,
			'SUM_EXCLUSIVE' => [
				'IS_METRIC' => 'Y',
				'AGGREGATION_TYPE' => 'SUM',
				'FIELD_NAME' => $helper->getRoundExpression(
					$exclusivePrice . ' * ' . self::QUANTITY,
					$scale,
				),
				'FIELD_TYPE' => 'double',
			] + $ownerTable,
			'SUM_TOTAL' => [
				'IS_METRIC' => 'Y',
				'AGGREGATION_TYPE' => 'SUM',
				'FIELD_NAME' => $helper->getRoundExpression($total, $scale),
				'FIELD_TYPE' => 'double',
			] + $ownerTable,
			'CURRENCY_ID' => [
				'IS_METRIC' => 'N',
				'FIELD_NAME' => $ownerAlias . '.CURRENCY_ID',
				'FIELD_TYPE' => 'string',
			] + $ownerTable,
		];
	}

	/**
	 * Returns the amount columns of a dataset whose preview values are brought to the price scale.
	 *
	 * MySQL types ROUND with a non-constant scale as DECIMAL with the scale of the whole expression,
	 * so the amounts come with a long tail of zeros, unlike the price columns stored as DECIMAL(26,8).
	 * PostgreSQL returns the scale of the currency and is left as is.
	 *
	 * @param string $datasetName
	 *
	 * @return string[]
	 */
	public static function getPreviewAmountFields(string $datasetName): array
	{
		if (
			!in_array($datasetName, self::DATASETS, true)
			|| Manager::getInstance()->getDatabaseConnection()->getType() !== 'mysql'
		)
		{
			return [];
		}

		return self::AMOUNT_FIELDS;
	}

	/**
	 * Formats a decimal string of an amount with the scale of the price columns.
	 *
	 * Insignificant zeros are trimmed, digits beyond the price scale are kept; values of any other form
	 * are returned unchanged.
	 *
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	public static function formatPreviewAmount(mixed $value): mixed
	{
		if (!is_string($value) || preg_match('/^(-?[0-9]+)(?:\.([0-9]+))?$/D', $value, $parts) !== 1)
		{
			return $value;
		}

		$fraction = rtrim($parts[2] ?? '', '0');

		return $parts[1] . '.' . str_pad($fraction, self::PRICE_SCALE, '0');
	}

	private static function getExclusivePriceExpression(): string
	{
		// Accounting restores optional prices omitted by the legacy product row API the same way.
		return 'case when coalesce(PR.PRICE_EXCLUSIVE, 0) = 0 and coalesce(PR.PRICE, 0) <> 0'
			. ' and coalesce(PR.TAX_RATE, 0) <> -100'
			. ' then PR.PRICE / (1 + coalesce(PR.TAX_RATE, 0) / 100)'
			. ' else coalesce(PR.PRICE_EXCLUSIVE, 0) end';
	}

	private static function getNettoPriceExpression(string $exclusivePrice): string
	{
		// The product table restores the original price using the discount when it is missing or zero.
		return 'case when coalesce(PR.PRICE_NETTO, 0) <> 0 then PR.PRICE_NETTO'
			. ' when PR.DISCOUNT_TYPE_ID = ' . Discount::MONETARY . ' or coalesce(PR.DISCOUNT_RATE, 0) >= 100'
			. ' then ' . $exclusivePrice . ' + coalesce(PR.DISCOUNT_SUM, 0)'
			. ' else 100 * ' . $exclusivePrice . ' / (100 - coalesce(PR.DISCOUNT_RATE, 0)) end';
	}

	private static function getScaleExpression(SqlHelper $helper, string $languageId, string $ownerAlias): string
	{
		$defaultDecimals = self::getDefaultDecimals();

		$branches = [];
		foreach (self::getCurrencyDecimals($languageId) as $currencyId => $decimals)
		{
			$branches[] = "when '" . $helper->forSql((string)$currencyId) . "' then " . (int)$decimals;
		}

		if (empty($branches))
		{
			return (string)$defaultDecimals;
		}

		return 'case ' . $ownerAlias . '.CURRENCY_ID '
			. implode(' ', $branches)
			. ' else ' . $defaultDecimals . ' end';
	}

	/**
	 * Number of decimals of every currency the portal has a format for.
	 *
	 * The whole directory is read at once: the formats are needed for every currency, and the format
	 * cache of the currency module is filled on main:OnEpilog, which a BI request never reaches.
	 * DECIMALS does not depend on the language, so a currency without a row for the requested language
	 * takes the value of any other language it has; a currency without a format anywhere is left out
	 * of the map and falls into the default branch of the scale expression.
	 *
	 * @param string $languageId
	 *
	 * @return array Currency code => number of decimals of its format.
	 */
	private static function getCurrencyDecimals(string $languageId): array
	{
		if (isset(self::$currencyDecimals[$languageId]))
		{
			return self::$currencyDecimals[$languageId];
		}

		if (!Loader::includeModule('currency'))
		{
			return [];
		}

		$requestedLanguage = [];
		$otherLanguages = [];
		$rows = \Bitrix\Currency\CurrencyLangTable::getList([
			'select' => ['CURRENCY', 'LID', 'DECIMALS'],
			'order' => ['CURRENCY' => 'ASC', 'LID' => 'ASC'],
			'cache' => ['ttl' => self::CURRENCY_FORMAT_CACHE_TTL],
		]);
		foreach ($rows as $row)
		{
			$currencyId = (string)$row['CURRENCY'];
			$decimals = $row['DECIMALS'] === null ? self::getDefaultDecimals() : (int)$row['DECIMALS'];

			if ((string)$row['LID'] === $languageId)
			{
				$requestedLanguage[$currencyId] = $decimals;
			}
			elseif (!isset($otherLanguages[$currencyId]))
			{
				$otherLanguages[$currencyId] = $decimals;
			}
		}

		$decimals = $requestedLanguage + $otherLanguages;
		ksort($decimals);
		self::$currencyDecimals[$languageId] = $decimals;

		return self::$currencyDecimals[$languageId];
	}

	private static function getDefaultDecimals(): int
	{
		if (!Loader::includeModule('currency'))
		{
			return self::DEFAULT_DECIMALS;
		}

		return (int)(\CCurrencyLang::GetDefaultValues()['DECIMALS'] ?? self::DEFAULT_DECIMALS);
	}
}
