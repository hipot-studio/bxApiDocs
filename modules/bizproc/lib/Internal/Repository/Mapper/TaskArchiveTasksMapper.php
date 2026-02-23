<?php

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\Task\TaskArchive\TaskArchiveTasksCollection;
use Bitrix\Bizproc\Internal\Entity\Task\TaskArchive\TaskArchiveTasks;
use Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks;
use Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks_Collection;
use Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTasksTable;
use Bitrix\Main\Type\DateTime;

class TaskArchiveTasksMapper
{
	public function convertFromOrm(EO_TaskArchiveTasks $orm): TaskArchiveTasks
	{
		return (new TaskArchiveTasks())
			->setId($orm->getId())
			->setArchiveId($orm->getArchiveId())
			->setTaskId($orm->getTaskId())
			->setCompletedAt($orm->getCompletedAt()?->getTimestamp())
		;
	}

	public function convertToOrm(TaskArchiveTasks $entity): EO_TaskArchiveTasks
	{
		$orm = $entity->getId()
			? EO_TaskArchiveTasks::wakeUp($entity->getId())
			: TaskArchiveTasksTable::createObject();

		$orm
			->setArchiveId($entity->getArchiveId())
			->setTaskId($entity->getTaskId())
			->setCompletedAt(DateTime::createFromTimestamp($entity->getCompletedAt()))
		;

		return $orm;
	}

	public function convertCollectionToOrm(
		TaskArchiveTasksCollection $entities
	): EO_TaskArchiveTasks_Collection
	{
		$collection = new EO_TaskArchiveTasks_Collection();
		foreach ($entities as $entity)
		{
			$collection->add($this->convertToOrm($entity));
		}

		return $collection;
	}
}
