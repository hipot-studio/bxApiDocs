<?php

namespace Bitrix\Bizproc\Internal\Repository\WorkflowStateRepository;

use Bitrix\Bizproc\Internal\Entity\WorkflowState\WorkflowStateCollection;
use Bitrix\Bizproc\Internal\Repository\Mapper\WorkflowStateMapper;
use Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

class WorkflowStateRepository
{
	public function __construct(private readonly WorkflowStateMapper $mapper)
	{
	}

	public function getStaleWorkflowsWithoutTasks(
		array $select,
		Date $beforeDate,
		int $limit
	): WorkflowStateCollection
	{
		$query =
			WorkflowStateTable::query()
				->setSelect($select)
				->whereNull('INSTANCE.ID')
				->whereNull('TASKS.ID')
				->where('STARTED', '<', $beforeDate)
				->setLimit($limit)
		;
		$ormWorkflowStates = $query->fetchCollection();

		return $this->mapper->convertCollectionFromOrm($ormWorkflowStates);
	}

	public function getStuckWorkflows(
		array $select,
		DateTime $startPeriod,
		DateTime $endPeriod,
		Date $beforeDate,
		int $limit
	): WorkflowStateCollection
	{
		$query =
			WorkflowStateTable::query()
				->setSelect($select)
				->where('STARTED', '>=', $startPeriod)
				->where('STARTED', '<', $endPeriod)
				->where('INSTANCE.OWNED_UNTIL', '<', $beforeDate)
				->setLimit($limit)
		;
		$ormWorkflowStates = $query->fetchCollection();

		return $this->mapper->convertCollectionFromOrm($ormWorkflowStates);
	}
}
