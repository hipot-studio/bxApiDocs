<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\Workflow\TerminateWorkflowCommand;

use Bitrix\Main\Result;

class TerminateWorkflowCommandResult extends Result
{
	/**
	 * @param int $terminatedCount how many of the given processes were terminated without an error. The only
	 *     sign of progress for a caller that walks pages: zero means the batch as a whole did not give in.
	 */
	public function __construct(public readonly int $terminatedCount = 0)
	{
		parent::__construct();
	}
}
