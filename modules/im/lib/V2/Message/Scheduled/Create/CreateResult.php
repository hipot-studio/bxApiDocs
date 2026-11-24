<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Scheduled\Create;

use Bitrix\Im\V2\Message\Scheduled\ScheduledMessage;
use Bitrix\Im\V2\Result;

/**
 * Result of {@see \Bitrix\Im\V2\Message\Scheduled\ScheduledMessageService::create}.
 *
 * @extends Result<ScheduledMessage>
 */
class CreateResult extends Result
{
	public function setScheduledMessage(ScheduledMessage $scheduledMessage): self
	{
		$this->setResult($scheduledMessage);

		return $this;
	}

	public function getScheduledMessage(): ?ScheduledMessage
	{
		return $this->getResult();
	}
}
