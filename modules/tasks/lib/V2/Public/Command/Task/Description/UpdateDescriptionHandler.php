<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Description;

use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Internal\Service\Task\DescriptionService;

class UpdateDescriptionHandler
{
	public function __construct(
		private readonly DescriptionService $descriptionService,
	)
	{

	}

	public function __invoke(UpdateDescriptionCommand $command): Result
	{
		return $this->descriptionService->update(
			task: $command->task,
			userId: $command->userId,
			forceUpdate: $command->forceUpdate,
			useConsistency: $command->useConsistency,
		);
	}
}
