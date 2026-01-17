<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\CheckList\Internals\CheckList;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Public\Provider\CheckListProvider;

abstract class BaseCheckListService
{
	public function __construct(
		protected readonly CheckListProvider $checkListProvider,
		protected readonly CheckListFacadeResolver $checkListFacadeResolver,
		protected readonly Logger $logger,
	)
	{
	}

	abstract protected function getEntityType(): Type;
	abstract protected function getEntity(int $entityId): ?Entity\AbstractEntity;

	protected function changeItemsStatus(array $ids, int $userId, bool $isComplete): array
	{
		Collection::normalizeArrayValuesByInt($ids, false);
		if (empty($ids))
		{
			throw new ArgumentException('IDs are required');
		}

		$facade = $this->checkListFacadeResolver->resolveByType($this->getEntityType());

		$itemsFields = $facade::getList([], ['ID' => $ids]);
		if (empty($itemsFields))
		{
			throw new ArgumentException('No checklist items found for the provided IDs');
		}

		$entityIds = array_column($itemsFields, 'ENTITY_ID');
		Collection::normalizeArrayValuesByInt($entityIds);
		if (count(array_unique($entityIds)) !== 1)
		{
			throw new ArgumentException('All checklist items must belong to the same entity');
		}

		$entityId = (int)array_shift($entityIds);

		$entity = $this->getEntity($entityId);
		if ($entity === null)
		{
			throw new ArgumentException('Entity not found');
		}

		$existingCheckList = $this->checkListProvider->getByEntity(
			entityId: $entityId,
			userId: $userId,
			type: $this->getEntityType(),
		);

		$entityBeforeUpdate = $entity->cloneWith(['checklist' => $existingCheckList->toArray()]);

		$changedIds = [];
		foreach ($itemsFields as $id => $itemFields)
		{
			$item = new CheckList(0, $userId, $facade, $itemFields);

			$result =
				$isComplete
					? $facade::complete($entityId, $userId, $item)
					: $facade::renew($entityId, $userId, $item)
			;

			if (!$result->isSuccess())
			{
				$this->logger->logWarning($result->getErrors());
				continue;
			}

			$changedIds[] = $id;
		}

		$changedItems = $existingCheckList->filter(
			fn (Entity\CheckList\CheckListItem $item): bool => in_array($item->getId(), $changedIds, true)
		);
		$changedItems = $changedItems->cloneWith(['isComplete' => $isComplete]);

		$newCheckList = clone $existingCheckList;
		$newCheckList->replaceMulti($changedItems);

		return [$newCheckList, $entityBeforeUpdate];
	}
}
