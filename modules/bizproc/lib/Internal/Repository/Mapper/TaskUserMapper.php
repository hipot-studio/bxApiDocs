<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\Task\TaskUser\TaskUser;
use Bitrix\Bizproc\Internal\Entity\Task\TaskUser\TaskUserCollection;
use Bitrix\Bizproc\Workflow\Task\EO_TaskUser;
use Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection;
use Bitrix\Bizproc\Workflow\Task\TaskUserTable;
use Bitrix\Main\Type\DateTime;

class TaskUserMapper
{
	public function convertFromOrm(EO_TaskUser $orm): TaskUser
	{
		return (new TaskUser())
			->setId($orm->getId())
			->setUserId($orm->getUserId())
			->setTaskId($orm->getTaskId())
			->setStatus($orm->getStatus())
			->setDateUpdate($orm->getDateUpdate()?->getTimestamp())
			->setOriginalUserId($orm->getOriginalUserId());
	}

	public function convertToOrm(TaskUser $entity): EO_TaskUser
	{
		$orm = $entity->getId()
			? EO_TaskUser::wakeUp($entity->getId())
			: TaskUserTable::createObject();

		$orm
			->setUserId($entity->getUserId())
			->setTaskId($entity->getTaskId())
			->setStatus($entity->getStatus())
			->setDateUpdate(DateTime::createFromTimestamp($entity->getDateUpdate()))
			->setOriginalUserId($entity->getOriginalUserId());

		return $orm;
	}

	public function convertCollectionFromOrm(?EO_TaskUser_Collection $taskUsers): TaskUserCollection
	{
		$collection = [];

		if ($taskUsers)
		{
			foreach ($taskUsers as $taskUser)
			{
				$collection[] = $this->convertFromOrm($taskUser);
			}
		}

		return new TaskUserCollection(...$collection);
	}
}
