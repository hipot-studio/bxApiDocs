<?php

namespace Bitrix\Crm\Import\Strategy\DuplicateControl;

use Bitrix\Crm\EntityAdapter;
use Bitrix\Crm\EntityAdapterFactory;
use Bitrix\Crm\Field;
use Bitrix\Crm\Import\Contract\Strategy\DuplicateControlStrategyInterface;
use Bitrix\Crm\Import\Result\DuplicateControlProcessResult;
use Bitrix\Crm\Integrity\DuplicateChecker;
use Bitrix\Crm\Integrity\DuplicateCheckerFactory;
use Bitrix\Crm\Integrity\DuplicateSearchParams;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;

abstract class AbstractDuplicateControlStrategy implements DuplicateControlStrategyInterface
{
	public function processDuplicateControl(int $entityTypeId, array $fieldNames, array $itemValues): DuplicateControlProcessResult
	{
		$adapter = $this->getAdapter($entityTypeId, $itemValues);
		if ($adapter === null)
		{
			return new DuplicateControlProcessResult(
				isDuplicate: false,
				entityTypeId: $entityTypeId,
				duplicateIds: [],
			);
		}

		$duplicateItems = $this->getDuplicateItems($entityTypeId, $fieldNames, $adapter);
		if (empty($duplicateItems))
		{
			return new DuplicateControlProcessResult(
				isDuplicate: false,
				entityTypeId: $entityTypeId,
				duplicateIds: [],
			);
		}

		/** @var Factory $factory */
		$factory = Container::getInstance()->getFactory($entityTypeId);
		$fieldCollection = $factory->getFieldsCollection();

		foreach ($itemValues as $fieldName => $importValue)
		{
			foreach ($duplicateItems as $duplicateItem)
			{
				$field = $fieldCollection->getField($fieldName);
				if ($field === null || !$field->isValueCanBeChanged())
				{
					continue;
				}

				$this->processDuplicateItem($duplicateItem, $field, $importValue);
			}
		}

		$processResult = new DuplicateControlProcessResult(
			isDuplicate: true,
			entityTypeId: $entityTypeId,
			duplicateIds: array_map(static fn (Item $item) => $item->getId(), $duplicateItems),
		);

		if ($this->isChangeDuplicateItems())
		{
			foreach ($duplicateItems as $duplicateItem)
			{
				$updateResult = $factory
					->getUpdateOperation($duplicateItem)
					->disableAllChecks()
					->launch()
				;

				if (!$updateResult->isSuccess())
				{
					$processResult->addErrors($updateResult->getErrors());
				}
			}
		}

		return $processResult;
	}

	private function getAdapter(int $entityTypeId, array $itemValues): ?EntityAdapter
	{
		return EntityAdapterFactory::create($itemValues, $entityTypeId);
	}

	private function getDuplicateChecker(int $entityTypeId): ?DuplicateChecker
	{
		$duplicateChecker = (new DuplicateCheckerFactory())->create($entityTypeId);
		$duplicateChecker?->setStrictComparison(true);

		return $duplicateChecker;
	}

	/**
	 * @return Item[]
	 */
	private function getDuplicateItems(
		int $entityTypeId,
		array $fieldNames,
		EntityAdapter $adapter,
	): array
	{
		$duplicateChecker = $this->getDuplicateChecker($entityTypeId);
		if ($duplicateChecker === null)
		{
			return [];
		}

		$duplicateSearchParams = new DuplicateSearchParams($fieldNames);
		$duplicateSearchParams->setEntityTypeId($entityTypeId);

		if ($entityTypeId === \CCrmOwnerType::Contact || $entityTypeId === \CCrmOwnerType::Company)
		{
			$duplicateSearchParams->setCategoryId(0);
		}

		$duplicates = $duplicateChecker->findDuplicates($adapter, $duplicateSearchParams);

		$duplicateIds = [];
		foreach ($duplicates as $duplicate)
		{
			$ids = $duplicate->getEntityIDsByType($entityTypeId);
			$duplicateIds = empty($duplicateIds) ? $ids : array_intersect($duplicateIds, $ids);
		}

		if (empty($duplicateIds))
		{
			return [];
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return [];
		}

		return $factory->getItems([
			'filter' => [
				'@ID' => $duplicateIds,
			],
		]);
	}

	abstract protected function processDuplicateItem(Item $duplicateItem, Field $field, mixed $importValue): void;

	protected function isChangeDuplicateItems(): bool
	{
		return true;
	}
}
