<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\TaskArchiveRepository;

use Bitrix\Bizproc\Internal\Entity\Task\TaskArchive\TaskArchiveTasksCollection;
use Bitrix\Bizproc\Internal\Repository\Mapper\TaskArchiveTasksMapper;
use Bitrix\Main\Repository\Exception\PersistenceException;

class TaskArchiveTasksRepository implements TaskArchiveTasksRepositoryInterface
{
	public function __construct(private readonly TaskArchiveTasksMapper $mapper)
	{
	}

	public function saveLinks(TaskArchiveTasksCollection $tasksArchivesWorkflow): void
	{
		try
		{
			$this->mapper->convertCollectionToOrm($tasksArchivesWorkflow)->save(true);
		}
		catch (\Exception $exception)
		{
			throw new PersistenceException($exception->getMessage(), $exception);
		}
	}
}
