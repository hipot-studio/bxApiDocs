<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Scheduled\SendNow;

use Bitrix\Im\V2\Result;

/**
 * Result of {@see \Bitrix\Im\V2\Message\Scheduled\ScheduledMessageService::sendNow()}.
 *
 * @extends Result<int>
 */
class SendNowResult extends Result
{
	public function setMessageId(int $messageId): self
	{
		$this->setResult($messageId);

		return $this;
	}

	public function getMessageId(): ?int
	{
		return $this->getResult();
	}
}
