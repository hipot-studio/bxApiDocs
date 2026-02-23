<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\Task\Task;
use Bitrix\Bizproc\Internal\Entity\Task\TaskCollection;
use Bitrix\Bizproc\Workflow\Task\EO_Task;
use Bitrix\Bizproc\Workflow\Task\EO_Task_Collection;
use Bitrix\Bizproc\Workflow\Task\TaskTable;
use Bitrix\Main\Type\DateTime;

class TaskMapper
{
	public function __construct(private readonly TaskUserMapper $taskUserMapper)
	{
	}

	public function convertFromOrm(EO_Task $orm): Task
	{
		return (new Task())
			->setId($orm->getId())
			->setWorkflowId($orm->getWorkflowId())
			->setActivity($orm->getActivity())
			->setActivityName($orm->getActivityName())
			->setModified($orm->getModified()?->getTimestamp())
			->setOverdueDate($orm->getOverdueDate())
			->setName($orm->getName())
			->setDescription($orm->getDescription())
			->setParameters($orm->getParameters())
			->setStatus($orm->getStatus())
			->setIsInline($orm->getIsInline() === 'Y')
			->setDelegationType($orm->getDelegationType())
			->setDocumentName($orm->getDocumentName())
			->setCreatedDate($orm->getCreatedDate()?->getTimestamp())
			->setTaskUsers($this->taskUserMapper->convertCollectionFromOrm($orm->getTaskUsers()))
		;
	}

	public function convertToOrm(Task $entity): EO_Task
	{
		$orm = !$entity->isNew()
			? EO_Task::wakeUp($entity->getId())
			: TaskTable::createObject();

		if ($entity->isNew())
		{
			$orm->setCreatedDate(new DateTime());
		}

		$orm
			->setWorkflowId($entity->getWorkflowId())
			->setActivity($entity->getActivity())
			->setActivityName($entity->getActivityName())
			->setModified(new DateTime())
			->setOverdueDate($entity->getOverdueDate())
			->setName($entity->getName())
			->setDescription($entity->getDescription())
			->setParameters($entity->getParameters())
			->setStatus($entity->getStatus())
			->setIsInline($entity->getIsInline() ? 'Y' : 'N')
			->setDelegationType($entity->getDelegationType())
			->setDocumentName($entity->getDocumentName())
		;

		return $orm;
	}

	public function convertCollectionFromOrm(EO_Task_Collection $tasks): TaskCollection
	{
		$collection = [];
		foreach ($tasks as $task)
		{
			$collection[] = $this->convertFromOrm($task);
		}

		return new TaskCollection(...$collection);
	}
}
