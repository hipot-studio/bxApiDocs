<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Tasks\Provider\Log\TaskLogQuery;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\HistoryLogMapper;

class TaskLogRepository implements TaskLogRepositoryInterface
{
	public function __construct(
		private readonly HistoryLogMapper $historyLogMapper
	)
	{

	}

	public function add(Entity\HistoryLog $historyLog): int
	{
		$command = $this->historyLogMapper->mapToCommand($historyLog);

		$service = ServiceLocator::getInstance()->get('tasks.control.log.task.service');

		return $service->add($command)->getId();
	}

	public function tail(int $taskId, int $offset = 0): Entity\HistoryLogCollection
	{
		$filter = new ConditionTree();
		$filter->where('TASK_ID', $taskId);

		$select = [
			'ID',
			'CREATED_DATE',
			'USER_ID',
			'TASK_ID',
			'FIELD',
			'FROM_VALUE',
			'TO_VALUE',
		];

		$order = ['ID' => 'DESC'];

		$query = (new TaskLogQuery())
			->setSelect($select)
			->setOrderBy($order)
			->setDistinct(false)
			->setOffset($offset)
			->setLimit(50)
			->setWhere($filter);

		$logs = Container::getInstance()->getTaskLogProvider()->getList($query);

		return $this->historyLogMapper->mapToCollection($logs);
	}

	public function getLastByField(int $taskId, string $field): ?Entity\HistoryLog
	{
		$filter = new ConditionTree();
		$filter->where('TASK_ID', $taskId);
		$filter->where('FIELD', $field);

		$select = [
			'ID',
			'CREATED_DATE',
			'USER_ID',
			'TASK_ID',
			'FIELD',
			'FROM_VALUE',
			'TO_VALUE',
		];

		$order = ['ID' => 'DESC'];

		$query = (new TaskLogQuery())
			->setSelect($select)
			->setOrderBy($order)
			->setDistinct(false)
			->setLimit(1)
			->setWhere($filter);

		$logs = Container::getInstance()->getTaskLogProvider()->getList($query)->getLogs();
		if (empty($logs))
		{
			return null;
		}

		$log = array_shift($logs);

		return $this->historyLogMapper->mapToEntity($log);
	}

	public function tailWithFieldAndValues(int $taskId, string $field, mixed $fromValue = null, mixed $toValue = null, int $offset = 0, ?int $limit = 50): Entity\HistoryLogCollection
	{
		$filter = new ConditionTree();
		$filter->where('TASK_ID', $taskId);
		$filter->where('FIELD', $field);

		if ($fromValue !== null) {
			$filter->where('FROM_VALUE', $fromValue);
		}

		if ($toValue !== null) {
			$filter->where('TO_VALUE', $toValue);
		}

		$select = [
			'ID',
			'CREATED_DATE',
			'USER_ID',
			'TASK_ID',
			'FIELD',
			'FROM_VALUE',
			'TO_VALUE',
		];

		$order = ['ID' => 'DESC'];

		$query = (new TaskLogQuery())
			->setSelect($select)
			->setOrderBy($order)
			->setDistinct(false)
			->setOffset($offset)
			->setWhere($filter);

		if ($limit !== null) {
			$query->setLimit($limit);
		}

		$logs = Container::getInstance()->getTaskLogProvider()->getList($query);

		return $this->historyLogMapper->mapToCollection($logs);
	}
}
