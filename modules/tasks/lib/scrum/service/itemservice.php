<?php
namespace Bitrix\Tasks\Scrum\Service;

use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Entity\Query\Join;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Error;
use Bitrix\Main\Errorable;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query;
use Bitrix\Main\Result;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Tasks\Scrum\Form\ItemForm;
use Bitrix\Tasks\Scrum\Form\ItemInfo;
use Bitrix\Tasks\Scrum\Internal\EntityTable;
use Bitrix\Tasks\Scrum\Internal\ItemTable;
use Bitrix\Tasks\V2\Internal\DI\Container;

class ItemService implements Errorable
{
	const ERROR_COULD_NOT_ADD_ITEM = 'TASKS_IS_01';
	const ERROR_COULD_NOT_UPDATE_ITEM = 'TASKS_IS_02';
	const ERROR_COULD_NOT_READ_ITEM = 'TASKS_IS_03';
	const ERROR_COULD_NOT_REMOVE_ITEM = 'TASKS_IS_04';
	const ERROR_COULD_NOT_MOVE_ITEM = 'TASKS_IS_09';
	const ERROR_COULD_NOT_READ_ITEM_INFO = 'TASKS_IS_11';
	const ERROR_COULD_NOT_UPDATE_ITEMS_ENTITY = 'TASKS_IS_12';
	const ERROR_COULD_NOT_READ_ALL_ITEMS = 'TASKS_IS_13';
	const ERROR_COULD_NOT_READ_ITEM_BY_TYPE_ID = 'TASKS_IS_14';
	const ERROR_COULD_NOT_CLEAN_ITEMS_TYPE_ID = 'TASKS_IS_15';
	const ERROR_COULD_NOT_GET_LIST = 'ITEM_LIST_01';

	private $errorCollection;

	private static $taskIdsByEpicId = [];

	private $userId;

	public function __construct(int $userId = 0)
	{
		$this->userId = $userId;
		$this->errorCollection = new ErrorCollection;
	}

	public function createTaskItem(ItemForm $item, PushService $pushService = null): ItemForm
	{
		try
		{
			$result = ItemTable::add($item->getFieldsToCreateTaskItem());

			if ($result->isSuccess())
			{
				$item->setId($result->getId());

				if ($pushService)
				{
					$pushService->sendAddItemEvent($item);
				}
			}
			else
			{
				$this->setErrors($result, self::ERROR_COULD_NOT_ADD_ITEM);
			}

			return $item;
		}
		catch (\Exception $exception)
		{
			$this->errorCollection->setError(
				new Error($exception->getMessage(), self::ERROR_COULD_NOT_ADD_ITEM)
			);
		}

		return $item;
	}

	/**
	 * @param PageNavigation $nav
	 * @param array $filter
	 * @param array $select
	 * @param array $order
	 * @return \Bitrix\Main\ORM\Query\Result|null
	 * @throws \Bitrix\Main\LoaderException
	 */
	public function getList(
		PageNavigation $nav,
		$filter = [],
		$select = [],
		$order = []
	): ?Query\Result
	{
		try
		{
			if (!Loader::includeModule('socialnetwork'))
			{
				$this->errorCollection->setError(
					new Error(
						'Unable to load socialnetwork.',
						self::ERROR_COULD_NOT_GET_LIST
					)
				);

				return null;
			}

			$query = new Query\Query(ItemTable::getEntity());

			if (empty($select))
			{
				$select = ['*'];
			}
			$query->setSelect($select);
			$query->setFilter($filter);
			$query->setOrder($order);

			if ($nav)
			{
				$query->setOffset($nav->getOffset());
				$query->setLimit($nav->getLimit() + 1);
			}

			$query->registerRuntimeField(
				'SE',
				new ReferenceField(
					'SE',
					EntityTable::getEntity(),
					Join::on('this.ENTITY_ID', 'ref.ID'),
					['join_type' => 'inner']
				)
			);

			$query->registerRuntimeField(
				'UG',
				new ReferenceField(
					'UG',
					UserToGroupTable::getEntity(),
					Join::on('this.SE.GROUP_ID', 'ref.GROUP_ID')->where('ref.USER_ID', $this->userId),
					['join_type' => 'inner']
				)
			);

			$queryResult = $query->exec();

			return $queryResult;
		}
		catch (\Exception $e)
		{
			$this->errorCollection->setError(
				new Error(
					$e->getMessage(),
					self::ERROR_COULD_NOT_GET_LIST
				)
			);

			return null;
		}
	}

	public function getTaskIdsByEpicId(int $epicId): array
	{
		if (isset(self::$taskIdsByEpicId[$epicId]))
		{
			return self::$taskIdsByEpicId[$epicId];
		}

		self::$taskIdsByEpicId[$epicId] = [];

		$queryObject = ItemTable::getList([
			'select' => ['SOURCE_ID'],
			'filter' => [
				'EPIC_ID' => $epicId,
				'ACTIVE' => 'Y'
			],
			'order' => ['ID']
		]);
		while ($itemData = $queryObject->fetch())
		{
			self::$taskIdsByEpicId[$epicId][] = $itemData['SOURCE_ID'];
		}

		return self::$taskIdsByEpicId[$epicId];
	}

	public function getItemById(int $itemId): ItemForm
	{
		$item = new ItemForm();

		if (!$itemId)
		{
			return $item;
		}

		$queryObject = ItemTable::getList([
			'filter' => ['ID' => $itemId],
			'order' => ['ID']
		]);
		if ($itemData = $queryObject->fetch())
		{
			$item->fillFromDatabase($itemData);
		}

		return $item;
	}

	/**
	 * @param array $itemIds Item ids.
	 * @return ItemForm[]
	 */
	public function getItemsByIds(array $itemIds): array
	{
		if (empty($itemIds))
		{
			return [];
		}

		try
		{
			$items = [];

			$queryObject = ItemTable::getList([
				'filter' => ['ID' => $itemIds],
				'order' => ['SORT_FLOAT' => 'ASC', 'ID' => 'DESC'],
			]);
			while ($itemData = $queryObject->fetch())
			{
				$item = new ItemForm();

				$item->fillFromDatabase($itemData);

				$items[$item->getId()] = $item;
			}

			return $items;
		}
		catch (\Exception $exception)
		{
			$this->errorCollection->setError(
				new Error($exception->getMessage(), self::ERROR_COULD_NOT_READ_ALL_ITEMS)
			);

			return [];
		}
	}

	/**
	 * @param array $sourceIds
	 * @return ItemForm[]
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getItemsBySourceIds(array $sourceIds): array
	{
		if (empty($sourceIds))
		{
			return [];
		}

		$items = [];

		$queryObject = ItemTable::getList([
			'filter' => ['SOURCE_ID' => $sourceIds]
		]);
		while ($itemData = $queryObject->fetch())
		{
			$itemForm = new ItemForm();

			$itemForm->fillFromDatabase($itemData);

			$items[] = $itemForm;
		}

		return $items;
	}

	public function getItemsStoryPointsBySourceId(array $sourceIds): array
	{
		if (empty($sourceIds))
		{
			return [];
		}

		try
		{
			$itemsStoryPoints = [];

			$queryObject = ItemTable::getList([
				'select' => ['ID', 'STORY_POINTS', 'SOURCE_ID'],
				'filter' => ['SOURCE_ID' => $sourceIds]
			]);
			while ($itemData = $queryObject->fetch())
			{
				$itemsStoryPoints[$itemData['SOURCE_ID']] = $itemData['STORY_POINTS'] !== null
					? $itemData['STORY_POINTS']
					: ''
				;
			}

			return $itemsStoryPoints;
		}
		catch (\Exception $exception)
		{
			$this->errorCollection->setError(
				new Error($exception->getMessage(), self::ERROR_COULD_NOT_READ_ITEM)
			);
		}

		return [];
	}

	public function getItemBySourceId(int $sourceId): ItemForm
	{
		if (!$sourceId)
		{
			return new ItemForm();
		}

		try
		{
			$itemId = 0;
			$queryObject = ItemTable::getList([
				'select' => ['ID'],
				'filter' => [
					'SOURCE_ID' => $sourceId
				],
				'order' => ['SORT_FLOAT' => 'ASC', 'ID' => 'DESC'],
			]);
			if ($itemData = $queryObject->fetch())
			{
				$itemId = $itemData['ID'];
			}

			return $this->getItemById($itemId);
		}
		catch (\Exception $exception)
		{
			$this->errorCollection->setError(
				new Error($exception->getMessage(), self::ERROR_COULD_NOT_READ_ITEM)
			);
		}

		return new ItemForm();
	}

	public function getItemIdsBySourceIds(array $sourceIds, array $entityIds = []): array
	{
		if (empty($sourceIds))
		{
			return [];
		}

		$itemIds = [];

		$filter = ['SOURCE_ID' => $sourceIds];
		if ($entityIds)
		{
			$filter['ENTITY_ID'] = $entityIds;
		}

		$queryParams = [
			'select' => ['ID'],
			'filter' => $filter,
			'order' => ['SORT_FLOAT' => 'ASC', 'ID' => 'DESC'],
		];

		$queryObject = ItemTable::getList($queryParams);

		while ($itemData = $queryObject->fetch())
		{
			$itemIds[] = $itemData['ID'];
		}

		return $itemIds;
	}

	public function getItemIdsByEntityId(int $entityId): array
	{
		if (!$entityId)
		{
			return [];
		}

		$itemIds = [];

		try
		{
			$queryParams = [
				'select' => ['ID'],
				'filter' => ['ENTITY_ID' => $entityId],
				'order' => ['SORT_FLOAT' => 'ASC', 'ID' => 'DESC'],
			];

			$queryObject = ItemTable::getList($queryParams);

			while ($itemData = $queryObject->fetch())
			{
				$itemIds[] = $itemData['ID'];
			}
		}
		catch (\Exception $exception)
		{
			$this->errorCollection->setError(
				new Error($exception->getMessage(), self::ERROR_COULD_NOT_READ_ITEM)
			);
		}

		return $itemIds;
	}

	public function getItemIdsByTypeId(int $typeId): array
	{
		if (!$typeId)
		{
			return [];
		}

		$itemIds = [];

		try
		{
			$queryObject = ItemTable::getList([
				'select' => ['ID'],
				'filter' => ['TYPE_ID' => $typeId],
				'order' => ['ID' => 'DESC'],
			]);
			while ($itemData = $queryObject->fetch())
			{
				$itemIds[] = $itemData['ID'];
			}
		}
		catch (\Exception $exception)
		{
			$this->errorCollection->setError(
				new Error(
					$exception->getMessage(),
					self::ERROR_COULD_NOT_READ_ITEM_BY_TYPE_ID
				)
			);
		}

		return $itemIds;
	}

	public function cleanTypeIdToItems(array $itemIds): void
	{
		try
		{
			if ($itemIds)
			{
				ItemTable::updateMulti($itemIds, ['TYPE_ID' => null]);
			}
		}
		catch (\Exception $exception)
		{
			$this->errorCollection->setError(
				new Error(
					$exception->getMessage(),
					self::ERROR_COULD_NOT_CLEAN_ITEMS_TYPE_ID
				)
			);
		}
	}

	/**
	 * Moves up the items in entity.
	 *
	 * @param array $itemIds Items list id.
	 * @param int $entityId Entity id.
	 * @param PushService|null $pushService For push.
	 * @return void
	 */
	public function moveItemsToEntity(
		array $itemIds,
		int $entityId,
		?PushService $pushService = null,
	): void
	{
		if (empty($itemIds))
		{
			return;
		}

		try
		{
			$firstSortValue = $this->getFirstItemSort();

			$updatedItems = [];
			$sortWhens = [];
			$entityWhens = [];
			$count = 1;
			foreach (array_reverse($itemIds) as $itemId)
			{
				$sort = $firstSortValue / (2 * $count);
				$updatedItems[$itemId] = [
					'sort' => $sort,
					'entityId' => $entityId,
				];
				$sortWhens[] = 'WHEN ID = ' . $itemId . ' THEN ' . $sort;
				$entityWhens[] = 'WHEN ID = ' . $itemId . ' THEN ' . $entityId;

				$count++;
			}

			$data = [];
			if ($sortWhens && $entityWhens && count($entityWhens) === count($sortWhens))
			{
				$data['SORT_FLOAT'] = new SqlExpression('(CASE ' . implode(' ', $sortWhens) . ' END)');
				$data['ENTITY_ID'] = new SqlExpression('(CASE ' . implode(' ', $entityWhens) . ' END)');

				ItemTable::updateMulti($itemIds, $data);
			}

			if ($pushService)
			{
				$pushService->sendSortItemEvent($updatedItems);
			}

			$this->normalizeEntitySort($entityId);
		}
		catch (\Exception $exception)
		{
			$this->errorCollection->setError(
				new Error(
					$exception->getMessage(),
					self::ERROR_COULD_NOT_MOVE_ITEM
				)
			);
		}
	}

	private function normalizeEntitySort(int $entityId): void
	{
		$offset = 0;
		$limit = 500;
		$sortValue = ItemForm::DEFAULT_SORT_VALUE;

		do {
			$items = ItemTable::getList([
				'select' => ['ID', 'SORT_FLOAT'],
				'filter' => ['ENTITY_ID' => $entityId],
				'order' => ['SORT_FLOAT' => 'ASC', 'ID' => 'DESC'],
				'limit' => $limit,
				'offset' => $offset
			])->fetchAll();
			if (empty($items))
			{
				break;
			}

			$sortWhens = [];
			$itemIds = [];
			$currentSort = $sortValue;

			foreach ($items as $item)
			{
				$itemIds[] = $item['ID'];
				$sortWhens[] = 'WHEN ID = ' . $item['ID'] . ' THEN ' . $currentSort;
				$currentSort += ItemForm::DEFAULT_SORT_VALUE;
			}

			if (!empty($sortWhens))
			{
				try
				{
					ItemTable::updateMulti($itemIds, [
						'SORT_FLOAT' => new SqlExpression('(CASE ' . implode(' ', $sortWhens) . ' END)')
					]);
				}
				catch (\Exception $e)
				{
					Container::getInstance()->getLogger()->logError($e);
				}
			}

			$offset += $limit;
			$sortValue = $currentSort;

		} while (count($items) === $limit);
	}

	public function updateEntityIdToItems(int $entityId, array $itemIds): void
	{
		try
		{
			if ($itemIds)
			{
				ItemTable::updateMulti($itemIds, ['ENTITY_ID' => $entityId]);
			}
		}
		catch (\Exception $exception)
		{
			$this->errorCollection->setError(
				new Error($exception->getMessage(), self::ERROR_COULD_NOT_UPDATE_ITEMS_ENTITY)
			);
		}
	}

	public function changeItem(ItemForm $item, PushService $pushService = null): bool
	{
		try
		{
			$result = ItemTable::update($item->getId(), $item->getFieldsToUpdateItem());

			if ($result->isSuccess())
			{
				if ($pushService)
				{
					$pushService->sendUpdateItemEvent($item);
				}

				return true;
			}
			else
			{
				$this->setErrors($result, self::ERROR_COULD_NOT_UPDATE_ITEM);

				return false;
			}
		}
		catch (\Exception $exception)
		{
			$this->errorCollection->setError(
				new Error(
					$exception->getMessage(),
					self::ERROR_COULD_NOT_UPDATE_ITEM
				)
			);

			return false;
		}
	}

	public function removeItem(ItemForm $item, PushService $pushService = null): bool
	{
		try
		{
			$result = ItemTable::delete($item->getId());

			if ($result->isSuccess())
			{
				if ($pushService)
				{
					$pushService->sendRemoveItemEvent($item);
				}

				return true;
			}
			else
			{
				$this->setErrors($result, self::ERROR_COULD_NOT_REMOVE_ITEM);

				return false;
			}
		}
		catch (\Exception $exception)
		{
			$this->errorCollection->setError(
				new Error(
					$exception->getMessage(),
					self::ERROR_COULD_NOT_REMOVE_ITEM
				)
			);

			return false;
		}
	}

	public function sortItems(array $sortInfo, ?PushService $pushService = null): void
	{
		if (empty($sortInfo))
		{
			return;
		}

		$allItemIds = $this->collectAllItemIds($sortInfo);
		$itemIds = array_keys($sortInfo);

		if (empty($itemIds))
		{
			return;
		}

		$currentItemsData = $this->getCurrentItemsData($allItemIds);

		$sortWhens = [];
		$entityWhens = [];
		$updatedItems = [];

		foreach ($sortInfo as $itemId => $info)
		{
			$itemId = (int)$itemId;
			if (!$itemId)
			{
				continue;
			}

			$currentData = $currentItemsData[$itemId] ?? null;
			if (!$currentData)
			{
				continue;
			}

			$previousItemId = isset($info['previousItemId']) ? (int)$info['previousItemId'] : null;
			$nextItemId = isset($info['nextItemId']) ? (int)$info['nextItemId'] : null;
			$newEntityId = isset($info['entityId']) ? (int)$info['entityId'] : null;
			$tmpId = $info['tmpId'] ?? '';
			$updatedItemId = isset($info['updatedItemId']) ? (int)$info['updatedItemId'] : 0;

			$newSort = $this->calculateNewSortPosition(
				$previousItemId,
				$nextItemId,
				$currentItemsData
			);

			$sortWhens[] = 'WHEN ID = ' . $itemId . ' THEN ' . $newSort;
			if ($newEntityId)
			{
				$entityWhens[] = 'WHEN ID = ' . $itemId . ' THEN ' . $newEntityId;
			}

			if ($updatedItemId)
			{
				$updatedItems[$itemId] = [
					'sort' => $newSort,
					'tmpId' => $tmpId,
				];

				if ($newEntityId)
				{
					$updatedItems[$itemId]['entityId'] = $newEntityId;
				}
			}
		}

		if (!empty($sortWhens))
		{
			ItemTable::updateMulti($itemIds, [
				'SORT_FLOAT' => new SqlExpression('(CASE ' . implode(' ', $sortWhens) . ' END)'),
			]);
		}

		if (!empty($entityWhens))
		{
			ItemTable::updateMulti($itemIds, [
				'ENTITY_ID' => new SqlExpression('(CASE ' . implode(' ', $entityWhens) . ' ELSE ENTITY_ID END)'),
			]);
		}

		if ($updatedItems && $pushService)
		{
			$pushService->sendSortItemEvent($updatedItems);
		}
	}

	private function collectAllItemIds(array $sortInfo): array
	{
		$allItemIds = [];

		foreach ($sortInfo as $itemId => $info)
		{
			$itemId = (int)$itemId;
			if ($itemId)
			{
				$allItemIds[$itemId] = true;
			}

			if (isset($info['previousItemId']))
			{
				$previousItemId = (int)$info['previousItemId'];
				if ($previousItemId)
				{
					$allItemIds[$previousItemId] = true;
				}
			}

			if (isset($info['nextItemId']))
			{
				$nextItemId = (int)$info['nextItemId'];
				if ($nextItemId)
				{
					$allItemIds[$nextItemId] = true;
				}
			}
		}

		return array_keys($allItemIds);
	}

	private function getCurrentItemsData(array $itemIds): array
	{
		$items = ItemTable::getList([
			'select' => ['ID', 'SORT_FLOAT', 'ENTITY_ID'],
			'filter' => ['ID' => $itemIds],
			'order' => ['SORT_FLOAT' => 'ASC', 'ID' => 'DESC'],
		])->fetchAll();

		$result = [];
		foreach ($items as $item)
		{
			$result[$item['ID']] = [
				'SORT_FLOAT' => (float)$item['SORT_FLOAT'],
				'ENTITY_ID' => (int)$item['ENTITY_ID']
			];
		}

		return $result;
	}

	private function calculateNewSortPosition(?int $previousItemId, ?int $nextItemId, array $currentItemsData): float
	{
		$previousSort = null;
		$nextSort = null;

		if ($previousItemId && isset($currentItemsData[$previousItemId]))
		{
			$previousSortValue = $currentItemsData[$previousItemId]['SORT_FLOAT'];
			$previousSort = ($previousSortValue !== 0.0) ? $previousSortValue : null;
		}

		if ($nextItemId && isset($currentItemsData[$nextItemId]))
		{
			$nextSortValue = $currentItemsData[$nextItemId]['SORT_FLOAT'];
			$nextSort = ($nextSortValue !== 0.0) ? $nextSortValue : null;
		}

		if ($previousSort === null && $nextSort !== null)
		{
			return $nextSort / 2.0;
		}
		elseif ($previousSort !== null && $nextSort === null)
		{
			return $previousSort + ItemForm::DEFAULT_SORT_VALUE;
		}
		elseif ($previousSort !== null && $nextSort !== null)
		{
			return ($previousSort + $nextSort) / 2.0;
		}
		else
		{
			return ItemForm::DEFAULT_SORT_VALUE;
		}
	}

	/**
	 * The method returns task ids from active items.
	 *
	 * @param int $entityId Entity id.
	 * @return array
	 */
	public function getTaskIdsByEntityId(int $entityId): array
	{
		if (!$entityId)
		{
			return [];
		}

		$items = $this->getItemsFromDb(
			['SOURCE_ID'],
			[
				'ENTITY_ID'=> (int) $entityId,
				'ACTIVE' => 'Y'
			]
		);

		return array_map(function ($item)
		{
			return $item['SOURCE_ID'];
		}, $items);
	}

	/**
	 * The method returns active items by entity id.
	 *
	 * @param int $entityId Entity id.
	 * @return ItemForm[]
	 */
	public function getTaskItemsByEntityId(int $entityId): array
	{
		if (!$entityId)
		{
			return [];
		}

		$items = $this->getItemsFromDb(
			['*'],
			[
				'ENTITY_ID'=> (int)$entityId,
				'ACTIVE' => 'Y'
			]
		);

		$itemObjects = [];
		foreach ($items as $item)
		{
			$itemForm = new ItemForm();

			$itemForm->fillFromDatabase($item);

			$itemObjects[] = $itemForm;
		}

		return $itemObjects;
	}

	/**
	 * @param array $sourceIds
	 * @return ItemInfo[]
	 */
	public function getItemsInfoBySourceIds(array $sourceIds): array
	{
		if (empty($sourceIds))
		{
			return [];
		}

		$itemsInfo = [];

		try
		{
			$queryObject = ItemTable::getList([
				'select' => ['ID', 'INFO'],
				'filter' => [
					'SOURCE_ID' => $sourceIds
				],
				'order' => ['SORT_FLOAT' => 'ASC', 'ID' => 'DESC'],
			]);
			while ($itemData = $queryObject->fetch())
			{
				$itemsInfo[$itemData['ID']] = $itemData['INFO'];
			}
		}
		catch (\Exception $exception)
		{
			$this->errorCollection->setError(
				new Error($exception->getMessage(), self::ERROR_COULD_NOT_READ_ITEM_INFO)
			);
		}

		return $itemsInfo;
	}

	public function getTaskIdByItemId(int $itemId): int
	{
		if (!$itemId)
		{
			return 0;
		}

		$queryObject = ItemTable::getList([
			'select' => ['SOURCE_ID'],
			'filter' => [
				'ID'=> $itemId,
				'ACTIVE' => 'Y',
			]
		]);
		if ($itemData = $queryObject->fetch())
		{
			return (int) $itemData['SOURCE_ID'];
		}
		return 0;
	}

	public function getSumStoryPointsBySourceIds(array $sourceIds): float
	{
		if (empty($sourceIds))
		{
			return 0;
		}

		$sumStoryPoints = 0;

		try
		{
			$queryObject = ItemTable::getList([
				'select' => ['STORY_POINTS'],
				'filter' => ['SOURCE_ID' => $sourceIds]
			]);
			while ($itemData = $queryObject->fetch())
			{
				$sumStoryPoints += (float) $itemData['STORY_POINTS'];
			}
		}
		catch (\Exception $exception)
		{
			$this->errorCollection->setError(
				new Error($exception->getMessage(), self::ERROR_COULD_NOT_READ_ITEM)
			);
		}

		return $sumStoryPoints;
	}

	/**
	 * The method returns an array of data in the required format for the client app.
	 *
	 * @param ItemForm $item Data object.
	 * @return array
	 */
	public function getItemData(ItemForm $item): array
	{
		return [
			'id' => $item->getId(),
			'tmpId' => $item->getTmpId(),
			'entityId' => $item->getEntityId(),
			'sort' => $item->getSortFloat(),
			'storyPoints' => $item->getStoryPoints(),
			'sourceId' => $item->getSourceId(),
			'epicId' => $item->getEpicId(),
			'info' => $item->getInfo()->getInfoData(),
		];
	}

	/**
	 * The method returns an array of data in the required format for the client app.
	 *
	 * @param ItemForm[] $items Items.
	 * @return array
	 */
	public function getItemsData(array $items): array
	{
		$itemsData = [];

		foreach ($items as $item)
		{
			$itemsData[$item->getSourceId()] = $this->getItemData($item);
		}

		return $itemsData;
	}

	public function getErrors()
	{
		return $this->errorCollection->toArray();
	}

	public function getErrorByCode($code)
	{
		return $this->errorCollection->getErrorByCode($code);
	}

	public function getFirstItemSort(): float
	{
		$queryObject = ItemTable::getList([
			'select' => ['SORT_FLOAT'],
			'order' => ['SORT_FLOAT' => 'ASC', 'ID' => 'DESC'],
			'limit' => 1,
		]);
		if ($itemData = $queryObject->fetch())
		{
			return (float)$itemData['SORT_FLOAT'];
		}

		return ItemForm::DEFAULT_SORT_VALUE;
	}

	private function getItemsFromDb(array $select = [], array $filter = [], array $order = []): array
	{
		$queryObject = ItemTable::getList([
			'select' => $select,
			'filter' => $filter,
			'order' => $order
		]);
		return $queryObject->fetchAll();
	}

	private function setErrors(Result $result, string $code): void
	{
		$this->errorCollection->setError(
			new Error(implode('; ', $result->getErrorMessages()), $code)
		);
	}
}