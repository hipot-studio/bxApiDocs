<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\TaskArchiveRepository;

use Bitrix\Bizproc\Internal\Entity\Task\TaskArchive\TaskArchive;
use Bitrix\Bizproc\Internal\Repository\Mapper\TaskArchiveMapper;
use Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTable;
use Bitrix\Main\Repository\Exception\PersistenceException;

class TaskArchiveRepository implements TaskArchiveRepositoryInterface
{
	public function __construct(private readonly TaskArchiveMapper $mapper)
	{
	}

	public function saveArchive(TaskArchive $taskArchive)
	{
		try
		{
			$archiveId = $this->mapper->convertToOrm($taskArchive)->save()->getId();
			$taskArchive->setId($archiveId);

			return $taskArchive;
		}
		catch (\Exception $exception)
		{
			throw new PersistenceException($exception->getMessage(), $exception);
		}
	}

	public function getByWorkflowId(string $workflowId): ?TaskArchive
	{
		$query =
			TaskArchiveTable::query()
				->setSelect(['ID', 'WORKFLOW_ID', 'TASKS_DATA'])
				->where('WORKFLOW_ID', $workflowId)
		;
		$ormArchive = $query->fetchObject();

		return is_null($ormArchive) ? null : $this->mapper->convertFromOrm($ormArchive);
	}
}
