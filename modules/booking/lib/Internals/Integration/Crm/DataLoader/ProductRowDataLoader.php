<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm\DataLoader;

use Bitrix\Booking\Entity\Booking\BookingSku;
use Bitrix\Booking\Entity\Booking\BookingSkuCollection;
use Bitrix\Main\Loader;

class ProductRowDataLoader
{
	public function loadForCollection(BookingSkuCollection ...$collections): void
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		$notEmptyCollections = array_filter(
			$collections,
			static function (BookingSkuCollection $collection) {
				if ($collection->isEmpty())
				{
					return false;
				}

				/** @var BookingSku $bookingSku */
				foreach ($collection->getCollectionItems() as $bookingSku)
				{
					if ($bookingSku->getProductRowId())
					{
						return true;
					}
				}

				return false;
			},
		);

		if (empty($notEmptyCollections))
		{
			return;
		}

		$productRowIds = $this->extractProductIds(...$notEmptyCollections);
		$productRows = $this->getProductRows($productRowIds);

		$this->fillSkuData($notEmptyCollections, $productRows);
	}

	private function getProductRows(array $productRowIds): array
	{
		$productRowsQuery = \CCrmProductRow::GetList(
			arFilter: [
				'ID' => $productRowIds,
			],
			arSelectFields: ['ID', 'PRICE'],
		);
		$productRows = [];
		while ($productRow = $productRowsQuery->Fetch())
		{
			$productRows[$productRow['ID']] = $productRow;
		}

		return $productRows;
	}

	private function extractProductIds(BookingSkuCollection ...$collections): array
	{
		$productIds = [];
		foreach ($collections as $collection)
		{
			foreach ($collection as $bookingSku)
			{
				$productIds[] = $bookingSku->getProductRowId();
			}
		}

		return $productIds;
	}

	/** @var BookingSkuCollection[] $collections */
	private function fillSkuData(array $collections, array $productRows): void
	{
		foreach ($collections as $collection)
		{
			if ($collection->isEmpty())
			{
				continue;
			}

			foreach ($collection as $bookingSku)
			{
				$productRowId = $bookingSku->getProductRowId();
				if (!$productRowId || !isset($productRows[$productRowId]))
				{
					continue;
				}

				$productRow = $productRows[$productRowId];
				if (!isset($productRow['PRICE']))
				{
					continue;
				}

				$bookingSku->setPrice((float)$productRow['PRICE']);
				if (!$bookingSku->getCurrencyId())
				{
					$bookingSku->setCurrencyId(\CCrmCurrency::GetBaseCurrencyID());
				}
			}
		}
	}
}
