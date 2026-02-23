<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Task\TaskArchive;

use Bitrix\Bizproc\Internal\Entity\BaseEntityCollection;

class TaskArchiveTasksCollection extends BaseEntityCollection
{
	public function __construct(TaskArchiveTasks ...$tasksArchivesWorkflow)
	{
		foreach ($tasksArchivesWorkflow as $taskArchivesWorkflow)
		{
			$this->collectionItems[] = $taskArchivesWorkflow;
		}
	}
}
