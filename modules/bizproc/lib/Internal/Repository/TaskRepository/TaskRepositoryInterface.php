<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\TaskRepository;

interface TaskRepositoryInterface
{

	/**
	 * @param array $select
	 * @param array $taskIds
	 * @return \Bitrix\Bizproc\Internal\Entity\Task\TaskCollection
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getTasksDataByIds(
		array $select,
		array $taskIds
	): \Bitrix\Bizproc\Internal\Entity\Task\TaskCollection;

	/**
	 * @param array $select
	 * @param int|null $limit
	 * @return \Bitrix\Bizproc\Internal\Entity\Task\TaskCollection
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getForArchive(
		array $select,
		array $filter = [],
		?int $limit = null
	): \Bitrix\Bizproc\Internal\Entity\Task\TaskCollection;

	/**
	 * @param int $id
	 * @return void
	 * @throws \Bitrix\Main\DB\SqlQueryException
	 */
	public function deleteTasksByIds(array $taskIds): void;
}
