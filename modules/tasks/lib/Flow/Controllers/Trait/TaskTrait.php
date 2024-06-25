<?php

namespace Bitrix\Tasks\Flow\Controllers\Trait;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Engine\Response\DataType\Page;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Tasks\Provider\Exception\TaskListException;
use Bitrix\Tasks\Provider\TaskList;
use Bitrix\Tasks\Provider\TaskQuery;
use Closure;

trait TaskTrait
{
	use UserTrait;
	use ControllerTrait;

	protected Converter $converter;
	protected TaskList $provider;
	protected int $userId;

	/**
	 * @throws TaskListException
	 */
	private function getTaskList(
		array $select,
		array $filter,
		PageNavigation $pageNavigation,
		array $order,
		Closure $modifier
	): Page
	{
		$query = (new TaskQuery($this->userId))
			->skipAccessCheck()
			->setSelect($select)
			->setWhere($filter)
			->setOrder($order)
			->setOffset($pageNavigation->getOffset())
			->setLimit($pageNavigation->getLimit());

		$pageNavigation->getLimit();

		$tasks = $this->provider->getList($query);

		foreach ($tasks as $i => &$task)
		{
			$task['SERIAL'] = $pageNavigation->getOffset() + $i + 1;

			$modifier($task);
		}

		return new Page(
			'tasks',
			$this->converter->process($this->formatTasks($tasks)),
			fn (): int => $this->provider->getCount($query),
		);
	}

	private function formatTasks(array $tasks): array
	{
		$creatorIds = array_column($tasks, 'CREATED_BY');
		$responsibleIds = array_column($tasks, 'RESPONSIBLE_ID');

		$memberIds = array_merge($creatorIds, $responsibleIds);
		$members = $this->getUsers(...$memberIds);

		$response = [];
		foreach ($tasks as $task)
		{
			$response[] = [
				'SERIAL' => $task['SERIAL'],
				'CREATOR' => $members[$task['CREATED_BY']],
				'RESPONSIBLE' => $members[$task['RESPONSIBLE_ID']],
				'TIME_IN_STATUS' => $task['TIME_IN_STATUS'],
			];
		}

		return $response;
	}

	protected function init(): void
	{
		parent::init();

		$this->userId = (int)CurrentUser::get()->getId();
		$this->provider = new TaskList();
		$this->converter = new Converter(Converter::OUTPUT_JSON_FORMAT);
	}
}