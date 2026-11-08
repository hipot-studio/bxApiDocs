<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\Workflow\TerminateWorkflowCommand;

use Bitrix\Main\Command\AbstractCommand;

/**
 * Terminates the processes it is given by the standard termination of the platform.
 *
 * The command does not choose whom to terminate and knows nothing about where the ids came from: selecting
 * them is the business of the caller. It checks no rights of its own either - see $authorizedByCaller.
 *
 * Call it from a background job only. The termination of a single process runs the completion subscribers
 * of other modules, and their cost is not knowable here, so a batch does not belong in a web request.
 */
class TerminateWorkflowCommand extends AbstractCommand
{
	/**
	 * @param string|list<string> $workflowId one process or a batch of them
	 * @param bool $authorizedByCaller the caller states that it has authorized the operation. A required
	 *     argument, and documentation of the contract by type rather than a guard: the caller sets it
	 *     itself, so it is not a boundary. The command lives in the public layer next to the other write
	 *     commands, and the operation it performs is authorized where it is started - the right to publish
	 *     a template - while the batch itself walks documents the publisher may have no access to.
	 */
	public function __construct(
		public readonly string|array $workflowId,
		public readonly bool $authorizedByCaller,
	)
	{
	}

	protected function execute(): TerminateWorkflowCommandResult
	{
		return (new TerminateWorkflowCommandHandler())($this);
	}
}
