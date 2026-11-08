<?php

namespace Bitrix\Tasks\Integration\UI\EntitySelector;

use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\Internals\Task\MetaStatus;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Provider\TaskList;
use Bitrix\Tasks\Provider\Query\TaskQuery;
use Bitrix\Tasks\Util\User;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Ranking\RankingConfig;
use Bitrix\Tasks\V2\Internal\Service\Ranking\RankingService;
use Bitrix\Tasks\V2\Internal\Service\Ranking\RelationKind;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;

class TaskWithIdProvider extends TaskProvider
{
	protected static $entityId = 'task-with-id';
	protected static $maxCount = 15;

	private const MAX_PASSES = 3;

	public function fillDialog(Dialog $dialog): void
	{
		$rankedItems = $this->getRankedItemsSafe($dialog, '');
		if ($rankedItems !== null)
		{
			$dialog->addItems($rankedItems);
			$this->fillMissingRankedItems($dialog, $rankedItems, [
				'excludeIds' => $this->getExcludeIds($dialog),
				'parentId' => $this->getParentId($dialog),
				'editableOnly' => $this->isEditableOnly($dialog),
				'doer' => User::getId(),
				'statuses' => [
					MetaStatus::UNSEEN,
					MetaStatus::EXPIRED,
					Status::NEW,
					Status::PENDING,
					Status::IN_PROGRESS,
				],
			]);
			$this->addTab($dialog);

			return;
		}

		$this->fillWithRecentItems($dialog);

		if (count($dialog->getItemCollection()->getEntityItems(static::$entityId)) < static::$maxCount)
		{
			$tasks = $this->collectEditableTasks(
				[
					'excludeIds' => array_unique(
						array_merge($this->getRecentItemsIds($dialog), $this->getExcludeIds($dialog))
					),
					'parentId' => $this->getParentId($dialog),
					'editableOnly' => $this->isEditableOnly($dialog),
					'doer' => User::getId(),
					'statuses' => [
						MetaStatus::UNSEEN,
						MetaStatus::EXPIRED,
						Status::NEW,
						Status::PENDING,
						Status::IN_PROGRESS,
					],
				],
				static::$maxCount
			);

			$dialog->addItems($this->makeTaskItems($tasks));
		}

		$this->addTab($dialog);
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$searchQuery->setCacheable(false);

		$rankedItems = $this->getRankedItemsSafe($dialog, $searchQuery->getQuery());
		if ($rankedItems !== null)
		{
			$dialog->addItems($rankedItems);
			$this->fillMissingRankedItems($dialog, $rankedItems, $this->getSearchOptions($searchQuery, $dialog));

			return;
		}

		$options = $this->getSearchOptions($searchQuery, $dialog);
		$tasks = $this->collectEditableTasks($options, static::$maxCount);

		$dialog->addItems($this->makeTaskItems($tasks));
	}

	private function getRankedItemsSafe(Dialog $dialog, string $searchQuery): ?array
	{
		if (!RankingConfig::isEnabled() || $this->getSelectorKind($dialog) === null)
		{
			return null;
		}

		try
		{
			return $this->getRankedItems($dialog, $searchQuery);
		}
		catch (\Throwable $e)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return null;
		}
	}

	private function getRankedItems(Dialog $dialog, string $searchQuery): array
	{
		$rankedTasks = Container::getInstance()->get(RankingService::class)->rank(
			(int)User::getId(),
			$this->getSelectorKind($dialog),
			$this->getGroupId($dialog),
			$this->getExcludeIds($dialog),
			($searchQuery === '') ? null : $searchQuery,
			static::$maxCount,
			$this->isEditableOnly($dialog),
			$this->getParentId($dialog),
		);

		// Ranking returns a display-ordered list, while makeTaskItems() expects tasks keyed by id.
		// Keep the actual task ids here: otherwise the list positions (0, 1, ...) become item ids,
		// letting the same task appear again through recents or the regular fill. Match the legacy
		// task-with-id format too, so the title always makes the selected task unambiguous.
		$tasks = [];
		foreach ($rankedTasks as $task)
		{
			$id = (int)$task['ID'];
			$task['TITLE'] .= "[{$id}]";
			$tasks[$id] = $task;
		}

		return $this->makeTaskItems($tasks);
	}

	private function getSelectorKind(Dialog $dialog): ?RelationKind
	{
		$entity = $dialog->getEntity(static::$entityId);
		if ($entity === null)
		{
			return null;
		}

		$value = $entity->getOptions()['selectorKind'] ?? null;

		return is_string($value) ? RelationKind::tryFrom($value) : null;
	}

	protected function filterBrowsableTasks(array $tasks, array $options = []): array
	{
		if (empty($options['editableOnly']))
		{
			return $tasks;
		}

		return $this->filterEditableTasks($tasks);
	}

	protected function getTasks(array $options = [], ?int $limit = null, int $offset = 0): array
	{
		$options = array_merge($this->getOptions(), $options);
		$tasks = [];

		$order = ['STATUS' => 'ASC', 'DEADLINE' => 'DESC', 'PRIORITY' => 'DESC', 'ID' => 'DESC'];
		$filter = $this->getFilterByOptions($options);

		$select = ['ID', 'TITLE', 'STATUS'];

		$taskQuery =
			(new TaskQuery(User::getId()))
				->setOrder($order)
				->setWhere($filter)
				->setSelect($select)
				->setLimit($limit ?? static::$maxCount)
				->setOffset($offset)
		;
		$taskList = (new TaskList())->getList($taskQuery);
		foreach ($taskList as $task)
		{
			$task['TITLE'] = $task['TITLE'] . "[{$task['ID']}]";

			$tasks[$task['ID']] = $task;
		}

		return $tasks;
	}

	protected function fillSearchFilter(array &$filter, string $searchQuery): void
	{
		if ($searchQuery === '')
		{
			return;
		}

		$filter['META::ID_OR_NAME'] = $searchQuery;
	}

	protected static function getSupertitleByStatus(int $status): ?string
	{
		return ($status === Status::COMPLETED) ? Status::getMessage($status) : '';
	}

	/**
	 * Selects up to $limit editable tasks, re-reading the next pages while the filtered-out
	 * ones keep the result short. A dialog that did not ask to narrow by the edit right loses nothing
	 * to the filter, so for it a single page is read.
	 *
	 * Every pass reads a full page of $limit rows even when fewer items are missing: a shorter page
	 * makes one more pass likelier, and a pass costs a whole sorted query plus an ACL warm-up, which
	 * outweighs the extra rows of a single page.
	 *
	 * @return array<int, array> Tasks keyed by id.
	 */
	private function collectEditableTasks(array $options, int $limit): array
	{
		$tasks = [];
		$offset = 0;
		$passes = empty($options['editableOnly']) ? 1 : self::MAX_PASSES;

		for ($pass = 0; $pass < $passes; $pass++)
		{
			$page = $this->getTasks($options, $limit, $offset);
			if (empty($page))
			{
				break;
			}

			$offset += count($page);
			$tasks += $this->filterBrowsableTasks($page, $options);

			if (count($tasks) >= $limit || count($page) < $limit)
			{
				break;
			}
		}

		return array_slice($tasks, 0, $limit, true);
	}

	/**
	 * @param Item[] $rankedItems
	 */
	private function fillMissingRankedItems(Dialog $dialog, array $rankedItems, array $options): void
	{
		$rankedIds = array_map(
			static fn (Item $item): int => (int)$item->getId(),
			$rankedItems,
		);
		$missing = static::$maxCount - count($rankedIds);
		if ($missing <= 0)
		{
			return;
		}

		$options['excludeIds'] = array_unique(array_merge(
			$options['excludeIds'] ?? [],
			$rankedIds,
		));
		$tasks = $this->collectEditableTasks($options, $missing);
		$dialog->addItems($this->makeTaskItems($tasks));
	}

	/**
	 * @param array<int, array> $tasks Tasks keyed by id.
	 * @return array<int, array>
	 */
	private function filterEditableTasks(array $tasks): array
	{
		if (empty($tasks))
		{
			return $tasks;
		}

		$userId = User::getId();

		$rights = ServiceLocator::getInstance()->get(TaskRightService::class)->getTaskRightsBatch(
			$userId,
			array_keys($tasks),
			['edit' => ActionDictionary::TASK_ACTIONS['edit']]
		);

		$this->logMissingRightsDecision($userId, $tasks, $rights);

		return array_filter(
			$tasks,
			static function ($taskId) use ($rights) {
				return (bool)($rights[$taskId]['edit'] ?? false);
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * A missing rights entry is a denial, so a rights service that answered nothing empties the
	 * selector exactly like a list of non-editable tasks would. Only the log tells them apart.
	 *
	 * @param array<int, array> $tasks Tasks keyed by id.
	 * @param array<int, array> $rights Rights keyed by task id.
	 */
	private function logMissingRightsDecision(int $userId, array $tasks, array $rights): void
	{
		foreach ($rights as $taskRights)
		{
			if (array_key_exists('edit', $taskRights))
			{
				return;
			}
		}

		LogFacade::log(
			'EntitySelector: no edit decision from the task rights service for user ' . $userId
			. ', tasks: ' . implode(',', array_keys($tasks)),
			'TASKS_ENTITY_SELECTOR_RIGHTS'
		);
	}
}
