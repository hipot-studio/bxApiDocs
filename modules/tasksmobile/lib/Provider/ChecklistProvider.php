<?php

declare(strict_types=1);

namespace Bitrix\TasksMobile\Provider;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Entity\Query;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\CheckList\CheckListFacade;
use Bitrix\Tasks\CheckList\Internals\CheckList;
use Bitrix\Tasks\CheckList\Task\TaskCheckListFacade;
use Bitrix\Tasks\Internals\Task\CheckListTable;
use Bitrix\Tasks\Internals\Task\CheckListTreeTable;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\TasksMobile\Dto\ChecklistAttachmentDto;

final class ChecklistProvider
{
	/** @var array<string, array> keyed by "userId:entityId" - the items are access-filtered per user */
	private static array $plainItems = [];

	/** @var array<string, ChecklistAttachmentDto> keyed by "userId:fileId" - Disk rights are per user */
	private static array $attachments = [];

	public function __construct(
		private ?int $userId = null,

		/** @var CheckListFacade */
		private readonly string $facade = TaskCheckListFacade::class,
	)
	{
		// CurrentUser::getId() may be null (anonymous/console) - cast for the int contract
		$this->userId = (int)($userId ?? CurrentUser::get()->getId());
		if (!is_subclass_of($this->facade, CheckListFacade::class))
		{
			throw new ArgumentException("Parameter 'facade' must be subclass of CheckListFacade");
		}
	}

	public function canAdd(int $taskId): bool
	{
		return $this->facade::isActionAllowed(
			$taskId,
			null,
			$this->userId,
			CheckListFacade::ACTION_ADD,
		);
	}

	/**
	 * @param array $tasks
	 * @return array
	 */
	public function fillFullDataForTasks(array $tasks): array
	{
		$tasks = $this->fillCommonDataForTasks($tasks);

		foreach ($tasks as $taskId => $task)
		{
			$tasks[$taskId]['CHECKLIST_DETAILS'] = $this->getCheckListDetailsData($this->getChecklistTree($taskId));
		}

		return $tasks;
	}

	/**
	 * @param array $tasks
	 * @return array
	 */
	public function fillCommonDataForTasks(array $tasks): array
	{
		$taskIds = array_keys($tasks);

		foreach ($taskIds as $taskId)
		{
			$tasks[$taskId]['CHECKLIST'] = [
				'COMPLETED' => 0,
				'UNCOMPLETED' => 0,
			];
			$tasks[$taskId]['CHECKLIST_DETAILS'] = [];
		}

		$query = new Query(CheckListTable::getEntity());
		$query
			->setSelect([
				'ID',
				'TASK_ID',
				'TITLE',
				'IS_COMPLETE',
				new ExpressionField('LEVEL', 'MAX(%s)', ['IT.LEVEL']),
			])
			->setFilter(['TASK_ID' => $taskIds])
			->setOrder(['SORT_INDEX' => 'ASC'])
			->registerRuntimeField(
				'IT',
				new ReferenceField(
					'IT',
					CheckListTreeTable::class,
					Join::on('this.ID', 'ref.CHILD_ID'),
					['join_type' => 'INNER'],
				),
			)
		;

		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$taskId = (int)$row['TASK_ID'];
			$level = (int)$row['LEVEL'];
			$completedKey = ($row['IS_COMPLETE'] === 'Y') ? 'COMPLETED' : 'UNCOMPLETED';

			if ($level === 1)
			{
				$tasks[$taskId]['CHECKLIST'][$completedKey] += 1;
			}
			else
			{
				$tasks[$taskId]['CHECKLIST_DETAILS'][] = [
					'TITLE' => $row['TITLE'],
					'COMPLETED' => null,
					'UNCOMPLETED' => null,
				];
			}
		}

		return $tasks;
	}

	/**
	 * @param int $taskId
	 * @param bool $isForCopy
	 * @return array
	 */
	public function getChecklistTree(int $taskId, bool $isForCopy = false): array
	{
		$checkListItems = $this->getPlainCheckListItems($taskId);
		$this->loadDiskAttachmentsData($checkListItems);

		if ($isForCopy)
		{
			foreach ($checkListItems as $id => $item)
			{
				$checkListItems[$id]['COPIED_ID'] = $id;
				$checkListItems[$id]['IS_COMPLETE'] = 'N';
				unset($checkListItems[$id]['ID']);
			}
		}
		else
		{
			$checkListItems = $this->fillAdditionalActionsForCheckListItems($checkListItems, $taskId);
		}

		$objectTreeStructure = $this->buildTreeStructure($checkListItems);
		$objectTreeStructure = $this->fillTreeInfo($objectTreeStructure);

		return $this->fillAttachments($objectTreeStructure->toTreeArray());
	}

	/**
	 * @param bool $isForCopy
	 * @return array
	 */
	public function getChecklistV2(int $entityId, Type $type, bool $isForCopy = false): array
	{
		$items = Container::getInstance()
			->getCheckListProvider()
			->getByEntity($entityId, $this->userId, $type)
			->toArray()
		;

		// template attachments are Disk-enriched only for tasks; kept as raw V2 {id, fileId}
		if ($type !== Type::Template)
		{
			$items = $this->enrichAttachmentsForItems($items);
		}

		return $isForCopy ? $this->prepareItemsForCopy($items) : $items;
	}

	/**
	 * @param array $items
	 * @return array
	 */
	private function prepareItemsForCopy(array $items): array
	{
		$nodeIdById = [];
		foreach ($items as $item)
		{
			if (($item['id'] ?? null) !== null)
			{
				$nodeIdById[$item['id']] = $item['nodeId'] ?? null;
			}
		}

		return array_map(
			static function (array $item) use ($nodeIdById): array {
				$parentId = $item['parentId'] ?? null;

				$item['copiedId'] = $item['id'] ?? null;
				$item['id'] = null;
				$item['isComplete'] = false;

				// hierarchy of new items is by parentNodeId (server parentId is no longer valid)
				if ($parentId !== null && $parentId !== 0 && isset($nodeIdById[$parentId]))
				{
					$item['parentNodeId'] = $nodeIdById[$parentId];
				}
				$item['parentId'] = null;

				return $item;
			},
			$items,
		);
	}

	/**
	 * @param array $plainItems
	 * @return array
	 */
	public function buildV2FlatFromPlainItems(array $plainItems): array
	{
		$plainItemsById = [];
		$nodeIdByPlainId = [];
		$plainChildIdsByParentId = [];
		foreach ($plainItems as $key => $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$plainId = self::getPlainItemId($item, $key);
			if ($plainId !== null)
			{
				$plainItemsById[$plainId] = $item;
				$nodeIdByPlainId[$plainId] = self::getPlainItemNodeId($item, $plainId);
			}

			$parentId = (int)($item['PARENT_ID'] ?? 0);
			if ($parentId > 0 && $plainId !== null)
			{
				$plainChildIdsByParentId[$parentId][] = $plainId;
			}
		}

		$completeCache = [];

		return array_values(array_map(
			static function (array $item) use (
				$nodeIdByPlainId,
				$plainChildIdsByParentId,
				$plainItemsById,
				&$completeCache
			): array {
				$accomplices = [];
				$auditors = [];
				foreach (($item['MEMBERS'] ?? []) as $member)
				{
					$mapped = ['id' => (int)($member['ID'] ?? 0), 'name' => $member['NAME'] ?? ''];
					if (($member['TYPE'] ?? '') === 'U')
					{
						$auditors[] = $mapped;
					}
					else
					{
						$accomplices[] = $mapped;
					}
				}

				$attachments = [];
				foreach (($item['ATTACHMENTS'] ?? []) as $id => $attachment)
				{
					$attachments[] = is_array($attachment) ? $attachment : ['id' => $id];
				}

				$rawActions = is_array($item['ACTION'] ?? null) ? $item['ACTION'] : [];
				$actions = [
					'modify' => (bool)($rawActions['MODIFY'] ?? false),
					'remove' => (bool)($rawActions['REMOVE'] ?? false),
					'toggle' => array_key_exists('TOGGLE', $rawActions) ? (bool)$rawActions['TOGGLE'] : true,
					'add' => (bool)($rawActions['ADD'] ?? false),
					'addAccomplice' => (bool)($rawActions['ADD_ACCOMPLICE'] ?? false),
				];

				$plainId = self::getPlainItemId($item);
				$isComplete = ($item['IS_COMPLETE'] ?? 'N') === 'Y';
				if ($plainId !== null)
				{
					// a parent with all children complete is complete too
					$isComplete = $isComplete || self::arePlainItemChildrenComplete(
						$plainId,
						$plainChildIdsByParentId,
						$plainItemsById,
						$completeCache,
					);
				}

				$id = isset($item['ID']) && is_numeric($item['ID']) ? (int)$item['ID'] : null;
				$nodeId = self::getPlainItemNodeId($item, $plainId);
				$parentId = (int)($item['PARENT_ID'] ?? 0);
				$parentNodeId = isset($item['PARENT_NODE_ID']) && $item['PARENT_NODE_ID'] !== 0
					? (string)$item['PARENT_NODE_ID']
					: ($parentId > 0 ? ($nodeIdByPlainId[$parentId] ?? null) : null);

				if ($id === null)
				{
					// new (no server id): keep the link via nodeId so splitFlatList does not drop it
					$id = $nodeId;
					$parentId = $parentNodeId ?? 0;
				}

				return [
					'id' => $id,
					'nodeId' => $nodeId,
					'parentId' => $parentId,
					'parentNodeId' => $parentNodeId,
					'title' => (string)($item['TITLE'] ?? ''),
					'isComplete' => $isComplete,
					'isImportant' => ($item['IS_IMPORTANT'] ?? 'N') === 'Y',
					'sortIndex' => (int)($item['SORT_INDEX'] ?? 0),
					'accomplices' => $accomplices,
					'auditors' => $auditors,
					'attachments' => $attachments,
					'actions' => $actions,
					'copiedId' => isset($item['COPIED_ID']) && is_numeric($item['COPIED_ID'])
						? (int)$item['COPIED_ID']
						: null,
				];
			},
			$plainItems,
		));
	}

	private static function getPlainItemId(array $item, int|string|null $fallbackKey = null): ?int
	{
		foreach (['ID', 'COPIED_ID'] as $key)
		{
			if (isset($item[$key]) && is_numeric($item[$key]))
			{
				return (int)$item[$key];
			}
		}

		return is_numeric($fallbackKey) ? (int)$fallbackKey : null;
	}

	private static function arePlainItemChildrenComplete(
		int $plainId,
		array $plainChildIdsByParentId,
		array $plainItemsById,
		array &$completeCache
	): bool
	{
		if (array_key_exists($plainId, $completeCache))
		{
			return $completeCache[$plainId];
		}

		$childIds = $plainChildIdsByParentId[$plainId] ?? [];
		if ($childIds === [])
		{
			$completeCache[$plainId] = false;

			return false;
		}

		foreach ($childIds as $childId)
		{
			$child = $plainItemsById[$childId] ?? null;
			if ($child === null)
			{
				$completeCache[$plainId] = false;

				return false;
			}

			$isChildComplete = ($child['IS_COMPLETE'] ?? 'N') === 'Y'
				|| self::arePlainItemChildrenComplete(
					$childId,
					$plainChildIdsByParentId,
					$plainItemsById,
					$completeCache,
				);

			if (!$isChildComplete)
			{
				$completeCache[$plainId] = false;

				return false;
			}
		}

		$completeCache[$plainId] = true;

		return true;
	}

	private static function getPlainItemNodeId(array $item, ?int $plainId): string
	{
		$nodeId = (string)($item['NODE_ID'] ?? '');
		if ($nodeId !== '')
		{
			return $nodeId;
		}

		return $plainId === null ? '' : 'n' . $plainId;
	}

	private function getPlainCheckListItems(int $taskId): array
	{
		$cacheKey = $this->cacheKey($taskId);

		if (!array_key_exists($cacheKey, self::$plainItems))
		{
			self::$plainItems[$cacheKey] = $this->facade::getItemsForEntity($taskId, $this->userId);
		}

		return self::$plainItems[$cacheKey];
	}

	private function cacheKey(int|string $id): string
	{
		return $this->userId . ':' . $id;
	}

	private function fillAdditionalActionsForCheckListItems(array $checkListItems, int $taskId): array
	{
		$canAdd = $this->canAdd($taskId);
		$canAddAccomplice = (
			TaskAccessController::can(
				$this->userId,
				ActionDictionary::ACTION_TASK_CHANGE_ACCOMPLICES,
				$taskId,
			)
			&& !TariffPlanRestrictionProvider::isAccompliceAuditorRestricted()
		);

		foreach ($checkListItems as $id => $item)
		{
			if (array_key_exists('ACTION', $item))
			{
				$checkListItems[$id]['ACTION']['ADD'] = $canAdd;
				$checkListItems[$id]['ACTION']['ADD_ACCOMPLICE'] = $canAddAccomplice;
			}
		}

		return $checkListItems;
	}

	public function buildTreeStructure(array $checkListItems): CheckList
	{
		$nodeId = 0;
		$sortIndex = 0;

		$result = new CheckList($nodeId, $this->userId, $this->facade);
		$arrayTreeStructure = $this->facade::getArrayStructuredRoots(
			$checkListItems,
			$this->getKeyToSort($checkListItems),
		);
		foreach ($arrayTreeStructure as $root)
		{
			$nodeId++;
			$result->add(
				$this->makeTree($root['NODE_ID'] ?? $nodeId, $root, $sortIndex, false),
			);
			$sortIndex++;
		}

		return $result;
	}

	private function getKeyToSort(array $items): string
	{
		$keyToSort = 'PARENT_ID';

		foreach ($items as $item)
		{
			if (array_key_exists('PARENT_NODE_ID', $item))
			{
				$keyToSort = 'PARENT_NODE_ID';

				break;
			}
		}

		return $keyToSort;
	}

	private function makeTree(
		int|string $nodeId,
		array $root,
		int $sortIndex,
		bool|int|string $displaySortIndex,
	): CheckList
	{
		$root['SORT_INDEX'] = $sortIndex;
		$root['DISPLAY_SORT_INDEX'] = htmlspecialcharsbx((string)$displaySortIndex);

		$tree = new CheckList($nodeId, $this->userId, $this->facade, $root);

		$localSortIndex = 0;
		foreach ($root['SUB_TREE'] as $item)
		{
			++$localSortIndex;
			$nodeId = is_int($nodeId) ? ++$nodeId : $item['NODE_ID'];
			$nextDisplaySortIndex = (
			($displaySortIndex === false) ? $localSortIndex : "$displaySortIndex.$localSortIndex"
			);

			$tree->add(
				$this->makeTree($nodeId, $item, $localSortIndex - 1, $nextDisplaySortIndex),
			);
		}

		return $tree;
	}

	private function fillTreeInfo(CheckList $tree): CheckList
	{
		$completedCount = 0;

		foreach ($tree->getDescendants() as $descendant)
		{
			/** @var CheckList $descendant */
			$fields = $descendant->getFields();

			if ($fields['IS_COMPLETE'])
			{
				$completedCount++;
			}

			$this->fillTreeInfo($descendant);
		}

		$tree->setFields(['COMPLETED_COUNT' => $completedCount]);

		return $tree;
	}

	private function fillAttachments(array $tree): array
	{
		if (!empty($tree['FIELDS']['ATTACHMENTS']))
		{
			$attachments = [];
			foreach ($tree['FIELDS']['ATTACHMENTS'] as $fileId => $originValue)
			{
				$attachments[$fileId] = self::$attachments[$this->cacheKey($fileId)] ?? $originValue;
			}
			$tree['FIELDS']['ATTACHMENTS'] = $attachments;
		}

		foreach ($tree['DESCENDANTS'] as $key => $descendant)
		{
			$tree['DESCENDANTS'][$key] = $this->fillAttachments($descendant);
		}

		return $tree;
	}

	private function getCheckListDetailsData(array $checkListTree): array
	{
		$checkListDetailsData = [];

		foreach ($checkListTree['DESCENDANTS'] as $descendant)
		{
			$counts = $this->fillDetailsDataRecursively($descendant);
			$checkListDetailsData[] = [
				'TITLE' => $descendant['FIELDS']['TITLE'],
				'COMPLETED' => $counts['COMPLETED'],
				'UNCOMPLETED' => $counts['UNCOMPLETED'],
			];
		}

		return $checkListDetailsData;
	}

	private function fillDetailsDataRecursively(array $item): array
	{
		$result = [
			'COMPLETED' => 0,
			'UNCOMPLETED' => 0,
		];

		foreach ($item['DESCENDANTS'] as $descendant)
		{
			$isComplete = $descendant['FIELDS']['IS_COMPLETE'];

			$result['COMPLETED'] += ($isComplete ? 1 : 0);
			$result['UNCOMPLETED'] += ($isComplete ? 0 : 1);

			$descendantResult = $this->fillDetailsDataRecursively($descendant);

			$result['COMPLETED'] += $descendantResult['COMPLETED'];
			$result['UNCOMPLETED'] += $descendantResult['UNCOMPLETED'];
		}

		return $result;
	}

	/**
	 * @param array $items tasks.v2.CheckList.get items (each has 'attachments' => [['id'=>int,'fileId'=>string]])
	 * @return array
	 */
	public function enrichAttachmentsForItems(array $items): array
	{
		$plainForLoad = [];
		foreach ($items as $item)
		{
			if (!empty($item['attachments']))
			{
				$plainForLoad[] = [
					'ATTACHMENTS' => array_fill_keys(
						// array_filter drops null ids (an empty key would load a garbage Disk id)
						array_filter(array_column($item['attachments'], 'id')),
						true,
					),
				];
			}
		}
		$this->loadDiskAttachmentsData($plainForLoad);

		foreach ($items as $index => $item)
		{
			if (empty($item['attachments']))
			{
				continue;
			}

			$items[$index]['attachments'] = array_map(
				function (array $attachment): ChecklistAttachmentDto|array {
					$id = (int)($attachment['id'] ?? 0);
					$cached = self::$attachments[$this->cacheKey($id)] ?? null;
					if ($cached === null)
					{
						return $attachment;
					}

					// fileId keeps its string V2 meaning; Disk operations use serverFileId ('n'+OBJECT_ID)
					return new ChecklistAttachmentDto(
						id: $cached->id,
						fileId: (string)($attachment['fileId'] ?? $cached->fileId),
						serverFileId: $cached->serverFileId,
						name: $cached->name,
						url: $cached->url,
						type: $cached->type,
						isUploading: $cached->isUploading,
					);
				},
				$item['attachments'],
			);
		}

		return $items;
	}

	private function loadDiskAttachmentsData(array $plainChecklistItems = []): void
	{
		$attachmentIds = [];

		foreach ($plainChecklistItems as $item)
		{
			if (!empty($item['ATTACHMENTS']))
			{
				$attachmentIds = array_merge(
					$attachmentIds,
					array_keys($item['ATTACHMENTS']),
				);
			}
		}

		if (empty($attachmentIds))
		{
			return;
		}

		$attachmentIds = array_unique(
			array_map('intval', $attachmentIds),
		);

		$loadIds = array_values(array_filter(
			$attachmentIds,
			fn (int $id): bool => !array_key_exists($this->cacheKey($id), self::$attachments),
		));

		if (empty($loadIds))
		{
			return;
		}

		$attachments = (new DiskFileProvider($this->userId))->getDiskFileAttachments($loadIds);

		foreach ($attachments as $id => $attachment)
		{
			self::$attachments[$this->cacheKey($id)] = new ChecklistAttachmentDto(
				id: $id,
				// placeholder string fileId (V2 contract); enrichAttachmentsForItems overrides it
				fileId: (string)$id,
				serverFileId: 'n' . $attachment['OBJECT_ID'],
				name: $attachment['NAME'] ?? '',
				url: $attachment['URL'] ?? '',
				type: $attachment['TYPE'] ?? '',
				isUploading: false,
			);
		}
	}
}
