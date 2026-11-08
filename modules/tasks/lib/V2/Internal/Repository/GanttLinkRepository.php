<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Internals\Task\ProjectDependenceTable;
use Bitrix\Tasks\V2\Internal\Entity\Task\GanttLink;
use Bitrix\Tasks\V2\Internal\Entity\Task\GanttLinkCollection;
use Bitrix\Tasks\V2\Internal\Exception\Task\TreeLinkException;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Task\Gantt\GanttLinkMapper;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Task\Gantt\LinkTypeMapper;
use Exception;

class GanttLinkRepository implements GanttLinkRepositoryInterface
{
	public function __construct(
		private readonly GanttLinkMapper $ganttLinkMapper,
		private readonly LinkTypeMapper $linkTypeMapper,
	)
	{

	}

	public function getLinkTypes(int $taskId, array $dependentIds): array
	{
		$rows = ProjectDependenceTable::query()
			->setSelect(['TASK_ID', 'DEPENDS_ON_ID', 'TYPE'])
			->where('TASK_ID', $taskId)
			->whereIn('DEPENDS_ON_ID', $dependentIds)
			->fetchAll()
		;

		if (empty($rows))
		{
			return [];
		}

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['DEPENDS_ON_ID']][(int)$row['TASK_ID']] = $this->linkTypeMapper->mapToEnum((int)$row['TYPE']);
		}

		return $result;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getTaskLinks(int $taskId, ?int $limit = null, ?int $offset = null): GanttLinkCollection
	{
		$query = ProjectDependenceTable::query()
			->setSelect(['TASK_ID', 'DEPENDS_ON_ID', 'TYPE', 'CREATOR_ID'])
			->where('TASK_ID', $taskId)
			->where('DIRECT', 1)
			->whereNot('DEPENDS_ON_ID', $taskId)
			->setOrder(['DEPENDS_ON_ID' => 'ASC'])
		;

		if ($limit !== null)
		{
			$query->setLimit($limit);
		}

		if ($offset !== null)
		{
			$query->setOffset($offset);
		}

		$rows = $query->exec()->fetchAll();

		if (empty($rows))
		{
			return new GanttLinkCollection();
		}

		return $this->ganttLinkMapper->mapToCollection($rows);
	}

	public function getLinksByTaskIds(array $taskIds): GanttLinkCollection
	{
		if (empty($taskIds))
		{
			return new GanttLinkCollection();
		}

		$rows = ProjectDependenceTable::query()
			->setSelect(['TASK_ID', 'DEPENDS_ON_ID', 'TYPE', 'CREATOR_ID'])
			->whereIn('TASK_ID', $taskIds)
			->exec()
			->fetchAll()
		;

		if (empty($rows))
		{
			return new GanttLinkCollection();
		}

		return $this->ganttLinkMapper->mapToCollection($rows);
	}

	public function update(GanttLink $ganttLink): void
	{
		$primary = [
			'TASK_ID' => $ganttLink->taskId,
			'DEPENDS_ON_ID' => $ganttLink->dependentId,
		];

		try
		{
			$result = ProjectDependenceTable::update($primary, [
				'TYPE' => $this->linkTypeMapper->mapFromEnum($ganttLink->type),
			]);
		}
		catch (Exception $e)
		{
			throw new TreeLinkException($e->getMessage());
		}

		if (!$result->isSuccess())
		{
			throw new TreeLinkException($result->getError()?->getMessage());
		}
	}

	public function containsLinks(int $taskId): bool
	{
		$result = ProjectDependenceTable::query()
			->setSelect([new ExpressionField('EXISTS', 1)])
			->where('TASK_ID', $taskId)
			->whereNot('DEPENDS_ON_ID', $taskId)
			->setLimit(1)->fetch()
		;

		return $result !== false;
	}
}
