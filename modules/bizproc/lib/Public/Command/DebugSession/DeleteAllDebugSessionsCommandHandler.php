<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\DebugSession;

use Bitrix\Bizproc\Public\Service\TestRun\TestRunService;

class DeleteAllDebugSessionsCommandHandler
{
	public function __invoke(DeleteAllDebugSessionsCommand $command): int
	{
		return (new TestRunService())->clearJournal($command->userId);
	}
}
