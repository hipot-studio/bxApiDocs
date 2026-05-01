<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\Task\AccessRequest;
use Bitrix\Tasks\V2\Internal\Model\TaskAccessRequestTable;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Task\AccessRequestMapper;

class TaskAccessRequestRepository implements TaskAccessRequestRepositoryInterface
{
	public function __construct(
		private readonly AccessRequestMapper $accessRequestMapper,
	)
	{

	}

	public function add(AccessRequest $accessRequest): void
	{
		$data = $this->accessRequestMapper->mapFromEntity($accessRequest);

		TaskAccessRequestTable::add($data);
	}

	public function get(int $userId, int $taskId): ?AccessRequest
	{
		$row = TaskAccessRequestTable::query()
			->setSelect(['TASK_ID', 'USER_ID', 'CREATED_DATE'])
			->where('USER_ID', $userId)
			->where('TASK_ID', $taskId)
			->fetch();

		if (!is_array($row))
		{
			return null;
		}

		return $this->accessRequestMapper->mapToEntity($row);
	}

	public function isExists(int $userId, int $taskId): bool
	{
		$result = TaskAccessRequestTable::query()
			->setSelect([new ExpressionField('EXISTS', 1)])
			->where('TASK_ID', $taskId)
			->where('USER_ID', $userId)
			->setLimit(1)
			->fetch()
		;

		return $result !== false;
	}

	public function clearByTime(int $createdDateTs): void
	{
		TaskAccessRequestTable::deleteByFilter(['<CREATED_DATE' => DateTime::createFromTimestamp($createdDateTs)]);
	}

	public function clearByTaskId(int $taskId): void
	{
		TaskAccessRequestTable::deleteByFilter(['=TASK_ID' => $taskId]);
	}

	public function clearByUserId(int $userId): void
	{
		TaskAccessRequestTable::deleteByFilter(['=USER_ID' => $userId]);
	}
}
