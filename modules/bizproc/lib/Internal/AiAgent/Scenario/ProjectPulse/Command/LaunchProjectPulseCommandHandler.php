<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Scenario\ProjectPulse\Command;

use Bitrix\Bizproc\Internal\AiAgent\Service\AgentLauncher;
use Bitrix\Bizproc\Internal\AiAgent\Service\LaunchAgentRequest;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\AiAgentStartResult;

final class LaunchProjectPulseCommandHandler
{
	private readonly AgentLauncher $launcher;

	public function __construct(?AgentLauncher $launcher = null)
	{
		$this->launcher = $launcher ?? AgentLauncher::create();
	}

	public function __invoke(LaunchProjectPulseCommand $command): AiAgentStartResult
	{
		return $this->launcher->launch(new LaunchAgentRequest(
			systemTemplateId: $command->systemTemplateId,
			userId: $command->ownerId,
			constants: ['Project' => (string)$command->workgroupId, 'BotName' => 'test', 'ScheduleAnalysisStartTime' => '10:00 [0]'],
		));
	}
}
