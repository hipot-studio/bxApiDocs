<?php

declare(strict_types=1);


namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update;

use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Async\Message;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Task\Trait\OccurredUserTrait;
use Bitrix\Tasks\Internals\TaskObject;

class SendNotification
{
	use ConfigTrait;
	use OccurredUserTrait;

	public function __invoke(array $fields, array $sourceTaskData, TaskObject $task): void
	{
		if ($this->config->isSkipNotifications())
		{
			return;
		}

		$occurredUserId = $this->getOccurredUserId($this->config->getUserId());
		$notificationFields = array_merge($fields, ['CHANGED_BY' => $occurredUserId]);

		$config = [
			'AUTHOR_ID' => $occurredUserId,
			'SPAWNED_BY_AGENT' => false
		];

		(new Message\UpdateSendNotification(
			taskId: $task->getId(),
			accessibleChangedFields: $notificationFields,
			previousFields: $sourceTaskData,
			config: $config,
		))->sendByTaskId($task->getId());
	}
}
