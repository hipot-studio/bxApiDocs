<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\WorkflowState\ClearStuckWorkflowCommand;

use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

class ClearStuckWorkflowResult extends Result
{
	public function __construct(
		public readonly DateTime $startedDate
	)
	{
		parent::__construct();
	}
}
