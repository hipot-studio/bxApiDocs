<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Access;

use Bitrix\Tasks\V2\Internal\Service\Task\AddObserverOutcome;
use Bitrix\Tasks\V2\Internal\Service\Task\AddObserverService;

class AddObserverHandler
{
	public function __construct(
		private readonly AddObserverService $addObserverService,
	)
	{

	}

	public function __invoke(AddObserverCommand $command): AddObserverOutcome
	{
		return $this->addObserverService->addObserver(
			taskId: $command->taskId,
			currentUserId: $command->currentUserId,
			candidateId: $command->candidateId,
		);
	}
}
