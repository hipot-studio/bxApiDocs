<?php

namespace Bitrix\Iblock\Internal\Integration\Catalog;

use Bitrix\Catalog;
use Bitrix\Main\Config\Option;

/**
 * Writes prices of inline editing in the admin element lists through the legacy catalog API, as the admin list
 * scripts did: the base price check, prices with a markup recalculated from the base price, the purchasing price
 * of an offer.
 *
 * Price values of an element are lists of the posted values: field => price type id => value, where fields are
 * the properties of \Bitrix\Iblock\Public\Command\Catalog\PriceInput.
 */
final class LegacyPriceWriter
{
	private ?int $basePriceTypeId = null;
	private ?bool $basePriceRequired = null;
	private ?array $extraPercents = null;

	/**
	 * Checks whether the element must not be saved because of the base price: it is negative in the posted values or,
	 * if it is not posted, missing or negative in the database. The check is off if products may be saved
	 * without a price.
	 *
	 * @param int $elementId
	 * @param array $prices Price values of the element.
	 * @return bool
	 */
	public function isBasePriceMissing(int $elementId, array $prices): bool
	{
		if (!$this->isBasePriceRequired())
		{
			return false;
		}

		$basePriceTypeId = $this->getBasePriceTypeId();
		if (isset($prices['price'][$basePriceTypeId]))
		{
			return (float)$prices['price'][$basePriceTypeId] < 0;
		}

		$basePrice = Catalog\PriceTable::getRow([
			'select' => [
				'ID',
				'PRICE',
			],
			'filter' => [
				'=PRODUCT_ID' => $elementId,
				'=CATALOG_GROUP_ID' => $basePriceTypeId,
				'=QUANTITY_FROM' => $this->getQuantityBound($prices['quantityFrom'][$basePriceTypeId] ?? null),
				'=QUANTITY_TO' => $this->getQuantityBound($prices['quantityTo'][$basePriceTypeId] ?? null),
			],
		]);

		return $basePrice === null || (float)$basePrice['PRICE'] < 0;
	}

	/**
	 * Saves changed prices of the element. A changed base price also updates prices with a markup.
	 *
	 * @param int $elementId
	 * @param array $prices Price values of the element.
	 * @return void
	 */
	public function saveElementPrices(int $elementId, array $prices): void
	{
		foreach (Catalog\GroupTable::getTypeList() as $priceType)
		{
			$priceTypeId = $priceType['ID'];
			if (!$this->isPriceChanged($prices, $priceTypeId))
			{
				continue;
			}

			if ($priceType['BASE'] === 'Y')
			{
				$basePrice = $this->savePrice($elementId, $priceTypeId, $prices);
				$this->updateExtraPrices($elementId, $priceTypeId, $basePrice, $prices['currency'][$priceTypeId]);
			}
			elseif (!isset($prices['extraId'][$priceTypeId]))
			{
				$this->savePrice($elementId, $priceTypeId, $prices);
			}
		}
	}

	/**
	 * Saves the purchasing price of an offer; an empty price clears it.
	 *
	 * @param int $offerId
	 * @param string $price
	 * @param string $currency
	 * @return void
	 */
	public function savePurchasingPrice(int $offerId, string $price, string $currency): void
	{
		$price = trim($price);
		Catalog\Model\Product::update(
			$offerId,
			[
				'fields' => [
					'PURCHASING_PRICE' => ($price === '' ? null : $price),
					'PURCHASING_CURRENCY' => $currency,
				],
			],
		);
	}

	private function isPriceChanged(array $prices, int|string $priceTypeId): bool
	{
		if (!isset(
			$prices['price'][$priceTypeId],
			$prices['currency'][$priceTypeId],
			$prices['oldPrice'][$priceTypeId],
			$prices['oldCurrency'][$priceTypeId],
		))
		{
			return false;
		}
		// the currency control of an empty cell gets the base currency: an empty price that stays empty is unchanged
		if (trim($prices['price'][$priceTypeId]) === '' && trim($prices['oldPrice'][$priceTypeId]) === '')
		{
			return false;
		}

		// loose comparison as in the former scripts: '1199' and '1199.00' are the same price
		return
			$prices['price'][$priceTypeId] != $prices['oldPrice'][$priceTypeId]
			|| $prices['currency'][$priceTypeId] != $prices['oldCurrency'][$priceTypeId];
	}

	/**
	 * Adds, updates or deletes (empty or negative value) the price. Returns the price, null if it was deleted.
	 */
	private function savePrice(int $elementId, int|string $priceTypeId, array $prices): ?float
	{
		$fields = [
			'PRODUCT_ID' => $elementId,
			'CATALOG_GROUP_ID' => $priceTypeId,
			'PRICE' => $prices['price'][$priceTypeId],
			'CURRENCY' => $prices['currency'][$priceTypeId],
			'QUANTITY_FROM' => $prices['quantityFrom'][$priceTypeId] ?? null,
			'QUANTITY_TO' => $prices['quantityTo'][$priceTypeId] ?? null,
		];
		$deletePrice = false;
		$fields['PRICE'] = str_replace(',', '.', $fields['PRICE']);
		if (trim($fields['PRICE']) === '')
		{
			$deletePrice = true;
		}
		$fields['PRICE'] = (float)$fields['PRICE'];
		if ($fields['PRICE'] < 0)
		{
			$deletePrice = true;
		}

		$priceId = $prices['priceId'][$priceTypeId] ?? null;
		if ($deletePrice)
		{
			\CPrice::Delete($priceId);

			return null;
		}

		if ((int)$priceId > 0)
		{
			\CPrice::Update($priceId, $fields);
		}
		else
		{
			\CPrice::Add($fields);
		}

		return $fields['PRICE'];
	}

	/**
	 * Recalculates prices with a markup from the new base price; detaches them from the markup if the base price
	 * was deleted.
	 */
	private function updateExtraPrices(
		int $elementId,
		int|string $basePriceTypeId,
		?float $basePrice,
		string $currency,
	): void
	{
		$iterator = \CPrice::GetListEx(
			[],
			[
				'PRODUCT_ID' => $elementId,
				'!CATALOG_GROUP_ID' => $basePriceTypeId,
				'+QUANTITY_FROM' => '1',
				'!EXTRA_ID' => false,
			],
			false,
			false,
			[
				'ID',
				'PRODUCT_ID',
				'CATALOG_GROUP_ID',
				'PRICE',
				'CURRENCY',
				'QUANTITY_FROM',
				'QUANTITY_TO',
				'EXTRA_ID',
			],
		);
		while ($row = $iterator->Fetch())
		{
			if ($basePrice === null)
			{
				\CPrice::Update($row['ID'], ['EXTRA_ID' => false]);

				continue;
			}

			$fields = [
				'PRICE' => $basePrice * (1 + ($this->getExtraPercents()[$row['EXTRA_ID']] ?? 0) / 100),
				'EXTRA_ID' => $row['EXTRA_ID'],
				'CURRENCY' => $currency,
			];
			if ($fields['PRICE'] <= 0)
			{
				\CPrice::Delete($row['ID']);
			}
			else
			{
				\CPrice::Update($row['ID'], $fields);
			}
		}
	}

	private function getQuantityBound(mixed $value): ?int
	{
		if (!is_string($value) || $value === '')
		{
			return null;
		}
		$value = (int)$value;

		return $value > 0 ? $value : null;
	}

	private function isBasePriceRequired(): bool
	{
		$this->basePriceRequired ??=
			$this->getBasePriceTypeId() !== null
			&& Option::get('catalog', 'save_product_without_price') !== 'Y';

		return $this->basePriceRequired;
	}

	private function getBasePriceTypeId(): ?int
	{
		$this->basePriceTypeId ??= Catalog\GroupTable::getBasePriceTypeId();

		return $this->basePriceTypeId;
	}

	private function getExtraPercents(): array
	{
		if ($this->extraPercents === null)
		{
			$this->extraPercents = [];
			$iterator = \CExtra::GetList(['ID' => 'ASC']);
			while ($extra = $iterator->Fetch())
			{
				$this->extraPercents[$extra['ID']] = $extra['PERCENTAGE'];
			}
		}

		return $this->extraPercents;
	}
}
