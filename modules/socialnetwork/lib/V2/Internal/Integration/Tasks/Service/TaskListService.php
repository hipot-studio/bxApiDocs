<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;
use Bitrix\Tasks\V2\Public\Provider\Params\SortDirection;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\FieldsEnum;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListArrayFilter;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListParams;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListSelect;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListSort;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;

class TaskListService
{
	/**
	 * @return array <int, int> taskId => chatId
	 * @throws ArgumentException
	 * @throws LoaderException
	 */
	public function getTaskChatsByGroupByActivityDesc(int $groupId, int $limit): array
	{
		if (!Loader::includeModule('tasks'))
		{
			return [];
		}

		$taskListParams = new TaskListParams(
			userId: 0,
			pagination: new Pager($limit),
			filter: new TaskListArrayFilter([
				[FieldsEnum::GroupId->value, '=', $groupId],
			]),
			sort: new TaskListSort([
				FieldsEnum::ActivityDate->value => SortDirection::Desc->value,
			]),
			select: new TaskListSelect([
				FieldsEnum::Id->value,
				FieldsEnum::ChatId->value,
			]),
			skipAccessCheck: true,
		);

		return $this->collectChatIds(
			(new TaskProvider())->getList($taskListParams),
		);
	}

	/**
	 * @return array <int, int> taskId => chatId
	 * @throws ArgumentException
	 * @throws LoaderException
	 */
	public function getTaskChatsByGroupByIdDesc(int $groupId, int $lastId, int $limit): array
	{
		if (!Loader::includeModule('tasks'))
		{
			return [];
		}

		$filters = [
			[FieldsEnum::GroupId->value, '=', $groupId],
			[FieldsEnum::ChatId->value, '!=', null],
			[FieldsEnum::ChatId->value, '!=', 0],
		];

		if ($lastId > 0)
		{
			$filters[] = [FieldsEnum::Id->value, '<', $lastId];
		}

		$taskListParams = new TaskListParams(
			userId: 0,
			pagination: new Pager($limit),
			filter: new TaskListArrayFilter($filters),
			sort: new TaskListSort([
				FieldsEnum::Id->value => SortDirection::Desc->value,
			]),
			select: new TaskListSelect([
				FieldsEnum::Id->value,
				FieldsEnum::ChatId->value,
			]),
			skipAccessCheck: true,
		);

		return $this->collectChatIds(
			(new TaskProvider())->getList($taskListParams),
		);
	}

	/**
	 * @param TaskCollection $collection
	 * @return array <int, int> taskId => chatId
	 */
	protected function collectChatIds(TaskCollection $collection): array
	{
		$result = [];
		foreach ($collection as $task)
		{
			$result[$task->id] = $task->chatId;
		}

		return $result;
	}
}
