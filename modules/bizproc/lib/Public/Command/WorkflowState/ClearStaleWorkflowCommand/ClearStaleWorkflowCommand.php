<?php

namespace Bitrix\Bizproc\Public\Command\WorkflowState\ClearStaleWorkflowCommand;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Config\Option;

class ClearStaleWorkflowCommand extends AbstractCommand
{
	private const DEFAULT_LIMIT = 50;
	public readonly int $limit;
	public readonly ?int $afterDate;

	public function __construct(?int $afterDate = null)
	{
		$this->limit = (int)Option::get('bizproc', 'clear_workflow_state_limit', self::DEFAULT_LIMIT);
		$this->afterDate = $afterDate;
	}

	protected function execute(): ClearStaleWorkflowResult
	{
		$handlerResult = (new ClearStaleWorkflowCommandHandler())($this);
		$oldestStarted = $handlerResult->lastStarted ?? $this->afterDate;

		return new ClearStaleWorkflowResult($handlerResult->isReachedLimit, $oldestStarted);
	}
}
