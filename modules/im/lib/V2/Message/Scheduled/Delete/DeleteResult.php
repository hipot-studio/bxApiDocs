<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Scheduled\Delete;

use Bitrix\Im\V2\Result;

/**
 * Result of {@see \Bitrix\Im\V2\Message\Scheduled\ScheduledMessageService::delete}.
 *
 * @extends Result<int>
 */
class DeleteResult extends Result
{
	public function setScheduledMessageId(int $scheduledMessageId): self
	{
		$this->setResult($scheduledMessageId);

		return $this;
	}

	public function getScheduledMessageId(): ?int
	{
		return $this->getResult();
	}
}
