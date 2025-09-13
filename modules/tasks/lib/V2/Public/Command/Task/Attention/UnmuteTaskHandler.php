<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Attention;

use Bitrix\Tasks\Internals\UserOption\Option;
use Bitrix\Tasks\V2\Internal\Service\Task\Option\Action\Mute\CollectCounter;
use Bitrix\Tasks\V2\Internal\Service\Task\Option\Action\Mute\Delete\RunCounterEvent;
use Bitrix\Tasks\V2\Internal\Service\Task\UserOptionService;
use Bitrix\Tasks\V2\Internal\Entity;

class UnmuteTaskHandler
{
	public function __construct(
		private readonly UserOptionService $userOptionService,
	)
	{

	}

	public function __invoke(UnmuteTaskCommand $command): void
	{
		$entity = new Entity\Task\UserOption(
			userId: $command->userId,
			taskId: $command->taskId,
			code: Option::MUTED,
		);

		(new CollectCounter())($entity);

		$this->userOptionService->delete($entity);

		(new RunCounterEvent())($entity);
	}
}