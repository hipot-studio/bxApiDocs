<?php

namespace Bitrix\Bizproc\Public\Command\WorkflowState\ClearStaleWorkflowCommand;

use Bitrix\Main\Result;

class ClearStaleWorkflowHandlerResult extends Result
{
	public function __construct(
		public readonly bool $isReachedLimit = false,
		public readonly ?int $lastStarted = null,
	)
	{
		parent::__construct();
	}
}
