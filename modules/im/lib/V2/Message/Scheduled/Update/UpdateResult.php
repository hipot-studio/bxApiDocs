<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Scheduled\Update;

use Bitrix\Im\V2\Message\Scheduled\ScheduledMessage;
use Bitrix\Im\V2\Result;

/**
 * Result of {@see \Bitrix\Im\V2\Message\Scheduled\ScheduledMessageService::update}.
 *
 * @extends Result<ScheduledMessage>
 */
class UpdateResult extends Result
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
