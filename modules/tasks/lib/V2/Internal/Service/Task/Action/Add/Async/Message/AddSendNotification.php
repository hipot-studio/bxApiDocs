<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Async\Message;

use Bitrix\Tasks\V2\Internal\Async\AbstractBaseMessage;
use Bitrix\Tasks\V2\Internal\Async\QueueId;

class AddSendNotification extends AbstractBaseMessage
{
	public function __construct(
		public readonly int $taskId,
		public readonly array $config,
	)
	{

	}

	public function jsonSerialize(): array
	{
		return [
			'taskId' => $this->taskId,
			'config' => $this->config,
		];
	}

	protected function getQueueId(): QueueId
	{
		return QueueId::AddSendNotification;
	}
}
