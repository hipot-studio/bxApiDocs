<?php

namespace Bitrix\Iblock\Internal\Service\Catalog;

use Bitrix\Catalog;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\Public\Command\Catalog\PriceInput;
use Bitrix\Iblock\Public\Command\Catalog\PurchasingPriceInput;
use Bitrix\Main\Loader;

/**
 * Accepts catalog values of inline editing in the admin element list of an iblock only for their own targets.
 *
 * A row is accepted if its element belongs to the iblock of the list or to the SKU iblock of its catalog. A value
 * is accepted for the row element itself or for an active offer of the row product when product rows show
 * the selected offer.
 */
final class InlineEditTargetFilter
{
	private const PAGE_SIZE = 500;

	private int $listIblockId;
	private bool $useOffers;
	private ?array $editableIblockIds = null;
	private array $rowIblockIds = [];
	private array|false|null $skuInfo = null;
	private array $offerProducts = [];

	/**
	 * @param int $listIblockId Iblock of the list.
	 * @param bool $useOffers Product rows show the selected offer (new product card).
	 */
	public function __construct(int $listIblockId, bool $useOffers)
	{
		$this->listIblockId = $listIblockId;
		$this->useOffers = $useOffers;
	}

	/**
	 * Returns iblocks whose element rows are accepted: the iblock of the list and the SKU iblock of its catalog.
	 *
	 * @return int[]
	 */
	public function getEditableIblockIds(): array
	{
		if ($this->editableIblockIds !== null)
		{
			return $this->editableIblockIds;
		}

		$this->editableIblockIds = [$this->listIblockId];
		if (!Loader::includeModule('catalog'))
		{
			return $this->editableIblockIds;
		}

		$catalog = \CCatalogSku::GetInfoByIBlock($this->listIblockId);
		if (
			!empty($catalog)
			&& (
				$catalog['CATALOG_TYPE'] === \CCatalogSku::TYPE_PRODUCT
				|| $catalog['CATALOG_TYPE'] === \CCatalogSku::TYPE_FULL
			)
		)
		{
			$this->editableIblockIds[] = (int)$catalog['IBLOCK_ID'];
		}

		return $this->editableIblockIds;
	}

	/**
	 * Returns products that have active offers, if product rows show the selected offer.
	 *
	 * @param int[] $productIds
	 * @return int[]
	 */
	public function getProductIdsWithOffers(array $productIds): array
	{
		$skuInfo = $this->getSkuInfo();
		$productIds = array_values(array_unique($productIds));
		if ($skuInfo === null || empty($productIds))
		{
			return [];
		}

		$productField = 'PROPERTY_' . $skuInfo['SKU_PROPERTY_ID'];
		$result = [];
		foreach (array_chunk($productIds, self::PAGE_SIZE) as $pageIds)
		{
			$iterator = \CIBlockElement::GetList(
				[],
				$this->getActiveOfferFilter($skuInfo) + ['=' . $productField => $pageIds],
				[$productField],
			);
			while ($row = $iterator->Fetch())
			{
				if ((int)$row['CNT'] > 0)
				{
					$result[] = (int)$row[$productField . '_VALUE'];
				}
			}
		}

		return $result;
	}

	/**
	 * Returns accepted prices as lists of values: field => target id => price type id => value. Fields are the
	 * properties of PriceInput. Prices of a target are taken from the first row posting them for it. A price is
	 * dropped if its price record belongs to another element or price type.
	 *
	 * @param PriceInput[] $prices
	 * @return array
	 */
	public function filterPrices(array $prices): array
	{
		$result = array_fill_keys(
			[
				'price',
				'currency',
				'oldPrice',
				'oldCurrency',
				'priceId',
				'quantityFrom',
				'quantityTo',
				'extraId',
			],
			[],
		);

		$this->loadRows(array_map(static fn(PriceInput $input): int => $input->rowElementId, $prices));
		$this->loadOfferProducts(array_map(
			static fn(PriceInput $input): array => [$input->rowElementId, $input->targetId],
			$prices,
		));

		$priceOwners = [];
		foreach ($prices as $input)
		{
			if (!$this->isAcceptedTarget($input->rowElementId, $input->targetId))
			{
				continue;
			}
			$priceOwners[$input->targetId] ??= $input->rowElementId;
			if ($priceOwners[$input->targetId] !== $input->rowElementId)
			{
				continue;
			}
			foreach (array_keys($result) as $field)
			{
				if ($input->{$field} !== null)
				{
					$result[$field][$input->targetId][$input->priceTypeId] = $input->{$field};
				}
			}
		}

		return $this->dropForeignPriceIds($result);
	}

	/**
	 * Returns accepted purchasing prices of offers indexed by offer id; the last posted value of an offer wins.
	 *
	 * @param PurchasingPriceInput[] $purchasingPrices
	 * @return PurchasingPriceInput[]
	 */
	public function filterPurchasingPrices(array $purchasingPrices): array
	{
		$this->loadRows(
			array_map(static fn(PurchasingPriceInput $input): int => $input->rowElementId, $purchasingPrices)
		);
		$this->loadOfferProducts(array_map(
			static fn(PurchasingPriceInput $input): array => [$input->rowElementId, $input->offerId],
			$purchasingPrices,
		));

		$result = [];
		foreach ($purchasingPrices as $input)
		{
			if (
				$input->offerId !== $input->rowElementId
				&& $this->isAcceptedTarget($input->rowElementId, $input->offerId)
			)
			{
				$result[$input->offerId] = $input;
			}
		}

		return $result;
	}

	private function isAcceptedTarget(int $rowElementId, int $targetId): bool
	{
		if (!$this->isAcceptedRow($rowElementId))
		{
			return false;
		}

		return $targetId === $rowElementId || ($this->offerProducts[$targetId] ?? 0) === $rowElementId;
	}

	private function isAcceptedRow(int $rowElementId): bool
	{
		$iblockId = $this->rowIblockIds[$rowElementId] ?? 0;

		return $iblockId > 0 && in_array($iblockId, $this->getEditableIblockIds(), true);
	}

	private function loadRows(array $rowIds): void
	{
		$rowIds = array_diff(array_unique($rowIds), array_keys($this->rowIblockIds));
		foreach (array_chunk($rowIds, self::PAGE_SIZE) as $pageIds)
		{
			foreach ($pageIds as $id)
			{
				$this->rowIblockIds[$id] = 0;
			}
			$iterator = ElementTable::getList([
				'select' => [
					'ID',
					'IBLOCK_ID',
				],
				'filter' => [
					'@ID' => $pageIds,
				],
			]);
			while ($row = $iterator->fetch())
			{
				$this->rowIblockIds[(int)$row['ID']] = (int)$row['IBLOCK_ID'];
			}
		}
	}

	/**
	 * Loads the products of the posted offers: only active offers of the SKU iblock of the catalog are found.
	 *
	 * @param array $targets Pairs of row element id and target id.
	 * @return void
	 */
	private function loadOfferProducts(array $targets): void
	{
		$skuInfo = $this->getSkuInfo();
		if ($skuInfo === null)
		{
			return;
		}

		$offerIds = [];
		foreach ($targets as [$rowElementId, $targetId])
		{
			if (
				$targetId !== $rowElementId
				&& !isset($this->offerProducts[$targetId])
				&& $this->isAcceptedRow($rowElementId)
			)
			{
				$offerIds[$targetId] = $targetId;
			}
		}

		$productField = 'PROPERTY_' . $skuInfo['SKU_PROPERTY_ID'];
		foreach (array_chunk(array_values($offerIds), self::PAGE_SIZE) as $pageIds)
		{
			foreach ($pageIds as $id)
			{
				$this->offerProducts[$id] = 0;
			}
			$iterator = \CIBlockElement::GetList(
				['ID' => 'ASC'],
				$this->getActiveOfferFilter($skuInfo) + ['ID' => $pageIds],
				false,
				false,
				[
					'ID',
					$productField,
				],
			);
			while ($row = $iterator->Fetch())
			{
				$this->offerProducts[(int)$row['ID']] = (int)$row[$productField . '_VALUE'];
			}
		}
	}

	/**
	 * Returns the SKU catalog of the list iblock, if product rows show the selected offer.
	 */
	private function getSkuInfo(): ?array
	{
		if ($this->skuInfo === null)
		{
			$this->skuInfo = false;
			if ($this->useOffers && Loader::includeModule('catalog'))
			{
				$this->skuInfo = \CCatalogSku::GetInfoByProductIBlock($this->listIblockId);
			}
		}

		return empty($this->skuInfo) ? null : $this->skuInfo;
	}

	/**
	 * Filter of offers that are shown in product rows, as \CCatalogSku::getOffersList() was called for them.
	 */
	private function getActiveOfferFilter(array $skuInfo): array
	{
		return [
			'IBLOCK_ID' => $skuInfo['IBLOCK_ID'],
			'ACTIVE' => 'Y',
			'ACTIVE_DATE' => 'Y',
			'CHECK_PERMISSIONS' => 'N',
		];
	}

	private function dropForeignPriceIds(array $prices): array
	{
		$priceIds = [];
		foreach ($prices['priceId'] as $targetId => $typeList)
		{
			foreach ($typeList as $priceTypeId => $priceId)
			{
				if ($priceId !== '')
				{
					$priceIds[(int)$priceId][] = [$targetId, $priceTypeId];
				}
			}
		}
		if (empty($priceIds))
		{
			return $prices;
		}

		$existing = [];
		foreach (array_chunk(array_keys($priceIds), self::PAGE_SIZE) as $pageIds)
		{
			$iterator = Catalog\PriceTable::getList([
				'select' => [
					'ID',
					'PRODUCT_ID',
					'CATALOG_GROUP_ID',
				],
				'filter' => [
					'@ID' => $pageIds,
				],
			]);
			while ($row = $iterator->fetch())
			{
				$existing[(int)$row['ID']] = [(int)$row['PRODUCT_ID'], (int)$row['CATALOG_GROUP_ID']];
			}
		}

		foreach ($priceIds as $priceId => $owners)
		{
			foreach ($owners as $owner)
			{
				if (($existing[$priceId] ?? null) === $owner)
				{
					continue;
				}
				[$targetId, $priceTypeId] = $owner;
				// the target stays in the lists: its other prices and the base price check are kept as before
				foreach (array_keys($prices) as $field)
				{
					unset($prices[$field][$targetId][$priceTypeId]);
				}
			}
		}

		return $prices;
	}
}
