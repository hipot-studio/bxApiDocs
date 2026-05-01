<?php

declare(strict_types=1);


namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update;

use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Trait\ConfigTrait;

class StopTimer
{
	use ConfigTrait;

	public function __invoke(array $fullTaskData, array $changes): void
	{
		$statusChanged = isset($changes['STATUS']) && is_array($changes['STATUS']);
		$timeTrackingChanged = isset($changes['ALLOW_TIME_TRACKING']) && is_array($changes['ALLOW_TIME_TRACKING']);
		if (!$statusChanged && !$timeTrackingChanged)
		{
			return;
		}

		$taskNotCompleted = (
			!in_array(
				(int)$fullTaskData['STATUS'],
				[Status::COMPLETED, Status::SUPPOSEDLY_COMPLETED],
				true,
			)
		);

		$timeTrackingNotDisabled = (
			!array_key_exists('ALLOW_TIME_TRACKING', $fullTaskData)
			|| $fullTaskData['ALLOW_TIME_TRACKING'] !== 'N'
		);

		if ($taskNotCompleted && $timeTrackingNotDisabled)
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
