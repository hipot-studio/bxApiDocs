<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\Internals\Task\LogTable;
use Bitrix\Tasks\V2\Internal\Entity\HistoryGridLogCollection;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\HistoryGridLogMapper;

class TaskHistoryGridRepository implements TaskHistoryGridRepositoryInterface
{
	public function __construct(
		private readonly HistoryGridLogMapper $historyGridLogMapper,
	)
	{

	}

	public function tail(int $taskId, int $offset = 0, int $limit = 50): HistoryGridLogCollection
	{
		$logs =
			LogTable::query()
				->setSelect([
					'ID',
					'CREATED_DATE',
					'USER_ID',
					'TASK_ID',
					'FIELD',
					'FROM_VALUE',
					'TO_VALUE',
					'USER_TITLE' => 'USER.TITLE',
					'USER_NAME' => 'USER.NAME',
					'USER_LAST_NAME' => 'USER.LAST_NAME',
					'USER_SECOND_NAME' => 'USER.SECOND_NAME',
					'USER_EMAIL' => 'USER.EMAIL',
					'USER_LOGIN' => 'USER.LOGIN',
				])
				->where('TASK_ID', $taskId)
				->setOrder(['ID' => 'DESC'])
				->setDistinct(false)
				->setLimit($limit)
				->setOffset($offset)
				->exec()
				->fetchAll()
		;

		return $this->historyGridLogMapper->mapToCollection($logs);
	}
}
