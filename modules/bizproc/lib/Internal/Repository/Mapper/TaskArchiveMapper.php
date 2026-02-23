<?php

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\Task\TaskArchive\TaskArchive;
use Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive;
use Bitrix\Bizproc\Public\Service\Task\ArchiveTaskService;
use Bitrix\Bizproc\Public\Service\Task\UnArchiveTaskService;
use Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTable;

class TaskArchiveMapper
{
	public function convertFromOrm(EO_TaskArchive $orm): TaskArchive
	{
		return (new TaskArchive())
			->setId($orm->getId())
			->setWorkflowId($orm->getWorkflowId())
			->setTasksData(UnArchiveTaskService::decodeTasksArchive($orm->getTasksData()));
	}

	public function convertToOrm(TaskArchive $entity): EO_TaskArchive
	{
		$orm =
			$entity->getId()
				? EO_TaskArchive::wakeUp($entity->getId())
				: TaskArchiveTable::createObject();

		$orm
			->setWorkflowId($entity->getWorkflowId())
			->setTasksData(ArchiveTaskService::encodeTasksArchive($entity->getTasksData()));

		return $orm;
	}
}
