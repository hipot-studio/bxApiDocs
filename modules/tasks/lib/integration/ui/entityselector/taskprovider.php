<?php

namespace Bitrix\Tasks\Integration\UI\EntitySelector;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Text\Emoji;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\RecentItem;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;

class TaskProvider extends BaseProvider
{
	protected static $entityId = 'task';
	protected static $maxCount = 30;
	protected bool $withTab = false;

	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->withTab = $options['withTab'] ?? false;
	}

	public function isAvailable(): bool
	{
		return $GLOBALS['USER']->isAuthorized();
	}

	public function getItems(array $ids): array
	{
		return [];
	}

	public function getSelectedItems(array $ids): array
	{
		return $this->getTaskItems(['ids' => $ids]);
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$dialog->addItems(
			$this->getTaskItems([
				'searchQuery' => $searchQuery->getQuery(),
				'parentId' => $this->getParentId($dialog),
				'excludeIds' => $this->getExcludeIds($dialog),
			])
		);
	}

	public function fillDialog(Dialog $dialog): void
	{
		$this->fillWithRecentItems($dialog);

		if ($dialog->getItemCollection()->count() < static::$maxCount)
		{
			$taskItems = $this->getTaskItems([
				'excludeIds' => array_unique(
					array_merge($this->getRecentItemsIds($dialog), $this->getExcludeIds($dialog))
				),
				'parentId' => $this->getParentId($dialog),
			]);

			$dialog->addItems($taskItems);
		}

		$this->addTab($dialog);
	}

	protected function fillWithRecentItems(Dialog $dialog): void
	{
		if ($dialog->getRecentItems()->count() <= 0)
		{
			return;
		}

		$tasks = $this->getTasks([
			'ids' => $this->getRecentItemsIds($dialog),
			'parentId' => $this->getParentId($dialog),
			'excludeIds' => $this->getExcludeIds($dialog),
		]);
		foreach ($dialog->getRecentItems()->getAll() as $item)
		{
			/** @var RecentItem $item */
			$itemId = $item->getId();

			if (
				!array_key_exists($itemId, $tasks)
				|| $dialog->getItemCollection()->get(static::$entityId, $itemId)
			)
			{
				continue;
			}

			$title = $tasks[$itemId]['TITLE'] ?? '';
			if ($title === '')
			{
				continue;
			}

			$status = (int)($tasks[$itemId]['STATUS'] ?? 0);
			$supertitle = static::getSupertitleByStatus($status);

			$dialog->addItem(static::makeItem($itemId, $title, $supertitle));
		}
	}

	protected function addTab(Dialog $dialog): void
	{
		if (!$this->withTab)
		{
			return;
		}

		$tab = new Tab([
			'id' => static::$entityId,
			'title' => Loc::getMessage('TASKS_UI_ENTITY_SELECTOR_TASK_PROVIDER_TASKS'),
			'stub' => true,
			'icon' => [
				'default' => 'o-task',
			],
		]);

		$dialog->addTab($tab);
	}

	protected function getRecentItemsIds(Dialog $dialog): array
	{
		$recentItems = $dialog->getRecentItems()->getAll();

		return array_map(
			static function (RecentItem $item) {
				return $item->getId();
			},
			$recentItems
		);
	}

	protected function getTaskItems(array $options = []): array
	{
		return $this->makeTaskItems($this->getTasks($options), $options);
	}

	protected function getTasks(array $options = []): array
	{
		$options = array_merge($this->getOptions(), $options);
		$tasks = [];

		$order = ['ID' => 'desc'];
		$filter = $this->getFilterByOptions($options);
		$parameters = [
			'USER_ID' => $GLOBALS['USER']->getId(),
			'NAV_PARAMS' => [
				'nTopCount' => static::$maxCount,
			],
		];
		$select = ['ID', 'TITLE'];

		$tasksResult = (new \Bitrix\Tasks\Provider\TaskProvider())->getList($order, $filter, $select, $parameters);
		while ($task = $tasksResult->Fetch())
		{
			$tasks[$task['ID']] = $task;
		}

		return $tasks;
	}

	protected function getFilterByOptions(array $options): array
	{
		$filter = [];

		if (
			array_key_exists('searchQuery', $options)
			&& is_string($options['searchQuery'])
		)
		{
			$this->fillSearchFilter($filter, $options['searchQuery']);
		}

		if (
			array_key_exists('ids', $options)
			&& is_array($options['ids'])
			&& !empty($options['ids'])
		)
		{
			$filter['ID'] = $options['ids'];
		}

		if (
			array_key_exists('excludeIds', $options)
			&& is_array($options['excludeIds'])
			&& !empty($options['excludeIds'])
		)
		{
			$filter['!ID'] = $options['excludeIds'];
		}

		if (
			array_key_exists('parentId', $options)
			&& is_numeric($options['parentId'])
		)
		{
			$filter['PARENT_ID'] = [$options['parentId'], 0];
		}

		if (
			array_key_exists('doer', $options)
			&& is_numeric($options['doer'])
		)
		{
			$filter['DOER'] = $options['doer'];
		}

		if (
			array_key_exists('statuses', $options)
			&& is_array($options['statuses'])
			&& !empty($options['statuses'])
		)
		{
			$filter['STATUS'] = $options['statuses'];
		}

		return $filter;
	}

	protected function fillSearchFilter(array &$filter, string $searchQuery): void
	{
		if ($searchQuery === '')
		{
			return;
		}

		$filter['META::ID_OR_NAME'] = $searchQuery;
	}

	protected function makeTaskItems(array $tasks, array $options = []): array
	{
		return static::makeItems($tasks, array_merge($this->getOptions(), $options));
	}

	protected static function makeItems(array $tasks, array $options = []): array
	{
		$result = [];
		foreach ($tasks as $id => $task)
		{
			$title = $task['TITLE'] ?? '';
			if ($title === '')
			{
				continue;
			}

			$status = (int)($task['STATUS'] ?? 0);
			$supertitle = static::getSupertitleByStatus($status);

			$result[] = static::makeItem($id, $title, $supertitle);
		}

		return $result;
	}

	protected static function makeItem(int $id, string $title, string $supertitle): Item
	{
		return new Item([
			'id' => $id,
			'entityId' => static::$entityId,
			'title' => Emoji::decode($title),
			'supertitle' => $supertitle,
			'tabs' => ['recents', static::$entityId],
		]);
	}

	protected function getParentId(Dialog $dialog): ?int
	{
		$parentId = null;
		$entity = $dialog->getEntity(static::$entityId);

		if ($entity !== null)
		{
			$taskOptions = $entity->getOptions();

			if (!empty($taskOptions['parentId']) && is_numeric($taskOptions['parentId']))
			{
				$parentId = (int)$taskOptions['parentId'];
			}
		}

		return $parentId;
	}

	protected function getExcludeIds(Dialog $dialog): array
	{
		$excludeIds = [];
		$entity = $dialog->getEntity(static::$entityId);

		if ($entity !== null)
		{
			$taskOptions = $entity->getOptions();

			if (!empty($taskOptions['excludeIds']) && is_array($taskOptions['excludeIds']))
			{
				$excludeIds = array_filter($taskOptions['excludeIds'], 'is_numeric');
			}
		}

		return $excludeIds;
	}

	protected static function getSupertitleByStatus(int $status): ?string
	{
		return Loc::getMessage('TASKS_UI_ENTITY_SELECTOR_TASK_PROVIDER_TASK');
	}
}
