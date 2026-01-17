<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM;

use Bitrix\Booking\Entity\Booking\BookingSkuCollection;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Model\BookingSkuTable;
use Bitrix\Booking\Internals\Model\BookingTable;
use Bitrix\Booking\Internals\Repository\BookingSkuRepositoryInterface;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

class BookingSkuRepository implements BookingSkuRepositoryInterface
{
	public function getQuery(): Query
	{
		return BookingSkuTable::query();
	}

	public function link(int $bookingId, BookingSkuCollection $skuCollection): void
	{
		$data = [];

		foreach ($skuCollection as $sku)
		{
			$props = [
				'BOOKING_ID' => $bookingId,
				'SKU_ID' => $sku->getId(),
			];

			$data[] = $props;
		}

		if (!empty($data))
		{
			$result = BookingSkuTable::addMulti($data, true);
			if (!$result->isSuccess())
			{
				throw new Exception($result->getErrors()[0]->getMessage());
			}
		}
	}

	public function unLink(int $bookingId, BookingSkuCollection $skuCollection): void
	{
		$skuIds = $skuCollection->getEntityIds();
		if (empty($skuIds))
		{
			return;
		}

		BookingSkuTable::deleteByFilter([
			'=BOOKING_ID' => $bookingId,
			'=SKU_ID' => $skuIds,
		]);
	}

	public function checkExistence(array $filter): bool
	{
		if (!isset($filter['SKU_ID']))
		{
			return false;
		}

		$query = BookingSkuTable::query()
			->setSelect(['ID'])
			->registerRuntimeField(
				(new Reference(
					'BOOKING',
					BookingTable::getEntity(),
					Join::on('this.BOOKING_ID', 'ref.ID')
						->where('ref.IS_DELETED', 'N'),
				))->configureJoinType(Join::TYPE_INNER)
			)
		;

		if (is_array($filter['SKU_ID']))
		{
			$query->whereIn('SKU_ID', array_map('intval', $filter['SKU_ID']));
		}
		else
		{
			$query->where('SKU_ID', (int)$filter['SKU_ID']);
		}

		$query->setLimit(1);

		return (bool)$query->fetch();
	}

	/**
	 * @param array $skuProductRowMap [skuId => [productRowId], ...]
	 */
	public function update(int $bookingId, array $skuProductRowMap): void
	{
		foreach ($skuProductRowMap as $skuId => $map)
		{
			BookingSkuTable::updateByFilter(
				[
					'BOOKING_ID' => $bookingId,
					'SKU_ID' => $skuId,
				],
				[
					'PRODUCT_ROW_ID' => $map['productRowId'],
				]
			);
		}
	}

	/**
	 * @param int[] $skuIds
	 * @return int[]
	 */
	public function getUsedIds(array $skuIds): array
	{
		$query = BookingSkuTable::query()
			->setDistinct()
			->setSelect(['SKU_ID'])
			->whereIn('SKU_ID', $skuIds)
		;

		return array_map('intval', array_column($query->fetchAll(), 'SKU_ID'));
	}
}
