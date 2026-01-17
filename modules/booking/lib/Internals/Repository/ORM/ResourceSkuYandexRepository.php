<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM;

use Bitrix\Booking\Entity\Resource\ResourceSkuCollection;

use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Exception\InvalidArgumentException;
use Bitrix\Booking\Internals\Model\ResourceSkuYandexTable;
use Bitrix\Main\Application;

class ResourceSkuYandexRepository
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

		$result = ResourceSkuYandexTable::addMulti($data, true);
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

		ResourceSkuYandexTable::deleteByFilter([
			'=RESOURCE_ID' => $resourceId,
			'=SKU_ID' => $skuIds,
		]);
	}

	/**
	 * @param array $links
	 * @return void
	 * @throws InvalidArgumentException
	 */
	public function linkByArray(array $links): void
	{
		$data = [];

		foreach ($links as $link)
		{
			if (!isset($link['RESOURCE_ID']) || !isset($link['SKU_ID']))
			{
				throw new InvalidArgumentException();
			}

			$data[] = [
				'RESOURCE_ID' => (int)$link['RESOURCE_ID'],
				'SKU_ID' => (int)$link['SKU_ID'],
			];
		}

		if (empty($data))
		{
			return;
		}

		$result = ResourceSkuYandexTable::addMulti($data, true);
		if (!$result->isSuccess())
		{
			throw new Exception($result->getErrors()[0]->getMessage());
		}
	}

	public function unlinkAll(): void
	{
		Application::getConnection()->truncateTable(ResourceSkuYandexTable::getTableName());
	}

	public function isEmpty(): bool
	{
		$row = ResourceSkuYandexTable::query()
			->setSelect(['ID'])
			->setLimit(1)
			->fetch()
		;

		return !$row;
	}

	/**
	 * @param int[] $skuIds
	 * @return int[]
	 */
	public function getUsedIds(array $skuIds): array
	{
		$query = ResourceSkuYandexTable::query()
			->setDistinct()
			->setSelect(['SKU_ID'])
			->whereIn('SKU_ID', $skuIds)
		;

		return array_map('intval', array_column($query->fetchAll(), 'SKU_ID'));
	}
}
