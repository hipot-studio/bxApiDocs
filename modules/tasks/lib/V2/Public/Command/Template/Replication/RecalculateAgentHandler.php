<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template\Replication;

use Bitrix\Tasks\V2\Internal\Service\Template\Replication\RecalculateAgentService;

class RecalculateAgentHandler
{
	public function __construct(
		private readonly RecalculateAgentService $service,
	)
	{
	}

	public function __invoke(RecalculateAgentCommand $command): void
	{
		$this->service->recalculate($command->templateId);
	}
}
