<?php

namespace Bitrix\Bizproc\Public\Command\WorkflowState\ClearStaleWorkflowCommand;

use Bitrix\Bizproc\Public\Provider\WorkflowStateProvider;
use Bitrix\Main\Type\Date;

class ClearStaleWorkflowCommandHandler
{

	public function __construct()
	{}

	public function __invoke(ClearStaleWorkflowCommand $command): ClearStaleWorkflowHandlerResult
	{
		$afterDate = null;
		if ($command->afterDate)
		{
			$afterDate = Date::createFromTimestamp($command->afterDate);
		}

		$workflows = (new WorkflowStateProvider)->getStaleWorkflowsWithoutTasks(
			['ID', 'STARTED'],
			Date::createFromTimestamp(strtotime('-1 year')),
			$command->limit,
			$afterDate,
		);

		foreach ($workflows as $workflow)
		{
			\CBPDocument::killCompletedWorkflowWithoutTasks($workflow->getId());
		}

		return new ClearStaleWorkflowHandlerResult(
			$workflows->count() === $command->limit,
			$workflows->getLastCollectionItem()?->getStarted()
		);
	}
}
