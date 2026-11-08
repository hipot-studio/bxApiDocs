<?php

declare(strict_types=1);


namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update;

use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Trait\ConfigTrait;

class StartTimer
{
	use ConfigTrait;

	public function __invoke(array $fullTaskData, array $changes): void
	{
		$statusChanged = isset($changes['STATUS']) && is_array($changes['STATUS']);
		if (!$statusChanged)
		{
			return;
		}

		if ((int)$fullTaskData['REAL_STATUS'] !== Status::IN_PROGRESS)
		{
			return;
		}

		$timeTrackingDisabled = (
			array_key_exists('ALLOW_TIME_TRACKING', $fullTaskData)
			&& $fullTaskData['ALLOW_TIME_TRACKING'] === 'N'
		);
		if ($timeTrackingDisabled)
		{
			return;
		}

		$taskId = (int)$fullTaskData['ID'];
		$responsibleId = (int)$fullTaskData['RESPONSIBLE_ID'];

		$container = Container::getInstance();

		$trackingUserIds = array_map('intval', [
			$fullTaskData['CREATED_BY'] ?? 0,
			$responsibleId,
			...($fullTaskData['ACCOMPLICES'] ?? []),
			$this->config->getUserId(),
		]);

		$runningTimerUserIds = $container->getTimerRepository()->getRunningTimersByTaskId($taskId)->getUserIdList();

		$taskIsTracked = !empty(array_intersect($runningTimerUserIds, $trackingUserIds));
		if ($taskIsTracked)
		{
			return;
		}

		$container->getTimeManagementService()->startTimer(
			userId: $responsibleId,
			taskId: $taskId,
			syncPlan: true,
			canStart: false,
			canRenew: false,
		);
	}
}
