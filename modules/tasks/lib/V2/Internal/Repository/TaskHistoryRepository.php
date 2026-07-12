<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Tasks\Internals\Task\LogTable;
use Bitrix\Tasks\Provider\Log\TaskLogQuery;
use Bitrix\Tasks\V2\Internal\Entity\HistoryLogCollection;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\HistoryLogMapper;

class TaskHistoryRepository implements TaskHistoryRepositoryInterface
{
	public function __construct(
		private readonly HistoryLogMapper $historyLogMapper,
	)
	{
	}

	public function tail(int $taskId, int $offset = 0, int $limit = 50): HistoryLogCollection
	{
		$filter = new ConditionTree();
		$filter->where('TASK_ID', $taskId);

		$query = (new TaskLogQuery())
			->setSelect([
				'ID',
				'CREATED_DATE',
				'USER_ID',
				'TASK_ID',
				'FIELD',
				'FROM_VALUE',
				'TO_VALUE',
			])
			->setOrderBy(['ID' => 'DESC'])
			->setDistinct(false)
			->setOffset($offset)
			->setLimit($limit)
			->setWhere($filter)
		;

		$logs = Container::getInstance()->getTaskLogProvider()->getList($query);

		return $this->historyLogMapper->mapToCollection($logs);
	}

	public function tailByFields(int $taskId, array $fields, int $offset = 0, int $limit = 50): array
	{
		if ($fields === [])
		{
			return [];
		}

		$result = LogTable::query()
			->setSelect([
				'CREATED_DATE',
				'USER_ID',
				'FIELD',
				'FROM_VALUE',
				'TO_VALUE',
			])
			->setOrder(['ID' => 'DESC'])
			->setDistinct(false)
			->setOffset($offset)
			->setLimit($limit)
			->where('TASK_ID', $taskId)
			->whereIn('FIELD', $fields)
			->exec()
		;

		return $this->historyLogMapper->mapStatusFieldsValues($result->fetchAll());
	}

	public function getLastCreatedDateByFields(int $taskId, array $fields): ?DateTime
	{
		if ($fields === [])
		{
			return null;
		}

		$row = LogTable::query()
			->setSelect(['CREATED_DATE'])
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->where('TASK_ID', $taskId)
			->whereIn('FIELD', $fields)
			->exec()
			->fetch()
		;

		$date = $row['CREATED_DATE'] ?? null;

		if (!$date instanceof DateTime)
		{
			return null;
		}

		return $date;
	}

	public function getLastCreatedDatesByFields(array $taskIds, array $fields): array
	{
		$result = [];
		if (empty($taskIds) || empty($fields))
		{
			return $result;
		}

		Collection::normalizeArrayValuesByInt($taskIds, false);

		if (empty($taskIds))
		{
			return $result;
		}

		$queryResult = LogTable::query()
			->setSelect([
				'TASK_ID',
				new ExpressionField('LAST_DATE', 'MAX(%s)', 'CREATED_DATE'),
			])
			->whereIn('TASK_ID', $taskIds)
			->whereIn('FIELD', $fields)
			->setGroup(['TASK_ID'])
			->exec()
		;

		while ($row = $queryResult->fetch())
		{
			$taskId = (int)($row['TASK_ID'] ?? null);
			$lastDate = $row['LAST_DATE'] ?? null;

			if ($taskId > 0 && $lastDate instanceof DateTime)
			{
				$result[$taskId] = $lastDate;
			}
		}

		return $result;
	}
}
