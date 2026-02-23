<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\TaskArchiveRepository;

interface TaskArchiveRepositoryInterface
{
	/**
	 * @param \Bitrix\Bizproc\Internal\Entity\Task\TaskArchive\TaskArchive $taskArchive
	 * @return int|null
	 * @throws \Bitrix\Main\Repository\Exception\PersistenceException;
	 */
	public function saveArchive(\Bitrix\Bizproc\Internal\Entity\Task\TaskArchive\TaskArchive $taskArchive);
}
