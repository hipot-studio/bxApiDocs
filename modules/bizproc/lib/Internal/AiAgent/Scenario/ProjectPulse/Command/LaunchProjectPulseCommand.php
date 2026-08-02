<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Scenario\ProjectPulse\Command;

use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\AiAgentStartResult;
use Bitrix\Main\Command\AbstractCommand;

final class LaunchProjectPulseCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $systemTemplateId,
		public readonly int $workgroupId,
		public readonly int $ownerId,
	) {}

	protected function execute(): AiAgentStartResult
	{
		return (new LaunchProjectPulseCommandHandler())($this);
	}
}
