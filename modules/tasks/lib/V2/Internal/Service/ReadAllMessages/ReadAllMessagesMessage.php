<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\ReadAllMessages;

use Bitrix\Tasks\V2\Internal\Async\AbstractBaseMessage;
use Bitrix\Tasks\V2\Internal\Async\QueueId;

class ReadAllMessagesMessage extends AbstractBaseMessage
{
	/** @param int[] $chatIds */
	public function __construct(
		public int $userId,
		public array $chatIds,
	) {
	}

	public function jsonSerialize(): array
	{
		return [
			'userId' => $this->userId,
			'chatIds' => $this->chatIds,
		];
	}

	protected function getQueueId(): QueueId
	{
		return QueueId::ReadAllMessages;
	}
}
