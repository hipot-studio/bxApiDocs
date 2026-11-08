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
use Bitrix\Tasks\Internals\TaskTable;
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
	const ERROR_COULD_NOT_MOVE_ITEM_BEFORE = 'TASKS_IS_16';
	const ERROR_COULD_NOT_GET_LIST = 'ITEM_LIST_01';
	private const MIN_SORT_STEP = 0.001;

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
			$this->normalizeEntitySort($entityId);

			return;
		}

		try
		{
			$firstSortValue = $this->getFirstItemSort($entityId);

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
		$batchSize = 500;
		$items = ItemTable::getList([
			'select' => ['ID'],
			'filter' => ['ENTITY_ID' => $entityId],
			'order' => ['SORT_FLOAT' => 'ASC', 'ID' => 'DESC'],
		]);

		$sortValue = ItemForm::DEFAULT_SORT_VALUE;
		$bufferedItemIds = [];
		$sortWhens = [];
		$hasMoreItems = true;

		while ($hasMoreItems)
		{
			$item = $items->fetch();
			$hasMoreItems = is_array($item);

			if ($hasMoreItems)
			{
				$itemId = (int)$item['ID'];
				$bufferedItemIds[] = $itemId;
				$sortWhens[] = 'WHEN ID = ' . $itemId . ' THEN ' . $sortValue;
				$sortValue += ItemForm::DEFAULT_SORT_VALUE;
			}

			$isBufferFull = (count($bufferedItemIds) === $batchSize);
			if ($bufferedItemIds && ($isBufferFull || !$hasMoreItems))
			{
				try
				{
					ItemTable::updateMulti($bufferedItemIds, [
						'SORT_FLOAT' => new SqlExpression('(CASE ' . implode(' ', $sortWhens) . ' END)'),
					]);
				}
				catch (\Exception $e)
				{
					Container::getInstance()->getLogger()->logError($e);
				}

				$bufferedItemIds = [];
				$sortWhens = [];
			}
		}
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

	/**
	 * Moves root Scrum items immediately before another root item.
	 *
	 * @param array $itemsToMove Ordered items with their temporary push identifiers.
	 * @param int $targetItemId Target item id.
	 * @param int $entityId Scrum entity id.
	 * @param PushService|null $pushService Push service.
	 * @return array Updated sort information keyed by item id.
	 */
	public function moveItemsBeforeItem(
		array $itemsToMove,
		int $targetItemId,
		int $entityId,
		?PushService $pushService = null
	): array
	{
		$itemsToMove = $this->normalizeItemsToMove($itemsToMove);
		$itemIds = array_keys($itemsToMove);
		if (!$itemIds || $targetItemId <= 0 || $entityId <= 0 || isset($itemsToMove[$targetItemId]))
		{
			$this->setMoveItemBeforeError();

			return [];
		}

		try
		{
			$requiredItemIds = [...$itemIds, $targetItemId];
			$currentItemsData = $this->getRootItemsData($entityId, $requiredItemIds);
			if (count($currentItemsData) !== count($requiredItemIds))
			{
				$this->setMoveItemBeforeError();

				return [];
			}

			$targetItemData = $currentItemsData[$targetItemId];
			$previousItemData = $this->getPreviousRootItemData(
				$entityId,
				$targetItemData,
				$itemIds
			);

			$previousSort = (float)($previousItemData['SORT_FLOAT'] ?? 0.0);
			$targetSort = (float)$targetItemData['SORT_FLOAT'];
			$sortStep = ($targetSort - $previousSort) / (count($itemIds) + 1);

			$sortsNormalized = false;
			if ($sortStep <= self::MIN_SORT_STEP)
			{
				$this->normalizeEntitySort($entityId);
				$sortsNormalized = true;

				$currentItemsData = $this->getRootItemsData($entityId, $requiredItemIds);
				$targetItemData = $currentItemsData[$targetItemId] ?? null;
				if (!$targetItemData)
				{
					$this->setMoveItemBeforeError();

					return [];
				}

				$previousItemData = $this->getPreviousRootItemData(
					$entityId,
					$targetItemData,
					$itemIds
				);
				$previousSort = (float)($previousItemData['SORT_FLOAT'] ?? 0.0);
				$targetSort = (float)$targetItemData['SORT_FLOAT'];
				$sortStep = ($targetSort - $previousSort) / (count($itemIds) + 1);
			}

			$sortValues = $this->calculateSortValues($itemIds, $previousSort, $targetSort);
			if (!$sortValues)
			{
				$this->setMoveItemBeforeError();

				return [];
			}

			$sortWhens = [];
			$updatedItems = [];
			foreach ($sortValues as $itemId => $sort)
			{
				$sortWhens[] = 'WHEN ID = ' . $itemId . ' THEN ' . $sort;
				$updatedItems[$itemId] = [
					'sort' => $sort,
					'tmpId' => $itemsToMove[$itemId],
				];
				if ($sortsNormalized)
				{
					$updatedItems[$itemId]['normalizationRequired'] = true;
					$updatedItems[$itemId]['entityId'] = $entityId;
				}
			}

			ItemTable::updateMulti($itemIds, [
				'SORT_FLOAT' => new SqlExpression('(CASE ' . implode(' ', $sortWhens) . ' END)'),
			]);

			if ($pushService)
			{
				$pushService->sendSortItemEvent($updatedItems);
			}

			return $updatedItems;
		}
		catch (\Exception $exception)
		{
			$this->errorCollection->setError(
				new Error($exception->getMessage(), self::ERROR_COULD_NOT_MOVE_ITEM_BEFORE)
			);

			return [];
		}
	}

	public function sortItems(array $sortInfo, ?PushService $pushService = null): array
	{
		if (empty($sortInfo))
		{
			return [];
		}

		$allItemIds = $this->collectAllItemIds($sortInfo);
		if (empty($allItemIds))
		{
			return [];
		}

		$currentItemsData = $this->getCurrentItemsData($allItemIds);
		$newSortValues = $this->calculateNewSortPositions($sortInfo, $currentItemsData);

		$sortWhens = [];
		$sortItemParams = [];
		$itemIds = [];

		foreach ($sortInfo as $itemId => $info)
		{
			$itemId = (int)$itemId;
			if (!$itemId || !isset($newSortValues[$itemId]))
			{
				continue;
			}

			$newEntityId = isset($info['entityId']) ? (int)$info['entityId'] : null;
			$tmpId = is_string($info['tmpId'] ?? null) ? $info['tmpId'] : '';
			$updatedItemId = isset($info['updatedItemId']) ? (int)$info['updatedItemId'] : 0;
			$newSort = (float)$newSortValues[$itemId];

			$itemIds[] = $itemId;
			$sortWhens[] = 'WHEN ID = ' . $itemId . ' THEN ' . $newSort;
			$sortItemParams[$itemId] = [
				'tmpId' => $tmpId,
				'newEntityId' => $newEntityId,
				'sendUpdate' => $updatedItemId > 0,
			];
		}

		if (empty($itemIds))
		{
			return [];
		}

		if (!empty($sortWhens))
		{
			ItemTable::updateMulti($itemIds, [
				'SORT_FLOAT' => new SqlExpression('(CASE ' . implode(' ', $sortWhens) . ' END)'),
			]);
		}

		$updatedItems = [];
		$sortedItems = [];
		$storedItemsData = $this->getCurrentItemsData($itemIds);
		foreach ($sortItemParams as $itemId => $params)
		{
			if (!isset($storedItemsData[$itemId]))
			{
				continue;
			}

			$itemInfo = [
				'sort' => (float)$storedItemsData[$itemId]['SORT_FLOAT'],
			];
			if ($params['tmpId'] !== '')
			{
				$itemInfo['tmpId'] = $params['tmpId'];
			}
			if ($params['newEntityId'])
			{
				$itemInfo['entityId'] = (int)$storedItemsData[$itemId]['ENTITY_ID'];
			}

			$sortedItems[$itemId] = $itemInfo;
			if ($params['sendUpdate'])
			{
				$updatedItems[$itemId] = $itemInfo;
			}
		}

		if ($updatedItems && $pushService)
		{
			$pushService->sendSortItemEvent($updatedItems);
		}

		return $sortedItems;
	}

	private function normalizeItemsToMove(array $itemsToMove): array
	{
		$normalizedItems = [];
		foreach ($itemsToMove as $itemData)
		{
			if (!is_array($itemData))
			{
				continue;
			}

			$itemId = (int)($itemData['id'] ?? 0);
			if ($itemId <= 0 || isset($normalizedItems[$itemId]))
			{
				continue;
			}

			$normalizedItems[$itemId] = (string)($itemData['tmpId'] ?? '');
		}

		return $normalizedItems;
	}

	private function calculateSortValues(array $itemIds, float $previousSort, float $targetSort): array
	{
		$sortStep = ($targetSort - $previousSort) / (count($itemIds) + 1);
		if ($sortStep <= 0)
		{
			return [];
		}

		$sortValues = [];
		foreach ($itemIds as $index => $itemId)
		{
			$sortValues[$itemId] = $previousSort + ($sortStep * ($index + 1));
		}

		return $sortValues;
	}

	private function getRootItemsData(int $entityId, array $itemIds): array
	{
		$query = $this->getRootItemsQuery($entityId)
			->setSelect(['ID', 'SORT_FLOAT'])
			->whereIn('ID', $itemIds)
		;

		$items = [];
		$result = $query->exec();
		while ($itemData = $result->fetch())
		{
			$items[(int)$itemData['ID']] = $itemData;
		}

		return $items;
	}

	private function getPreviousRootItemData(int $entityId, array $targetItemData, array $excludeIds): ?array
	{
		$targetItemId = (int)$targetItemData['ID'];
		$targetSort = (float)$targetItemData['SORT_FLOAT'];
		$positionFilter = Query\Query::filter()
			->logic('or')
			->where('SORT_FLOAT', '<', $targetSort)
			->where(
				Query\Query::filter()
					->where('SORT_FLOAT', $targetSort)
					->where('ID', '>', $targetItemId)
			)
		;

		$query = $this->getRootItemsQuery($entityId)
			->setSelect(['ID', 'SORT_FLOAT'])
			->where($positionFilter)
			->setOrder(['SORT_FLOAT' => 'DESC', 'ID' => 'ASC'])
			->setLimit(1)
		;
		if ($excludeIds)
		{
			$query->whereNotIn('ID', $excludeIds);
		}

		return $query->exec()->fetch() ?: null;
	}

	private function getRootItemsQuery(int $entityId): Query\Query
	{
		$rootTaskFilter = Query\Query::filter()
			->logic('or')
			->where('TASK.PARENT_ID', 0)
			->whereNull('TASK.PARENT_ID')
		;

		return ItemTable::query()
			->registerRuntimeField('TASK', new ReferenceField(
				'TASK',
				TaskTable::getEntity(),
				Join::on('this.SOURCE_ID', 'ref.ID'),
				['join_type' => 'INNER']
			))
			->where('ENTITY_ID', $entityId)
			->where('ACTIVE', 'Y')
			->where($rootTaskFilter)
		;
	}

	private function setMoveItemBeforeError(): void
	{
		$this->errorCollection->setError(
			new Error('Unable to move Scrum items before the target item.', self::ERROR_COULD_NOT_MOVE_ITEM_BEFORE)
		);
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

	private function calculateNewSortPositions(array $sortInfo, array $currentItemsData): array
	{
		$itemIds = [];
		foreach (array_keys($sortInfo) as $itemId)
		{
			$itemId = (int)$itemId;
			if ($itemId && isset($currentItemsData[$itemId]))
			{
				$itemIds[$itemId] = true;
			}
		}

		$groups = [];
		$visitedItemIds = [];
		foreach (array_keys($itemIds) as $itemId)
		{
			$previousItemId = isset($sortInfo[$itemId]['previousItemId'])
				? (int)$sortInfo[$itemId]['previousItemId']
				: null
			;
			if ($previousItemId && isset($itemIds[$previousItemId]))
			{
				continue;
			}

			$groups[] = $this->collectSortGroup($itemId, $sortInfo, $itemIds, $visitedItemIds);
		}

		foreach (array_keys($itemIds) as $itemId)
		{
			if (!isset($visitedItemIds[$itemId]))
			{
				$groups[] = $this->collectSortGroup($itemId, $sortInfo, $itemIds, $visitedItemIds);
			}
		}

		$newSortValues = [];
		foreach ($groups as $group)
		{
			if (empty($group))
			{
				continue;
			}

			$firstItemId = reset($group);
			$lastItemId = end($group);
			$previousItemId = isset($sortInfo[$firstItemId]['previousItemId'])
				? (int)$sortInfo[$firstItemId]['previousItemId']
				: null
			;
			$nextItemId = isset($sortInfo[$lastItemId]['nextItemId'])
				? (int)$sortInfo[$lastItemId]['nextItemId']
				: null
			;
			$previousSort = $this->getCurrentSort($previousItemId, $currentItemsData);
			$nextSort = $this->getCurrentSort($nextItemId, $currentItemsData);
			$groupSize = count($group);

			$sortStep = ItemForm::DEFAULT_SORT_VALUE;
			$firstSort = ItemForm::DEFAULT_SORT_VALUE;
			if ($previousSort !== null && $nextSort !== null && $nextSort > $previousSort)
			{
				$sortStep = ($nextSort - $previousSort) / ($groupSize + 1);
				$firstSort = $previousSort + $sortStep;
			}
			elseif ($previousSort !== null)
			{
				$firstSort = $previousSort + ItemForm::DEFAULT_SORT_VALUE;
			}
			elseif ($nextSort !== null)
			{
				$sortStep = $nextSort / ($groupSize + 1);
				$firstSort = $sortStep;
			}

			foreach ($group as $index => $itemId)
			{
				$newSortValues[$itemId] = $firstSort + $sortStep * $index;
			}
		}

		return $newSortValues;
	}

	private function collectSortGroup(
		int $firstItemId,
		array $sortInfo,
		array $itemIds,
		array &$visitedItemIds
	): array
	{
		$group = [];
		$currentItemId = $firstItemId;
		while ($currentItemId && isset($itemIds[$currentItemId]) && !isset($visitedItemIds[$currentItemId]))
		{
			$group[] = $currentItemId;
			$visitedItemIds[$currentItemId] = true;
			$currentItemId = isset($sortInfo[$currentItemId]['nextItemId'])
				? (int)$sortInfo[$currentItemId]['nextItemId']
				: null
			;
		}

		return $group;
	}

	private function getCurrentSort(?int $itemId, array $currentItemsData): ?float
	{
		if (!$itemId || !isset($currentItemsData[$itemId]))
		{
			return null;
		}

		$sort = (float)$currentItemsData[$itemId]['SORT_FLOAT'];

		return $sort !== 0.0 ? $sort : null;
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

	public function getFirstItemSort(int $entityId): float
	{
		$queryObject = ItemTable::getList([
			'select' => ['SORT_FLOAT'],
			'filter' => [
				'ENTITY_ID' => $entityId,
			],
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
