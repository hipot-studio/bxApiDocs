<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\WorkflowState\ClearStuckWorkflowCommand;

use Bitrix\Bizproc\Internal\Repository\WorkflowStateRepository\WorkflowStateRepository;
use Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

class ClearStuckWorkflowCommandHandler
{

	public function __construct(private readonly WorkflowStateRepository $workflowStateRepository)
	{}

	public function __invoke(ClearStuckWorkflowCommand $command): DateTime
	{
		$startedDate = $command->startedDate;
		if (!$startedDate)
		{
			$workflowState =
				WorkflowStateTable::query()
					->setSelect(['STARTED'])
					->whereNotNull('STARTED')
					->setOrder('STARTED')
					->setLimit(1)
					->fetchObject()
			;
			$startedDate = $workflowState?->getStarted() ?? new DateTime();
		}

		if ($startedDate > Date::createFromTimestamp(strtotime('-1 year')))
		{
			return $startedDate;
		}

		$startPeriod = DateTime::createFromPhp(
			(new \DateTime())
				->setTimestamp($startedDate->getTimestamp())
				->modify('first day of this month')
				->setTime(0, 0, 0)
		);
		$endPeriod = DateTime::createFromPhp(
			(new \DateTime())
				->setTimestamp($startedDate->getTimestamp())
				->modify('last day of this month')
				->setTime(23, 59, 59)
		);
		$workflows = $this->workflowStateRepository->getStuckWorkflows(
			['ID'],
			$startPeriod,
			$endPeriod,
			Date::createFromTimestamp(strtotime('-1 year')),
			$command->limit,
		);

		foreach ($workflows as $workflow)
		{
			\CBPDocument::killWorkflow($workflow->getId(), false);
		}

		if ($workflows->count() === $command->limit)
		{
			return $startedDate;
		}

		return $startedDate->add('+1 months');
	}
}
