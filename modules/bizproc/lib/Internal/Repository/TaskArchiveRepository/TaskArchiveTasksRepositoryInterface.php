<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\TaskArchiveRepository;

/**
 * @param \Bitrix\Bizproc\Internal\Entity\Task\TaskArchive\TaskArchiveTasksCollection $tasksArchivesWorkflow
 *
 * @return void
 * @throws \Bitrix\Main\Repository\Exception\PersistenceException
 */
interface TaskArchiveTasksRepositoryInterface
{
	public function saveLinks(
		\Bitrix\Bizproc\Internal\Entity\Task\TaskArchive\TaskArchiveTasksCollection $tasksArchivesWorkflow
	): void;

}
