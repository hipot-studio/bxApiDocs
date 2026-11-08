<?php

namespace Bitrix\Catalog\Integration\Report\Handler\StoreProfit;

use Bitrix\Catalog\Integration\Report\Filter\StoreStockFilter;
use Bitrix\Catalog\Integration\Report\Handler\BaseHandler;
use Bitrix\Catalog\Integration\Report\Quantity\StoreTotalsCalculator;
use Bitrix\Catalog\Integration\Report\StoreStock\StoreStockSale;
use Bitrix\Catalog\Product\Price\Calculation;
use Bitrix\Currency\CurrencyManager;
use Bitrix\Main\Loader;

abstract class ProfitHandler extends BaseHandler
{
	private const SUMMED_MONEY_FIELDS = ['TOTAL_SOLD', 'TOTAL_COST_PRICE', 'PROFIT'];

	private function getPriceAmounts(): array
	{
		return StoreStockSale::getProductsSoldPricesForStores(
			$this->getFormattedFilter()
		);
	}

	protected function preparePriceFields(array $fields, string $currency): array
	{
		$reportCurrency = $this->getBaseCurrency();
		if (!$reportCurrency)
		{
			return $fields;
		}

		foreach ($fields as $key => $value)
		{
			$fields[$key] = Calculation::roundByFormatCurrency(
				\CCurrencyRates::convertCurrency($value, $currency, $reportCurrency),
				$reportCurrency
			);
		}

		return $fields;
	}

	protected function preparePrice(float $value, string $currency): float
	{
		$reportCurrency = $this->getBaseCurrency();
		if (!$reportCurrency)
		{
			return $value;
		}

		return Calculation::roundByFormatCurrency(
			\CCurrencyRates::convertCurrency($value, $currency, $reportCurrency),
			$reportCurrency
		);
	}

	protected function getBaseCurrency(): ?string
	{
		if (!Loader::includeModule('currency'))
		{
			return null;
		}

		return CurrencyManager::getBaseCurrency();
	}

	protected function getStoreTotals(): array
	{
		$storeTotals = parent::getStoreTotals();
		if (empty($storeTotals))
		{
			return $storeTotals;
		}

		$priceAmounts = $this->getPriceAmounts();

		$calculator = new StoreTotalsCalculator();
		$formattedTotalsByPrices = $storeTotals;
		foreach ($storeTotals as $storeId => $storeTotal)
		{
			$total = [
				'TOTAL_SOLD' => 0,
				'TOTAL_COST_PRICE' => 0,
			];
			$total += $calculator->pivotToFieldMeasure($storeTotal['TOTALS'] ?? []);

			$priceAmounts[$storeId] ??= [];
			foreach ($priceAmounts[$storeId] as $currency => $fields)
			{
				$fields = $this->preparePriceFields($fields, $currency);
				$total['TOTAL_SOLD'] ??= 0;
				$total['TOTAL_SOLD'] += $fields['TOTAL_SOLD'];
				$total['TOTAL_COST_PRICE'] ??= 0;
				$total['TOTAL_COST_PRICE'] += $fields['COST_PRICE'];
			}

			$total['PROFIT'] = $total['TOTAL_SOLD'] - $total['TOTAL_COST_PRICE'];
			$total['PROFITABILITY'] = $this->calculateProfitability((float)$total['TOTAL_COST_PRICE'], (float)$total['PROFIT']);
			$formattedTotalsByPrices[$storeId]['TOTALS'] = $total;
		}

		return $formattedTotalsByPrices;
	}

	protected function prepareOverallTotals(array $storeTotals): array
	{
		$overallTotals = (new StoreTotalsCalculator())->sumByFieldAndMeasure($storeTotals);
		$overallTotals += [
			'TOTAL_SOLD' => 0,
			'TOTAL_COST_PRICE' => 0,
			'PROFIT' => 0,
			'PROFITABILITY' => 0,
		];

		foreach ($storeTotals as $storeTotalEntry)
		{
			foreach (self::SUMMED_MONEY_FIELDS as $fieldName)
			{
				$overallTotals[$fieldName] += (float)($storeTotalEntry['TOTALS'][$fieldName] ?? 0.0);
			}
		}

		$overallTotals['PROFITABILITY'] = $this->calculateProfitability($overallTotals['TOTAL_COST_PRICE'], $overallTotals['PROFIT']);

		return $overallTotals;
	}

	protected function calculateProfitability(float $costPrice, float $profit): ?float
	{
		if ($costPrice <= 0 && $profit <= 0)
		{
			return null;
		}

		$profitability =
			$costPrice > 0
				? round($profit / $costPrice, 4)
				: 0
		;

		return $profitability * 100;
	}

	protected function getProductFilter(array $productFilter): array
	{
		return ['=PRODUCT_ID' => StoreStockFilter::prepareProductFilter($productFilter)];
	}
}
