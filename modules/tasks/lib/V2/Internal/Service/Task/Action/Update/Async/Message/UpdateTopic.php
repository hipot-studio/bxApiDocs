<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Async\Message;

use Bitrix\Tasks\V2\Internal\Async\AbstractBaseMessage;
use Bitrix\Tasks\V2\Internal\Async\QueueId;

class UpdateTopic extends AbstractBaseMessage
{
	// TODO: Remove when UpdateTopic queue is completely empty.
	public function __construct(
		public readonly array $task
	)
	{

	}

	protected function getQueueId(): QueueId
	{
		return QueueId::UpdateTopic;
	}

	public function jsonSerialize(): array
	{
		return [
			'task' => $this->task,
		];
	}
}
