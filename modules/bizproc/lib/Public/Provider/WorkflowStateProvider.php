<?php

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\WorkflowState\WorkflowStateCollection;
use Bitrix\Bizproc\Internal\Repository\WorkflowStateRepository\WorkflowStateRepository;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

class WorkflowStateProvider
{
	private WorkflowStateRepository $repository;

	public function __construct()
	{
		$this->repository = Container::getWorkflowStateRepository();
	}

	public function getStaleWorkflowsWithoutTasks(
		array $select,
		Date $beforeDate,
		int $limit
	): WorkflowStateCollection
	{
		return $this->repository->getStaleWorkflowsWithoutTasks(
			$select,
			$beforeDate,
			$limit,
		);
	}
}
