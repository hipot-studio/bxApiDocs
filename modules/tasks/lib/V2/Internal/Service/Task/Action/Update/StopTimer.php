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

		$leftInProgress = (int)$fullTaskData['REAL_STATUS'] !== Status::IN_PROGRESS;

		$timeTrackingDisabled = (
			array_key_exists('ALLOW_TIME_TRACKING', $fullTaskData)
			&& $fullTaskData['ALLOW_TIME_TRACKING'] === 'N'
		);

		if (!$leftInProgress && !$timeTrackingDisabled)
		{
			return;
		}

		$taskId = (int)$fullTaskData['ID'];
		$userIds = array_values(array_unique([
			$fullTaskData['CREATED_BY'],
			$fullTaskData['RESPONSIBLE_ID'],
			...$fullTaskData['ACCOMPLICES'],
			...$this->getFormerMemberIds($changes),
		]));

		Container::getInstance()->getTimeManagementService()->stopAllTimers(
			taskId: $taskId,
			userIds: $userIds,
			currentUserId: $this->config->getUserId(),
		);
	}

	private function getFormerMemberIds(array $changes): array
	{
		$userIds = [];
		foreach (['RESPONSIBLE_ID', 'ACCOMPLICES'] as $field)
		{
			if (!isset($changes[$field]) || !is_array($changes[$field]))
			{
				continue;
			}

			$fromValue = (string)($changes[$field]['FROM_VALUE'] ?? '');
			if ($fromValue === '')
			{
				continue;
			}

			foreach (explode(',', $fromValue) as $userId)
			{
				$userId = (int)$userId;
				if ($userId > 0)
				{
					$userIds[] = $userId;
				}
			}
		}

		return $userIds;
	}
}
