<?php

namespace Bitrix\Crm\Service;

use Bitrix\Crm;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\ProductRow;
use Bitrix\Crm\ProductRowTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\Result;
use Bitrix\Main\Config\Option;

class Accounting
{
	protected const PRICE_MAX_PRECISION = 8;
	protected const PRICE_PUBLIC_PRECISION = 2;

	protected const RATE_PRECISION = 4;

	protected const QUANTITY_PRECISION = 4;

	protected bool $isTaxMode = false;
	protected array $cache = [];

	private static array $productRowFieldNames;

	public function __construct()
	{
		$this->isTaxMode = \CCrmTax::isTaxMode();
	}

	/**
	 * Return true if location dependant tax mode is used.
	 * By default VAT taxes used.
	 *
	 * @return bool
	 */
	public function isTaxMode(): bool
	{
		return $this->isTaxMode;
	}

	/**
	 * Switch location dependant tax mode.
	 *
	 * @param bool $isTaxMode
	 * @return $this
	 */
	public function setTaxMode(bool $isTaxMode): self
	{
		$this->isTaxMode = $isTaxMode;

		return $this;
	}

	/**
	 * Clear inner calculation cache.
	 *
	 * @return $this
	 */
	public function clearCache(): self
	{
		$this->cache = [];

		return $this;
	}

	private function isResultCached(Item $item): bool
	{
		if ($item->isNew())
		{
			return false;
		}

		$hash = $this->compileHash($item);

		return isset($this->cache[$hash]);
	}

	private function compileHash(Item $item): string
	{
		$ownerHash = serialize($item->getData());

		$productRows = $item->getProductRows() ? $item->getProductRows()->toArray() : [];
		$productRowsHash = serialize($productRows);

		// return result from cache only if a current item is exactly the same as previous
		return md5($ownerHash . $productRowsHash);
	}

	private function getResultFromCache(Item $item): Accounting\Result
	{
		if ($item->isNew())
		{
			throw new ArgumentException('Accounting result caching for new items is not possible');
		}

		$hash = $this->compileHash($item);

		$result = $this->cache[$hash] ?? null;
		if (!$result)
		{
			throw new InvalidOperationException('Result for this item is not cached yet');
		}

		return $result;
	}

	private function cacheResult(Item $item, Accounting\Result $result): void
	{
		if ($item->isNew())
		{
			return;
		}

		$hash = $this->compileHash($item);

		$this->cache[$hash] = $result;
	}

	/**
	 * Calculate total sums based on current tax mode and $item`s fields.
	 * This method stores calculation result in inner cache.
	 *
	 * @param Item $item
	 * @return Accounting\Result
	 */
	public function calculateByItem(Item $item): Accounting\Result
	{
		if ($this->isResultCached($item))
		{
			return $this->getResultFromCache($item);
		}

		$productRows = $item->getProductRows() ? $item->getProductRows()->toArray() : [];
		$personTypeId = $this->resolvePersonTypeId($item);

		$locationId = null;
		if ($item->hasField(Item::FIELD_NAME_LOCATION_ID))
		{
			$locationId = $item->get(Item::FIELD_NAME_LOCATION_ID);
		}

		$result = Accounting\Result::initializeFromArray(
			$this->calculate($productRows, $item->getCurrencyId(), $personTypeId, $locationId),
		);

		$this->cacheResult($item, $result);

		return $result;
	}

	/**
	 * Calculates total sums based on current tax mode and provided data.
	 * @see \CCrmSaleHelper::Calculate
	 *
	 * @param array $productRows
	 * @param string $currencyId
	 * @param int $personTypeId
	 * @param string|null $locationId
	 * @return array|null
	 */
	public function calculate(
		array $productRows,
		string $currencyId,
		int $personTypeId,
		?string $locationId = null,
	): ?array
	{
		$options = [
			'ALLOW_LD_TAX' => 'N',
		];
		if ($this->isTaxMode())
		{
			$options['ALLOW_LD_TAX'] = 'Y';
			if (!empty($locationId))
			{
				$options['LOCATION_ID'] = $locationId;
			}
		}

		return \CCrmSaleHelper::Calculate(
			$productRows,
			$currencyId,
			$personTypeId,
			false,
			SITE_ID,
			$options,
		);
	}

	public function calculateDeliveryTotal(ItemIdentifier $itemIdentifier): float
	{
		$orderIds = \Bitrix\Crm\Binding\OrderEntityTable::getOrderIdsByOwner(
			$itemIdentifier->getEntityId(),
			$itemIdentifier->getEntityTypeId(),
		);

		$orders = Container::getInstance()->getOrderBroker()->getBunchByIds($orderIds);

		$total = 0;
		foreach ($orders as $order)
		{
			$total += $order->getShipmentCollection()->getPriceDelivery();
		}

		return (float)$total;
	}

	/**
	 * Returns person type id based on data contained in the $item
	 *
	 * @param Item $item
	 *
	 * @return int - if a suitable person type was not found, returns 0
	 */
	public function resolvePersonTypeId(Item $item): int
	{
		$personTypes = \CCrmPaySystem::getPersonTypeIDs();

		if (isset($personTypes['COMPANY']) && ($item->getCompanyId() > 0))
		{
			return (int)$personTypes['COMPANY'];
		}
		if (isset($personTypes['CONTACT']))
		{
			return (int)$personTypes['CONTACT'];
		}

		return 0;
	}

	public static function getPricePrecision(): int
	{
		return self::PRICE_MAX_PRECISION;
	}

	public static function getPricePublicPrecision(): int
	{
		return (int)Option::get('sale', 'value_precision', self::PRICE_PUBLIC_PRECISION);
	}

	public static function getRatePrecision(): int
	{
		return self::RATE_PRECISION;
	}

	public static function getQuantityPrecision(): int
	{
		return self::QUANTITY_PRECISION;
	}

	public static function round(
		mixed $value,
		?int $precision = null,
	): float
	{
		return round((float)$value, $precision ?? static::getPricePrecision());
	}

	private static function getProductRowFieldNames(): array
	{
		if (!isset(self::$productRowFieldNames))
		{
			$entity = ProductRowTable::getEntity();
			self::$productRowFieldNames = array_fill_keys(
				array_keys($entity->getScalarFields()),
				true,
			);
			unset($entity);
		}

		return self::$productRowFieldNames;
	}

	/**
	 * Returns original price before tax
	 *
	 * @param float $priceWithTax
	 * @param null|float $taxRate
	 *
	 * @return float
	 */
	public function calculatePriceWithoutTax(float $priceWithTax, ?float $taxRate = null): float
	{
		$taxRate ??= 0.0;

		return static::calculatePriceExcludingTax($priceWithTax, $taxRate);
	}

	/**
	 * Applies tax with $taxRate to the price and returns its new value
	 *
	 * @param float $priceWithoutTax
	 * @param null|float $taxRate
	 *
	 * @return float
	 */
	public function calculatePriceWithTax(float $priceWithoutTax, ?float $taxRate = null): float
	{
		$taxRate ??= 0.0;

		return static::calculatePriceIncludingTax($priceWithoutTax, $taxRate);
	}

	/**
	 * Returns original price before tax
	 *
	 * @param float| $priceWithTax
	 * @param float $taxRate
	 *
	 * @return float
	 */
	public static function calculatePriceExcludingTax(float $priceWithTax, float $taxRate): float
	{
		return $priceWithTax / (1 + ($taxRate / 100));
	}

	/**
	 * Applies tax with $taxRate to the price and returns its new value
	 *
	 * @param float|int|string $priceWithoutTax
	 * @param float|int|string|null $taxRate
	 *
	 * @return float
	 */
	public static function calculatePriceIncludingTax(float $priceWithoutTax, float $taxRate): float
	{
		return $priceWithoutTax * (1 + ($taxRate / 100));
	}

	public static function calculateDiscountRate(float $originalPrice, float $price): float
	{
		if ($originalPrice === 0.0)
		{
			return 0.0;
		}

		if ($price === 0.0)
		{
			return $originalPrice > 0 ? 100.0 : -100.0;
		}

		return (100 * ($originalPrice - $price)) / $originalPrice;
	}

	public static function calculateOriginalPrice(float $exclusivePrice, float $discountRate): float
	{
		if ($discountRate === 100.0)
		{
			return 0.0;
		}

		return (100 * $exclusivePrice) / (100 - $discountRate);
	}

	public static function calculatePrice(float $originalPrice, float $discountRate): float
	{
		return $originalPrice - (($originalPrice * $discountRate) / 100);
	}

	public static function calculateDiscountValue(float $exclusivePrice, float $discountRate): float
	{
		if ($discountRate === 100.0)
		{
			return 0.0;
		}

		return static::calculateOriginalPrice($exclusivePrice, $discountRate) - $exclusivePrice;
	}

	/**
	 * @param ProductRow $productRow
	 * @param array $config
	 *
	 * @return Result
	 */
	public static function recalculateProductRow(ProductRow $productRow, array $config = []): Result
	{
		$fields = [
			'PRICE' => $productRow->getPrice(),
			'TAX_RATE' => $productRow->getTaxRate(),
			'TAX_INCLUDED' => $productRow->getTaxIncluded() ? 'Y' : 'N',
			'DISCOUNT_TYPE_ID' => $productRow->getDiscountTypeId(),
			'DISCOUNT_RATE' => $productRow->getDiscountRate(),
			'DISCOUNT_SUM' => $productRow->getDiscountSum(),
		];

		$internalResult = static::calculateProductPrices($fields, $config);
		if ($internalResult->isSuccess())
		{
			$resultFields = $internalResult->getData();
			$productRow->setPrice($resultFields['PRICE']);
			$productRow->setPriceExclusive($resultFields['PRICE_EXCLUSIVE']);
			$productRow->setDiscountTypeId($resultFields['DISCOUNT_TYPE_ID']);
			$productRow->setDiscountRate($resultFields['DISCOUNT_RATE']);
			$productRow->setDiscountSum($resultFields['DISCOUNT_SUM']);
			$productRow->setPriceNetto($resultFields['PRICE_NETTO']);
			$productRow->setPriceBrutto($resultFields['PRICE_BRUTTO']);
			if (isset($resultFields['PRICE_ACCOUNT']))
			{
				$productRow->setPriceAccount($resultFields['PRICE_ACCOUNT']);
			}

			$internalResult->setData([
				'PRECISION' => $resultFields['PRECISION'],
			]);
		}

		return $internalResult;
	}

	public static function calculateProductPrices(array $product, array $config = []): Result
	{
		$result = new Result();

		$pricePrecision = (int)($config['PRECISION']['PRICE_PRECISION'] ?? static::getPricePrecision());
		$ratePrecision = (int)($config['PRECISION']['RATE_PRECISION'] ?? static::getRatePrecision());
		$quantityPrecision = (int)($config['PRECISION']['QUANTITY_PRECISION'] ?? static::getQuantityPrecision());

		$product = array_intersect_key($product, self::getProductRowFieldNames());
		if (empty($product))
		{
			$result->addError(
				new Error(
					'Empty product data',
					ProductRow::ERROR_CODE_NORMALIZATION_COMMON_ERROR,
				),
			);

			return $result;
		}

		$fields = [];
		$fields['PRICE'] = (float)($product['PRICE'] ?? 0.0);
		$fields['PRICE_EXCLUSIVE'] = (float)($product['PRICE_EXCLUSIVE'] ?? 0.0);

		$fields['QUANTITY'] =
			isset($product['QUANTITY'])
				? static::round((float)$product['QUANTITY'], $quantityPrecision)
				: 1
		;

		$fields['DISCOUNT_TYPE_ID'] = (int)($product['DISCOUNT_TYPE_ID'] ?? Crm\Discount::UNDEFINED);
		if (!Crm\Discount::isDefined($fields['DISCOUNT_TYPE_ID']))
		{
			$fields['DISCOUNT_TYPE_ID'] = Crm\Discount::PERCENTAGE;
			$product['DISCOUNT_RATE'] = 0.0;
			$product['DISCOUNT_SUM'] = 0.0;
		}

		$inclusivePrice = $fields['PRICE'];
		$exclusivePrice = $fields['PRICE_EXCLUSIVE'];

		$fields['TAX_RATE'] = 0.0;
		if (isset($product['TAX_RATE']))
		{
			$fields['TAX_RATE'] = static::round((float)$product['TAX_RATE'], $ratePrecision);
		}
		$fields['TAX_INCLUDED'] = ($product['TAX_INCLUDED'] ?? 'N') === 'Y' ? 'Y' : 'N';
		if ($exclusivePrice === 0.0 && $inclusivePrice !== 0.0)
		{
			$exclusivePrice = static::calculatePriceExcludingTax($inclusivePrice, $fields['TAX_RATE']);
		}

		$discountTypeId = $fields['DISCOUNT_TYPE_ID'];
		$discountRate = 0.0;
		$discountValue = 0.0;

		switch ($discountTypeId)
		{
			case Crm\Discount::PERCENTAGE:
				if (!isset($product['DISCOUNT_RATE']))
				{
					$result->addError(new Error(
						'Discount Rate (DISCOUNT_RATE) is required '
						. 'if Percentage Discount Type (DISCOUNT_TYPE_ID) is defined.',
						ProductRow::ERROR_CODE_NORMALIZATION_DISCOUNT_RATE_REQUIRED,
					));

					return $result;
				}
				$discountRate = (float)$product['DISCOUNT_RATE'];
				if ($discountRate === 100.0)
				{
					if (empty($product['DISCOUNT_SUM']))
					{
						$result->addError(new Error(
							'Discount Sum (DISCOUNT_SUM) is required if Percentage Discount Type (DISCOUNT_TYPE_ID) '
							. 'is defined and Discount Rate (DISCOUNT_RATE) is 100%',
							ProductRow::ERROR_CODE_NORMALIZATION_DISCOUNT_SUM_REQUIRED,
						));

						return $result;
					}
				}
				$discountValue =
					$discountRate === 100.0
						? (float)$product['DISCOUNT_SUM']
						: static::calculateDiscountValue($exclusivePrice, $discountRate)
				;
				break;
			case Crm\Discount::MONETARY:
				if (!isset($product['DISCOUNT_SUM']))
				{
					$result->addError(new Error(
						'Discount Sum (DISCOUNT_SUM) is required '
						. 'if Monetary Discount Type (DISCOUNT_TYPE_ID) is defined.',
						ProductRow::ERROR_CODE_NORMALIZATION_DISCOUNT_SUM_REQUIRED,
					));

					return $result;
				}

				$discountValue = (float)$product['DISCOUNT_SUM'];
				$discountRate =
					isset($product['DISCOUNT_RATE'])
						? (float)$product['DISCOUNT_RATE']
						: static::calculateDiscountRate(
							$exclusivePrice + $discountValue,
							$exclusivePrice
						)
				;
				break;
		}

		$fields['PRICE'] = static::round($inclusivePrice, $pricePrecision);
		$fields['PRICE_EXCLUSIVE'] = static::round($exclusivePrice, $pricePrecision);

		$priceNetto = (float)($product['PRICE_NETTO'] ?? $exclusivePrice + $discountValue);
		$fields['PRICE_NETTO'] = static::round($priceNetto, $pricePrecision);

		$priceBrutto =
			isset($product['PRICE_BRUTTO'])
				? (float)$product['PRICE_BRUTTO']
				: static::calculatePriceIncludingTax($priceNetto, $fields['TAX_RATE'])
		;

		$fields['PRICE_BRUTTO'] = static::round($priceBrutto, $pricePrecision);

		$fields['DISCOUNT_SUM'] = static::round($discountValue, $pricePrecision);
		$fields['DISCOUNT_RATE'] = static::round($discountRate, $ratePrecision);

		if (isset($config['CURRENCY_ID']))
		{
			$fields['PRICE_ACCOUNT'] = Crm\Currency\Conversion::toAccountCurrency(
				$inclusivePrice,
				$config['CURRENCY_ID'],
				$config['EXCH_RATE'] ?? null,
			);
			$fields['PRICE_ACCOUNT'] = static::round($fields['PRICE_ACCOUNT'], $pricePrecision);
		}

		$fields['PRECISION'] = [
			'PRICE_PRECISION' => $pricePrecision,
			'RATE_PRECISION' => $ratePrecision,
			'QUANTITY_PRECISION' => $quantityPrecision,
		];

		$result->setData($fields);

		return $result;
	}
}
