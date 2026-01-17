<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Tasks\Internals\Task\ElapsedTimeTable;
use Bitrix\Tasks\V2\Internal\Entity\Task\ElapsedTime;
use Bitrix\Tasks\V2\Internal\Entity\Task\ElapsedTimeCollection;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\ElapsedTimeMapper;

class ElapsedTimeReadRepository implements ElapsedTimeReadRepositoryInterface
{
	public function __construct(
		private readonly ElapsedTimeMapper $elapsedTimeMapper,
	)
	{
	}

	public function getList(int $taskId, int $limit, int $offset, array $order = []): ElapsedTimeCollection
	{
		$rows = ElapsedTimeTable::query()
			->setSelect(['*'])
			->where('TASK_ID', $taskId)
			->setLimit($limit)
			->setOffset($offset)
			->setOrder($order)
			->exec()
			->fetchAll()
		;

		return $this->elapsedTimeMapper->mapToCollection($rows);
	}

	public function getById(int $elapsedTimeId): ?ElapsedTime
	{
		$row = ElapsedTimeTable::query()
			->setSelect(['*'])
			->where('ID', $elapsedTimeId)
			->exec()
			->fetch()
		;

		if (!is_array($row))
		{
			return null;
		}

		return $this->elapsedTimeMapper->mapToEntity($row);
	}

	public function getUsersContribution(int $taskId): array
	{
		$rows = ElapsedTimeTable::query()
			->registerRuntimeField(new ExpressionField('TOTAL_SECONDS', 'SUM(%s)', 'SECONDS'))
			->setSelect(['USER_ID', 'TOTAL_SECONDS'])
			->where('TASK_ID', $taskId)
			->addGroup('USER_ID')
			->exec()
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['USER_ID']] = (int)$row['TOTAL_SECONDS'];
		}

		return $result;
	}
}
