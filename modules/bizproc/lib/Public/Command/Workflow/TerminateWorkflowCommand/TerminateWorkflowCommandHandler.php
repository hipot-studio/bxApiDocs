<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\Workflow\TerminateWorkflowCommand;

use Bitrix\Main\Application;
use Bitrix\Main\Diag\ExceptionHandlerLog;
use Bitrix\Main\Error;

class TerminateWorkflowCommandHandler
{
	public function __invoke(TerminateWorkflowCommand $command): TerminateWorkflowCommandResult
	{
		$workflowIds = is_array($command->workflowId) ? $command->workflowId : [$command->workflowId];

		$terminatedCount = 0;
		$errors = [];
		// One record per call and not per process. The log of the exception handler is shared with every
		// other module of the portal and rotates at a megabyte, dropping what everyone else wrote; a
		// template broken at the head is re-read by every step of the job, so a record per process would
		// fill the file with one and the same trace and take the diagnostics of the portal with it. Every
		// refusal still reaches the caller as an error of the result - nothing is lost, only repeated.
		$isRefusalLogged = false;

		foreach ($workflowIds as $workflowId)
		{
			$terminationErrors = [];

			try
			{
				// The document is passed empty on purpose: the standard termination then skips comparing
				// the document of the row with the document of the process, and a discrepancy between the
				// two cannot turn "terminated" into "never terminates".
				\CBPDocument::terminateWorkflow((string)$workflowId, null, $terminationErrors);
			}
			catch (\Throwable $refusal)
			{
				// An activity whose class went away with its module, or a subscriber that breaks, refuses
				// with an \Error, which the standard termination does not catch: it catches \Exception
				// alone. Left to travel further such a refusal would take the whole pass with it and the
				// job would then break on every hit of the portal, so it joins the refusals of this instance.
				//
				// And the first such refusal of the call goes to the log of the exception handler, here where
				// it is intercepted: the caught throwable itself, so its trace goes with it. A message alone
				// says nothing about an \Error of a class that is gone with its module. There is nothing to
				// roll back - a termination is not a write of ours and shares no transaction - so the log is
				// all this interception does besides reporting the refusal to the caller.
				if (!$isRefusalLogged)
				{
					Application::getInstance()->getExceptionHandler()->writeToLog(
						$refusal,
						ExceptionHandlerLog::CAUGHT_EXCEPTION,
					);

					$isRefusalLogged = true;
				}

				$terminationErrors[] = [
					'code' => (int)$refusal->getCode(),
					'message' => $refusal->getMessage(),
				];
			}

			if (empty($terminationErrors))
			{
				$terminatedCount++;

				continue;
			}

			// A refusal on one process neither stops the batch nor rolls back what is already done:
			// terminations are independent of each other and share no transaction.
			foreach ($terminationErrors as $terminationError)
			{
				$errors[] = new Error(
					(string)($terminationError['message'] ?? ''),
					(int)($terminationError['code'] ?? 0),
				);
			}
		}

		$result = new TerminateWorkflowCommandResult($terminatedCount);
		$result->addErrors($errors);

		return $result;
	}
}
