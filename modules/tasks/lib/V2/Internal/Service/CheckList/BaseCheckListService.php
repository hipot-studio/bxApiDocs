<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\CheckList\Internals\CheckList;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Service\CheckList\Result\CheckListStatusResult;
use Bitrix\Tasks\V2\Public\Provider\CheckListProvider;

abstract class BaseCheckListService
{
	private const ERROR_NO_ITEMS = 'No checklist items found for the provided IDs';
	private const ERROR_STATUS_UPDATE_FAILED = 'Checklist item status was not persisted';
	private const ERROR_STATUS_UPDATE_FAILED_CODE = 'CHECKLIST_STATUS_UPDATE_FAILED';

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
		$result = $this->applyItemsStatus($ids, $userId, $isComplete);

		return [$result->getCheckList(), $result->getEntityBeforeUpdate()];
	}

	/**
	 * Applies the status to every requested item, skipping and logging the ones the facade rejects.
	 */
	protected function applyItemsStatus(array $ids, int $userId, bool $isComplete): CheckListStatusResult
	{
		$ids = $this->normalizeIds($ids);
		$facade = $this->checkListFacadeResolver->resolveByType($this->getEntityType());
		$itemsFieldsById = $this->getItemsFieldsById($facade, $ids);
		if (empty($itemsFieldsById))
		{
			throw new ArgumentException(self::ERROR_NO_ITEMS);
		}

		return $this->applyStatusToItems(
			facade: $facade,
			ids: $ids,
			itemsFieldsById: $itemsFieldsById,
			userId: $userId,
			isComplete: $isComplete,
			existingCheckList: null,
			stopOnError: false,
		);
	}

	/**
	 * Stops at the first item the facade rejects and reports the failure through the result.
	 * Items missing from storage are tolerated only while $existingCheckList still knows them.
	 *
	 * @param class-string<\Bitrix\Tasks\CheckList\CheckListFacade> $facade
	 */
	protected function applyItemsStatusStrict(
		string $facade,
		array $ids,
		int $userId,
		bool $isComplete,
		int $entityId,
		array $entityItemsFields,
		?Entity\CheckList $existingCheckList = null,
		bool $useBatch = true,
	): CheckListStatusResult
	{
		$ids = $this->normalizeIds($ids);
		$itemsFieldsById = $this->pickItemsFieldsByIds($entityItemsFields, $ids);

		$missingIds = array_diff($ids, array_keys($itemsFieldsById));
		if (!empty($missingIds) && !$this->containsAllIds($existingCheckList, $missingIds))
		{
			throw new ArgumentException(self::ERROR_NO_ITEMS);
		}

		if (empty($itemsFieldsById))
		{
			return new CheckListStatusResult();
		}

		return $this->applyStatusToItems(
			facade: $facade,
			ids: $ids,
			itemsFieldsById: $itemsFieldsById,
			userId: $userId,
			isComplete: $isComplete,
			existingCheckList: $existingCheckList,
			stopOnError: true,
			expectedEntityId: $entityId,
			useBatch: $useBatch,
		);
	}

	private function normalizeIds(array $ids): array
	{
		Collection::normalizeArrayValuesByInt($ids, false);
		if (empty($ids))
		{
			throw new ArgumentException('IDs are required');
		}

		return $ids;
	}

	/**
	 * @param class-string<\Bitrix\Tasks\CheckList\CheckListFacade> $facade
	 */
	private function getItemsFieldsById(string $facade, array $ids): array
	{
		return $this->keyItemsFieldsById($facade::getList([], ['ID' => $ids]));
	}

	private function pickItemsFieldsByIds(array $entityItemsFields, array $ids): array
	{
		return array_intersect_key($this->keyItemsFieldsById($entityItemsFields), array_flip($ids));
	}

	private function keyItemsFieldsById(array $itemsFields): array
	{
		$itemsFieldsById = [];
		foreach ($itemsFields as $id => $itemFields)
		{
			$itemId = (int)($itemFields['ID'] ?? $id);
			$itemsFieldsById[$itemId] = $itemFields;
		}

		return $itemsFieldsById;
	}

	/**
	 * @param class-string<\Bitrix\Tasks\CheckList\CheckListFacade> $facade
	 */
	private function applyStatusToItems(
		string $facade,
		array $ids,
		array $itemsFieldsById,
		int $userId,
		bool $isComplete,
		?Entity\CheckList $existingCheckList,
		bool $stopOnError,
		?int $expectedEntityId = null,
		bool $useBatch = true,
	): CheckListStatusResult
	{
		$entityId = $this->resolveEntityId($itemsFieldsById);
		if ($expectedEntityId !== null && $entityId !== $expectedEntityId)
		{
			throw new ArgumentException('Checklist items belong to another entity');
		}

		$entity = $this->getEntity($entityId);
		if ($entity === null)
		{
			throw new ArgumentException('Entity not found');
		}

		$existingCheckList ??= $this->checkListProvider->getByEntity(
			entityId: $entityId,
			userId: $userId,
			type: $this->getEntityType(),
		);

		$entityBeforeUpdate = $entity->cloneWith(['checklist' => $existingCheckList->toArray()]);

		$result = new CheckListStatusResult();
		$changedIds = [];
		$itemsToChange = [];
		foreach ($ids as $id)
		{
			$itemFields = $itemsFieldsById[$id] ?? null;
			if ($itemFields === null || $this->hasStatus($itemFields, $isComplete))
			{
				continue;
			}

			$itemsToChange[$id] = $itemFields;
		}

		if (
			$useBatch
			&& $stopOnError
			&& $isComplete
			&& $facade === TaskCheckListCompletionFacade::class
			&& !empty($itemsToChange)
		)
		{
			$operationResult = $facade::completeBatch($entityId, $userId, $itemsToChange);
			if ($operationResult->isSuccess())
			{
				$changedIds = array_keys($itemsToChange);
			}
			else
			{
				$result->addErrors($operationResult->getErrors());
			}
		}
		else
		{
			foreach ($itemsToChange as $id => $itemFields)
			{
				$item = new CheckList(0, $userId, $facade, $itemFields);

				$operationResult
					= $isComplete
						? $facade::complete($entityId, $userId, $item)
						: $facade::renew($entityId, $userId, $item);

				if (!$operationResult->isSuccess())
				{
					if ($stopOnError)
					{
						$result->addErrors($operationResult->getErrors()->toArray());

						break;
					}

					$this->logger->logWarning($operationResult->getErrors());

					continue;
				}

				if ($stopOnError && !$useBatch && $this->getPersistedStatusIds($facade, [$id], $isComplete) !== [$id])
				{
					$result->addError(new Error(
						self::ERROR_STATUS_UPDATE_FAILED,
						self::ERROR_STATUS_UPDATE_FAILED_CODE,
					));

					break;
				}

				$changedIds[] = $id;
			}
		}

		if ($stopOnError && $useBatch && !empty($changedIds))
		{
			$persistedIds = $this->getPersistedStatusIds($facade, $changedIds, $isComplete);
			if (count($persistedIds) !== count($changedIds))
			{
				$result->addError(new Error(
					self::ERROR_STATUS_UPDATE_FAILED,
					self::ERROR_STATUS_UPDATE_FAILED_CODE,
				));
				$changedIds = $persistedIds;
			}
		}

		return $result->setChange(
			$changedIds,
			$this->cloneWithChangedItems($existingCheckList, $changedIds, $isComplete),
			$entityBeforeUpdate,
		);
	}

	private function resolveEntityId(array $itemsFieldsById): int
	{
		$entityIds = array_column($itemsFieldsById, 'ENTITY_ID');
		Collection::normalizeArrayValuesByInt($entityIds);
		if (count(array_unique($entityIds)) !== 1)
		{
			throw new ArgumentException('All checklist items must belong to the same entity');
		}

		return (int)array_shift($entityIds);
	}

	private function hasStatus(array $itemFields, bool $isComplete): bool
	{
		if (!array_key_exists('IS_COMPLETE', $itemFields))
		{
			return false;
		}

		return in_array($itemFields['IS_COMPLETE'], ['Y', true], true) === $isComplete;
	}

	/**
	 * @param class-string<\Bitrix\Tasks\CheckList\CheckListFacade> $facade
	 */
	private function getPersistedStatusIds(
		string $facade,
		array $changedIds,
		bool $isComplete,
	): array
	{
		$persistedItems = $this->keyItemsFieldsById(
			$facade::getList(['ID', 'IS_COMPLETE'], ['ID' => $changedIds]),
		);

		return array_values(array_filter(
			$changedIds,
			fn (int $id): bool => isset($persistedItems[$id])
				&& $this->hasStatus($persistedItems[$id], $isComplete),
		));
	}

	private function cloneWithChangedItems(
		Entity\CheckList $existingCheckList,
		array $changedIds,
		bool $isComplete,
	): Entity\CheckList
	{
		$changedIdsMap = array_fill_keys($changedIds, true);
		$changedItems = $existingCheckList->filter(
			fn (Entity\CheckList\CheckListItem $item): bool => isset($changedIdsMap[$item->getId()]),
		);
		$changedItems = $changedItems->cloneWith(['isComplete' => $isComplete]);

		$newCheckList = clone $existingCheckList;
		$newCheckList->replaceMulti($changedItems);

		return $newCheckList;
	}

	private function containsAllIds(?Entity\CheckList $checkList, array $ids): bool
	{
		if ($checkList === null)
		{
			return false;
		}

		$missingIds = array_fill_keys($ids, true);
		/** @var Entity\CheckList\CheckListItem $item */
		foreach ($checkList as $item)
		{
			unset($missingIds[$item->getId()]);
		}

		return empty($missingIds);
	}
}
