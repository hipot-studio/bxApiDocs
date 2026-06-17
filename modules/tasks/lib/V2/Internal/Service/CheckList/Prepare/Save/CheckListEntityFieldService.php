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

		foreach ($items as $key => $item)
		{
			if (!$this->isBelongsToEntity($item, $entityChecklistIdsMap))
			{
				unset($items[$key]);
			}
		}

		return $items;
	}

	private function isBelongsToEntity(array $item, array $entityChecklistIdsMap): bool
	{
		if (!$this->checkListIdService->isNew($item['id'] ?? null))
		{
			$id = (int)$item['id'];
			if (!isset($entityChecklistIdsMap[$id]))
			{
				return false;
			}
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
