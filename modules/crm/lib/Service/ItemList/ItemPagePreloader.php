<?php

namespace Bitrix\Crm\Service\ItemList;

use Bitrix\Crm\Item;
use Bitrix\Crm\Model\FieldContentTypeTable;
use Bitrix\Crm\Model\LastCommunicationTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\UtmTable;

/**
 * Warms the per-hit caches items of a list page read their extra fields through, one query per
 * storage for the whole page instead of one per item.
 *
 * @internal
 */
final class ItemPagePreloader
{
	/**
	 * @param Item[] $items
	 * @param string[]|null $commonFieldNames fields to be serialized, null for all fields
	 */
	public function preload(Factory $factory, array $items, ?array $commonFieldNames, bool $isMultifieldsNeeded): void
	{
		$ids = $this->getStoredItemIds($items);
		if (empty($ids))
		{
			return;
		}

		$entityTypeId = $factory->getEntityTypeId();

		if ($this->isAnyRequested($commonFieldNames, UtmTable::getCodeList()))
		{
			UtmTable::warmupEntityUtmCache($entityTypeId, $ids);
		}

		if ($this->isAnyRequested($commonFieldNames, [LastCommunicationTable::getLastStateFieldName()]))
		{
			LastCommunicationTable::warmupEntityCache($entityTypeId, $ids);
		}

		$flexibleContentTypeFieldNames = $factory->getFlexibleContentTypeFields()->getFieldNameList();
		if (!empty($flexibleContentTypeFieldNames) && $this->isAnyRequested($commonFieldNames, $flexibleContentTypeFieldNames))
		{
			FieldContentTypeTable::loadForMultipleItems($entityTypeId, $ids);
		}

		if ($isMultifieldsNeeded && $factory->isMultiFieldsEnabled())
		{
			Container::getInstance()->getMultifieldStorage()->warmupCache($entityTypeId, $ids);
		}
	}

	/**
	 * @param Item[] $items
	 * @return int[]
	 */
	private function getStoredItemIds(array $items): array
	{
		$ids = [];
		foreach ($items as $item)
		{
			$id = (int)$item->getId();
			if ($id > 0 && !$item->isNew())
			{
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}

	private function isAnyRequested(?array $commonFieldNames, array $sourceFieldNames): bool
	{
		return $commonFieldNames === null || !empty(array_intersect($commonFieldNames, $sourceFieldNames));
	}
}
