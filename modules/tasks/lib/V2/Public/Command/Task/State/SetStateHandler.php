<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\State;

use Bitrix\Tasks\V2\Internal\Service\Task\StateService;

class SetStateHandler
{
	public function __construct(
		private readonly StateService $stateService,
	)
	{

	}

	public function __invoke(SetStateCommand $command): void
	{
		$this->stateService->set($command->state, $command->userId);
	}
}
