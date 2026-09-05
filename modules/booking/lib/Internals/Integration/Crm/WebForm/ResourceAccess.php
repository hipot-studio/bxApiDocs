<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm\WebForm;

class ResourceAccess
{
	private const MAX_RESOURCE_IDS = 1000;
	private const MAX_SKU_IDS_PER_RESOURCE = 1000;

	/** @var array<int, true> */
	private readonly array $resourceIdMap;

	/** @var array<int, array<int, true>> */
	private readonly array $skuIdMapByResourceId;

	/**
	 * @param int[] $resourceIds
	 * @param array<int, int[]> $skuIdsByResourceId
	 */
	public function __construct(array $resourceIds, array $skuIdsByResourceId = [])
	{
		$this->resourceIdMap = $this->createIdMap($resourceIds, self::MAX_RESOURCE_IDS);

		$skuIdMapByResourceId = [];
		foreach ($skuIdsByResourceId as $resourceId => $skuIds)
		{
			$resourceId = (int)$resourceId;
			if (!isset($this->resourceIdMap[$resourceId]))
			{
				continue;
			}

			$skuIdMapByResourceId[$resourceId] = $this->createIdMap($skuIds, self::MAX_SKU_IDS_PER_RESOURCE);
		}

		$this->skuIdMapByResourceId = $skuIdMapByResourceId;
	}

	/**
	 * @return int[]
	 */
	public function getResourceIds(): array
	{
		return array_keys($this->resourceIdMap);
	}

	public function isResourceAllowed(int $resourceId): bool
	{
		return isset($this->resourceIdMap[$resourceId]);
	}

	/**
	 * @param mixed[] $resourceIds
	 * @return int[]
	 */
	public function filterResourceIds(array $resourceIds): array
	{
		$result = [];
		foreach (array_map('intval', array_slice($resourceIds, 0, self::MAX_RESOURCE_IDS)) as $resourceId)
		{
			if (isset($this->resourceIdMap[$resourceId]))
			{
				$result[$resourceId] = $resourceId;
			}
		}

		return array_values($result);
	}

	/**
	 * @param mixed[] $resources
	 * @return array<int, array{id: int, skus: int[]}>
	 */
	public function filterResourcesWithSkus(array $resources): array
	{
		$result = [];
		foreach (array_slice($resources, 0, self::MAX_RESOURCE_IDS) as $resource)
		{
			if (!is_array($resource))
			{
				continue;
			}

			$resourceId = (int)($resource['id'] ?? 0);
			$allowedSkuIdMap = $this->skuIdMapByResourceId[$resourceId] ?? [];
			$skuIds = is_array($resource['skus'] ?? null) ? $resource['skus'] : [];

			$allowedSkuIds = [];
			foreach (array_map('intval', array_slice($skuIds, 0, self::MAX_SKU_IDS_PER_RESOURCE)) as $skuId)
			{
				if (isset($allowedSkuIdMap[$skuId]))
				{
					$allowedSkuIds[$skuId] = $skuId;
				}
			}

			if (!empty($allowedSkuIds))
			{
				$result[$resourceId] = [
					'id' => $resourceId,
					'skus' => array_values($allowedSkuIds),
				];
			}
		}

		return array_values($result);
	}

	/**
	 * @param mixed[] $ids
	 * @return array<int, true>
	 */
	private function createIdMap(array $ids, int $limit): array
	{
		$ids = array_filter(
			array_map('intval', array_slice($ids, 0, $limit)),
			static fn (int $id): bool => $id > 0,
		);

		return array_fill_keys($ids, true);
	}
}
