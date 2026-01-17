<?php

declare(strict_types=1);


namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update;

use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Trait\ConfigTrait;

class StopTimer
{
	use ConfigTrait;

	public function __invoke(array $fullTaskData): void
	{
		if (
			!in_array(
				(int)$fullTaskData['STATUS'],
				[Status::COMPLETED, Status::SUPPOSEDLY_COMPLETED],
				true,
			)
		)
		{
			return;
		}

		$taskId = (int)$fullTaskData['ID'];
		$userIds = array_unique([
			$fullTaskData['CREATED_BY'],
			$fullTaskData['RESPONSIBLE_ID'],
			...$fullTaskData['ACCOMPLICES'],
		]);

		Container::getInstance()->getTimeManagementService()->stopAllTimers(
			taskId: $taskId,
			userIds: $userIds,
			currentUserId: $this->config->getUserId(),
		);
	}
}
