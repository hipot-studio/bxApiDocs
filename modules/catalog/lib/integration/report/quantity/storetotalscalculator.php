<?php

namespace Bitrix\Catalog\Integration\Report\Quantity;

final class StoreTotalsCalculator
{
	public const QUANTITY_FIELDS = [
		'STARTING_QUANTITY',
		'RECEIVED_QUANTITY',
		'AMOUNT_SUM',
		'QUANTITY_RESERVED_SUM',
		'QUANTITY',
		'AMOUNT_SOLD',
	];

	/**
	 * @param array $groupedStoreTotals [storeId => [measureId => entry]] as grouped by BaseHandler::getStoreTotals()
	 * @return array [storeId => ['TITLE', 'STORE_ID', 'TOTALS' => [measureId => quantity fields]]]
	 */
	public function calculateStoreTotals(array $groupedStoreTotals): array
	{
		$preparedTotals = [];

		foreach ($groupedStoreTotals as $storeId => $storeTotal)
		{
			foreach ($storeTotal as $measureId => $entry)
			{
				$preparedTotals[$storeId] ??= [
					'TITLE' => $entry['TITLE'],
					'STORE_ID' => $storeId,
					'TOTALS' => [],
				];

				$totals = $this->calculatePairTotals($entry, $measureId);
				if ($this->isEmpty($totals))
				{
					continue;
				}

				$preparedTotals[$storeId]['TOTALS'][$measureId] = $totals;
			}
		}

		return $preparedTotals;
	}

	/**
	 * @return array [measureId => quantity fields]
	 */
	public function sumByMeasure(array $storeTotals): array
	{
		$overallTotals = [];

		foreach ($storeTotals as $storeTotalEntry)
		{
			foreach ($storeTotalEntry['TOTALS'] as $measureId => $total)
			{
				$amountSum = (float)$total['AMOUNT_SUM'];
				$quantityReservedSum = (float)$total['QUANTITY_RESERVED_SUM'];

				$overallTotals[$measureId] ??= [
					'STARTING_QUANTITY' => 0.0,
					'RECEIVED_QUANTITY' => 0.0,
					'AMOUNT_SUM' => 0.0,
					'QUANTITY_RESERVED_SUM' => 0.0,
					'AMOUNT_SOLD' => 0.0,
					'QUANTITY' => 0.0,
				];
				$overallTotals[$measureId]['STARTING_QUANTITY'] += (float)$total['STARTING_QUANTITY'];
				$overallTotals[$measureId]['RECEIVED_QUANTITY'] += (float)$total['RECEIVED_QUANTITY'];
				$overallTotals[$measureId]['AMOUNT_SUM'] += $amountSum;
				$overallTotals[$measureId]['QUANTITY_RESERVED_SUM'] += $quantityReservedSum;
				$overallTotals[$measureId]['AMOUNT_SOLD'] += (float)$total['AMOUNT_SOLD'];
				$overallTotals[$measureId]['QUANTITY'] += $amountSum - $quantityReservedSum;
			}
		}

		foreach ($overallTotals as $measureId => $total)
		{
			$overallTotals[$measureId] = array_map([QuantityNormalizer::class, 'normalize'], $total);
		}

		return $overallTotals;
	}

	/**
	 * @param array $totalsByMeasure [measureId => [field => value]]
	 * @return array [field => [measureId => value]]
	 */
	public function pivotToFieldMeasure(array $totalsByMeasure): array
	{
		$totalsByField = [];

		foreach ($totalsByMeasure as $measureId => $fields)
		{
			foreach ($fields as $field => $value)
			{
				$totalsByField[$field][$measureId] = $value;
			}
		}

		return $totalsByField;
	}

	/**
	 * @param array $storeTotals [storeId => ['TOTALS' => [field => [measureId => value]] + money fields]]
	 * @return array [field => [measureId => value]] for quantity fields only
	 */
	public function sumByFieldAndMeasure(array $storeTotals): array
	{
		$overallTotals = array_fill_keys(self::QUANTITY_FIELDS, []);

		foreach ($storeTotals as $storeTotalEntry)
		{
			foreach (self::QUANTITY_FIELDS as $field)
			{
				$valuesByMeasure = $storeTotalEntry['TOTALS'][$field] ?? null;
				if (!is_array($valuesByMeasure))
				{
					continue;
				}

				foreach ($valuesByMeasure as $measureId => $value)
				{
					$overallTotals[$field][$measureId] ??= 0.0;
					$overallTotals[$field][$measureId] += (float)$value;
				}
			}
		}

		foreach ($overallTotals as $field => $valuesByMeasure)
		{
			$overallTotals[$field] = array_map([QuantityNormalizer::class, 'normalize'], $valuesByMeasure);
		}

		return $overallTotals;
	}

	private function calculatePairTotals(array $entry, int|string $measureId): array
	{
		$amountSum = QuantityNormalizer::normalize(
			(float)$entry['AMOUNT_SUM']
			- $this->getMeasureValue($entry, 'RECEIVED_QUANTITIES_DIFFERENCE', $measureId)
			+ $this->getMeasureValue($entry, 'OUTGOING_QUANTITIES_DIFFERENCE', $measureId)
			+ $this->getMeasureValue($entry, 'SOLD_AMOUNTS_DIFFERENCE', $measureId)
		);
		$quantityReservedSum = QuantityNormalizer::normalize((float)$entry['QUANTITY_RESERVED_SUM']);
		$receivedQuantity = QuantityNormalizer::normalize(
			$this->getMeasureValue($entry, 'RECEIVED_QUANTITIES', $measureId)
		);
		$amountSold = QuantityNormalizer::normalize($this->getMeasureValue($entry, 'SOLD_AMOUNTS', $measureId));

		return [
			'STARTING_QUANTITY' => QuantityNormalizer::normalize(
				$amountSum
				- $receivedQuantity
				+ $this->getMeasureValue($entry, 'OUTGOING_QUANTITIES', $measureId)
				+ $amountSold
			),
			'RECEIVED_QUANTITY' => $receivedQuantity,
			'AMOUNT_SUM' => $amountSum,
			'QUANTITY_RESERVED_SUM' => $quantityReservedSum,
			'QUANTITY' => QuantityNormalizer::normalize($amountSum - $quantityReservedSum),
			'AMOUNT_SOLD' => $amountSold,
		];
	}

	private function getMeasureValue(array $entry, string $key, int|string $measureId): float
	{
		return (float)($entry[$key][$measureId] ?? 0.0);
	}

	private function isEmpty(array $totals): bool
	{
		foreach ($totals as $value)
		{
			if ($value !== 0.0)
			{
				return false;
			}
		}

		return true;
	}
}
