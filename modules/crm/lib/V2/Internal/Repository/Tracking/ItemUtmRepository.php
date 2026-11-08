<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Tracking;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\UtmTable;
use Bitrix\Main\SystemException;

/**
 * The UTM tags of items, as a trace hands them over.
 *
 * @internal
 */
final class ItemUtmRepository
{
	private const BATCH_SIZE = 500;

	/**
	 * Gives the items the UTM tags of a trace, each item provided it has none of its own, the way legacy
	 * binding of a trace does: own tags of an item are never mixed with or replaced by the trace ones.
	 *
	 * Which items have tags is read once per type of item, from the table itself: the static cache of
	 * {@see UtmTable::getEntityUtm()} may hold the tags of an item from before the current write.
	 *
	 * @param ItemIdentifier[] $items
	 * @param array<string, mixed> $tags UTM code => value, as the trace stores them
	 * @throws SystemException the storage refused a tag
	 */
	public function addFromTags(array $items, array $tags): void
	{
		$fields = [];
		foreach (array_change_key_case($tags, CASE_UPPER) as $code => $value)
		{
			if (in_array($code, UtmTable::getCodeList(), true) && is_string($value) && $value !== '')
			{
				$fields[$code] = $value;
			}
		}

		if (empty($fields) || empty($items))
		{
			return;
		}

		$withUtm = $this->findItemsWithUtm($items);
		$isAdded = false;
		foreach ($items as $item)
		{
			if (isset($withUtm[$item->getEntityTypeId()][$item->getEntityId()]))
			{
				continue;
			}

			// The item has no tags, so they are inserted as they are, with no read of each one before.
			foreach ($fields as $code => $value)
			{
				$result = UtmTable::add([
					'ENTITY_TYPE_ID' => $item->getEntityTypeId(),
					'ENTITY_ID' => $item->getEntityId(),
					'CODE' => $code,
					'VALUE' => $value,
				]);
				if (!$result->isSuccess())
				{
					throw new SystemException(
						'Could not add the UTM tags: ' . implode('; ', $result->getErrorMessages()),
					);
				}
			}
			$isAdded = true;
		}

		if ($isAdded)
		{
			UtmTable::cleanRuntimeCache();
		}
	}

	/**
	 * @param ItemIdentifier[] $items
	 * @return array<int, array<int, true>> entity type id => entity id => true
	 */
	private function findItemsWithUtm(array $items): array
	{
		$idsByType = [];
		foreach ($items as $item)
		{
			$idsByType[$item->getEntityTypeId()][$item->getEntityId()] = $item->getEntityId();
		}

		$withUtm = [];
		foreach ($idsByType as $entityTypeId => $ids)
		{
			foreach (array_chunk(array_values($ids), self::BATCH_SIZE) as $chunk)
			{
				$rows =
					UtmTable::query()
						->setSelect(['ENTITY_ID'])
						->setDistinct()
						->where('ENTITY_TYPE_ID', $entityTypeId)
						->whereIn('ENTITY_ID', $chunk)
						->fetchAll()
				;
				foreach ($rows as $row)
				{
					$withUtm[$entityTypeId][(int)$row['ENTITY_ID']] = true;
				}
			}
		}

		return $withUtm;
	}
}
