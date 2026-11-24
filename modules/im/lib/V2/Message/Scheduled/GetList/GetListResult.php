<?php

namespace Bitrix\Im\V2\Message\Scheduled\GetList;

use Bitrix\Im\V2\Result;

/**
 * Result of {@see \Bitrix\Im\V2\Message\Scheduled\ScheduledMessageService::getList}.
 *
 * @extends Result<array>
 */
class GetListResult extends Result
{
	public function setScheduledMessages(array $scheduledMessages): self
	{
		return $this->addToResult('scheduledMessages', $scheduledMessages);
	}

	public function setLastCursor(ScheduledMessageCursor $lastCursor): self
	{
		return $this->addToResult('lastCursor', $lastCursor);
	}

	public function getScheduledMessages(): array
	{
		return $this->getResult()['scheduledMessages'] ?? [];
	}

	public function getLastCursor(): ?ScheduledMessageCursor
	{
		return $this->getResult()['lastCursor'] ?? null;
	}
}
