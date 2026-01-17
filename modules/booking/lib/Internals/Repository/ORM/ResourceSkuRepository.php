<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM;

use Bitrix\Booking\Entity\Resource\ResourceSkuCollection;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Model\ResourceDataTable;
use Bitrix\Booking\Internals\Model\ResourceSkuTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

class ResourceSkuRepository
{
	public function link(int $resourceId, ResourceSkuCollection $skus): void
	{
		$data = [];

		foreach ($skus as $sku)
		{
			$data[] = [
				'RESOURCE_ID' => $resourceId,
				'SKU_ID' => $sku->getId(),
			];
		}

		if (empty($data))
		{
			return;
		}

		$result = ResourceSkuTable::addMulti($data, true);
		if (!$result->isSuccess())
		{
			throw new Exception($result->getErrors()[0]->getMessage());
		}
	}

	public function unlink(int $resourceId, ResourceSkuCollection $skus): void
	{
		$skuIds = $skus->getEntityIds();
		if (empty($skuIds))
		{
			return;
		}

		ResourceSkuTable::deleteByFilter([
			'=RESOURCE_ID' => $resourceId,
			'=SKU_ID' => $skuIds,
		]);
	}

	public function checkExistence(array $filter): bool
	{
		if (!isset($filter['SKU_ID']))
		{
			return false;
		}

		$query = ResourceSkuTable::query()
			->setSelect(['ID'])
			->registerRuntimeField(
				(new Reference(
					'RESOURCE_DATA',
					ResourceDataTable::getEntity(),
					Join::on('this.RESOURCE_ID', 'ref.RESOURCE_ID')
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
	 * @param int[] $skuIds
	 * @return int[]
	 */
	public function getUsedIds(array $skuIds): array
	{
		$query = ResourceSkuTable::query()
			->setDistinct()
			->setSelect(['SKU_ID'])
			->whereIn('SKU_ID', $skuIds)
		;

		return array_map('intval', array_column($query->fetchAll(), 'SKU_ID'));
	}
}
