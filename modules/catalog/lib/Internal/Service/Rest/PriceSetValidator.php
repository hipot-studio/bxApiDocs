<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Config\Feature;
use Bitrix\Catalog\GroupTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Validates a set of prices of one product: the rows already stored plus the row being written.
 *
 * Rules and error texts are carried over from Engine `Price::modifyValidate()` unchanged. The code
 * of every error is the name of the offending DTO property: the V3 transport renders
 * `Error::getCode()` as the `field` key of a validation item.
 *
 * The validator neither checks permissions nor writes anything; the value checks of a single row
 * (currency, price sign, existence of the price type and of the extra) stay in `Model\Price`.
 */
final class PriceSetValidator
{
	private const FIELD_CATALOG_GROUP_ID = 'catalogGroupId';
	private const FIELD_QUANTITY_FROM = 'quantityFrom';
	private const FIELD_QUANTITY_TO = 'quantityTo';

	/**
	 * @param list<array<string, mixed>> $items ORM-shaped price rows
	 */
	public function validate(array $items): Result
	{
		$result = new Result();
		if ($items === [])
		{
			return $result;
		}

		$priceTypes = GroupTable::getTypeList();
		$groups = [];
		$hasRanges = false;

		foreach ($items as $item)
		{
			$priceTypeId = (int)($item['CATALOG_GROUP_ID'] ?? 0);
			if (!isset($priceTypes[$priceTypeId]))
			{
				$result->addError(new Error(
					'Validate price error. Catalog price group is wrong',
					self::FIELD_CATALOG_GROUP_ID,
				));

				return $result;
			}

			// An empty bound is stored as NULL but reaches the API as zero, so emptiness is a value.
			$hasRanges = $hasRanges || $this->lowerBound($item) > 0 || $this->higherBound($item) > 0;
			$groups[$priceTypeId][] = $item;
		}

		if (!$hasRanges)
		{
			if (count($items) > count($groups))
			{
				$result->addError(new Error(
					'Validate price error. Catalog product is allowed has only single price without ranges in price group.',
					self::FIELD_CATALOG_GROUP_ID,
				));
			}

			return $result;
		}

		if (!Feature::isPriceQuantityRangesEnabled())
		{
			$result->addError(new Error(
				'Validate price error. Price quantity ranges disabled',
				self::FIELD_QUANTITY_FROM,
			));

			return $result;
		}

		$basePriceTypeId = (int)GroupTable::getBasePriceTypeId();
		if (!isset($groups[$basePriceTypeId]))
		{
			$result->addError($this->baseRangesMismatchError());

			return $result;
		}

		foreach ($groups as $priceTypeId => $prices)
		{
			$groups[$priceTypeId] = $this->sortByLowerBound($prices);
		}

		$baseRanges = $groups[$basePriceTypeId];
		foreach ($groups as $priceTypeId => $prices)
		{
			if ($priceTypeId !== $basePriceTypeId && !$this->hasSameRanges($prices, $baseRanges))
			{
				$result->addError($this->baseRangesMismatchError());

				return $result;
			}
		}

		$allowEmptyRange = Option::get('catalog', 'save_product_with_empty_price_range') === 'Y';
		foreach ($groups as $prices)
		{
			$this->checkRanges($result, $prices, $allowEmptyRange);
		}

		return $result;
	}

	/**
	 * Bound errors are accumulated: a set can be wrong in several places at once.
	 *
	 * @param list<array<string, mixed>> $prices sorted by the lower bound
	 */
	private function checkRanges(Result $result, array $prices, bool $allowEmptyRange): void
	{
		$last = count($prices) - 1;

		foreach ($prices as $index => $price)
		{
			$from = $this->lowerBound($price);
			$to = $this->higherBound($price);

			if (($index > 0 && $from <= 0) || ($index === 0 && $from < 0))
			{
				$result->addError(new Error(
					"Quantity bounds error: lower bound {$from} must be above zero (for the first range)",
					self::FIELD_QUANTITY_FROM,
				));
			}

			if ($index < $last && $to <= 0)
			{
				$result->addError(new Error(
					"Quantity bounds error: higher bound {$to} must be above zero (for the last range)",
					self::FIELD_QUANTITY_TO,
				));
			}

			if ($from > $to && !($index === $last && $to <= 0))
			{
				$result->addError(new Error(
					"Quantity bounds error: range {$from}-{$to} is incorrect",
					self::FIELD_QUANTITY_FROM,
				));
			}

			if ($index < $last)
			{
				$nextFrom = $this->lowerBound($prices[$index + 1]);
				$nextTo = $this->higherBound($prices[$index + 1]);

				if ($to >= $nextFrom)
				{
					$result->addError(new Error(
						"Quantity bounds error: ranges {$from}-{$to} and {$nextFrom}-{$nextTo} overlap",
						self::FIELD_QUANTITY_FROM,
					));
				}

				if (!$allowEmptyRange && $nextFrom - $to > 1)
				{
					$gapFrom = $to + 1;
					$gapTo = $nextFrom - 1;

					$result->addError(new Error(
						"Invalid quantity range entry: no price is specified for range {$gapFrom}-{$gapTo})",
						self::FIELD_QUANTITY_FROM,
					));
				}
			}
		}

		$lastHigherBound = $this->higherBound($prices[$last]);
		if ($lastHigherBound > 0)
		{
			$result->addError(new Error(
				"Invalid quantity range entry: no price is specified for quantity over {$lastHigherBound}",
				self::FIELD_QUANTITY_TO,
			));
		}
	}

	/**
	 * @param list<array<string, mixed>> $prices
	 * @return list<array<string, mixed>>
	 */
	private function sortByLowerBound(array $prices): array
	{
		usort(
			$prices,
			fn (array $left, array $right): int => $this->lowerBound($left) <=> $this->lowerBound($right),
		);

		return $prices;
	}

	/**
	 * @param list<array<string, mixed>> $prices
	 * @param list<array<string, mixed>> $baseRanges
	 */
	private function hasSameRanges(array $prices, array $baseRanges): bool
	{
		if (count($prices) !== count($baseRanges))
		{
			return false;
		}

		foreach ($prices as $index => $price)
		{
			if (
				$this->lowerBound($price) !== $this->lowerBound($baseRanges[$index])
				|| $this->higherBound($price) !== $this->higherBound($baseRanges[$index])
			)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $price
	 */
	private function lowerBound(array $price): float
	{
		return (float)($price['QUANTITY_FROM'] ?? 0);
	}

	/**
	 * @param array<string, mixed> $price
	 */
	private function higherBound(array $price): float
	{
		return (float)($price['QUANTITY_TO'] ?? 0);
	}

	private function baseRangesMismatchError(): Error
	{
		return new Error(
			'Validate price error. Ranges of base price are not equal to another price group range',
			self::FIELD_QUANTITY_FROM,
		);
	}
}
