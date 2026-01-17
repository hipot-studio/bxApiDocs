<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Delete;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Delete\Trait\ConfigTrait;

class StopTimer
{
	use ConfigTrait;

	public function __invoke(array $fullTaskData)
	{
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
			sendNotification: false,
		);
	}
}
