<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Service;

use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\CheckListItem;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\Tasks\V2\Internal\Repository\CheckListEntityRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\CheckListRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\CheckListMapper;

class CheckListAccessService
{
	public function __construct(
		private readonly TaskAccessService $taskAccessService,
		private readonly CheckListEntityRepositoryInterface $checkListEntityRepository,
		private readonly CheckListRepositoryInterface $checkListRepository,
		private readonly CheckListMapper $checkListMapper,
	)
	{
	}

	public function canSave(int $userId, CheckListItem $item): bool
	{
		$checkListId = (int)$item->getId();

		$taskId = $this->checkListEntityRepository->getIdByCheckListId($checkListId, Type::Task);

		$existingCheckList = $this->checkListRepository->getByEntity($taskId, Type::Task);

		$itemsData = [];

		foreach ($existingCheckList as $existingItem)
		{
			$itemData = $existingItem->toArray();

			if ($existingItem->getId() === $checkListId)
			{
				foreach ($item->toArray() as $field => $value)
				{
					if ($value !== null)
					{
						$itemData[$field] = $value;
					}
				}
			}

			$itemsData[] = $itemData;
		}

		$nodes = $this->checkListMapper->mapToNodes($itemsData);

		return $this->taskAccessService->can(
			$userId,
			ActionDictionary::ACTION_CHECKLIST_SAVE,
			$taskId,
			array_values($nodes->toArray()),
		);
	}

	public function canAdd(int $userId, int $taskId, array $params = []): bool
	{
		return $this->taskAccessService->can($userId, ActionDictionary::ACTION_CHECKLIST_ADD, $taskId, $params);
	}

	public function canUpdate(int $userId, int $checkListId, array $params = []): bool
	{
		$taskId = $this->checkListEntityRepository->getIdByCheckListId($checkListId, Type::Task);

		return $this->taskAccessService->can($userId, ActionDictionary::ACTION_CHECKLIST_EDIT, $taskId, $params);
	}

	public function canDelete(int $userId, int $checkListId): bool
	{
		return $this->canUpdate($userId, $checkListId);
	}
}
