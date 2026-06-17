<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Async\Message;

use Bitrix\Tasks\V2\Internal\Async\AbstractBaseMessage;
use Bitrix\Tasks\V2\Internal\Async\QueueId;

class UpdateSendNotification extends AbstractBaseMessage
{
	public function __construct(
		public readonly int $taskId,
		public readonly array $accessibleChangedFields,
		public readonly array $previousFields,
		public readonly array $config,
	)
	{

	}

	protected function getQueueId(): QueueId
	{
		return QueueId::UpdateSendNotification;
	}

	public function jsonSerialize(): array
	{
		return [
			'taskId' => $this->taskId,
			'accessibleChangedFields' => $this->serialiseDateTime(
				payload: $this->accessibleChangedFields,
				dateTimeKeys: ['DEADLINE', 'START_DATE_PLAN', 'END_DATE_PLAN'],
			),
			'previousFields' => $this->previousFields,
			'config' => $this->config,
		];
	}
}
