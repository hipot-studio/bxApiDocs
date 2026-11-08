<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList\Prepare\Save;

use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\Tasks\V2\Internal\Repository\CheckListRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\CheckList\CheckListIdService;
use Bitrix\Tasks\V2\Internal\Service\TariffService;

class CheckListEntityFieldService
{
	public function __construct(
		private readonly TariffService $tariffService,
		private readonly CheckListRepositoryInterface $checkListRepository,
		private readonly CheckListIdService $checkListIdService,
	)
	{

	}

	public function prepare(array $items, int $entityId, Type $type): array
	{
		if (empty($items))
		{
			return $items;
		}

		if (!$this->tariffService->isStakeholderAvailable())
		{
			$items = $this->removeStakeholders($items);
		}

		return $this->filterBelongsToEntity($items, $entityId, $type);
	}

	private function removeStakeholders(array $items): array
	{
		foreach ($items as &$item)
		{
			$accompliceNames = array_column($item['accomplices'] ?? [], 'name');
			$auditorNames = array_column($item['auditors'] ?? [], 'name');

			$names = array_merge($accompliceNames, $auditorNames);

			$title = trim(str_replace($names, '', $item['title']));

			if (!empty($title))
			{
				$item['title'] = $title;
			}

			unset($item['accomplices'], $item['auditors']);
		}

		return $items;
	}

	private function filterBelongsToEntity(array $items, int $entityId, Type $type): array
	{
		$entityChecklistIdsMap = $this->getEntityChecklistIdsMap($entityId, $type);
		$foreignIdsMap = $this->getForeignIdsMap($items, $entityChecklistIdsMap, $type);

		$detachedIdToNodeId = [];
		foreach ($items as $key => $item)
		{
			$id = $item['id'] ?? null;

			if (
				!$this->checkListIdService->isNew($id)
				&& !isset($entityChecklistIdsMap[(int)$id])
				&& !isset($foreignIdsMap[(int)$id])
			)
			{
				$detachedIdToNodeId[(int)$id] = $item['nodeId'] ?? null;
				$items[$key]['id'] = null;
			}
		}

		foreach ($items as $key => $item)
		{
			$parentId = $item['parentId'] ?? null;

			if (
				!$this->checkListIdService->isNew($parentId)
				&& array_key_exists((int)$parentId, $detachedIdToNodeId)
			)
			{
				$items[$key]['parentId'] = $detachedIdToNodeId[(int)$parentId];
			}
		}

		foreach ($items as $key => $item)
		{
			if (!$this->belongsToEntity($items[$key], $entityChecklistIdsMap))
			{
				unset($items[$key]);
			}
		}

		return $items;
	}

	private function getForeignIdsMap(array $items, array $entityChecklistIdsMap, Type $type): array
	{
		$detachedIds = [];
		foreach ($items as $item)
		{
			$id = $item['id'] ?? null;

			if (
				!$this->checkListIdService->isNew($id)
				&& !isset($entityChecklistIdsMap[(int)$id])
			)
			{
				$detachedIds[] = (int)$id;
			}
		}

		if (empty($detachedIds))
		{
			return [];
		}

		return array_flip($this->checkListRepository->filterExistingIds($detachedIds, $type));
	}

	private function belongsToEntity(array $item, array $entityChecklistIdsMap): bool
	{
		$id = $item['id'] ?? null;

		if (
			!$this->checkListIdService->isNew($id)
			&& !isset($entityChecklistIdsMap[(int)$id])
		)
		{
			return false;
		}

		return $this->isParentBelongsToEntity($item, $entityChecklistIdsMap);
	}

	private function isParentBelongsToEntity(array $item, array $entityChecklistIdsMap): bool
	{
		$parentId = $this->checkListIdService->resolveId($item['parentId'] ?? null);

		if ($parentId === null)
		{
			return true;
		}

		return isset($entityChecklistIdsMap[$parentId]);
	}

	private function getEntityChecklistIdsMap(int $entityId, Type $type): array
	{
		return array_flip($this->checkListRepository->getIdsByEntity($entityId, $type));
	}

}
