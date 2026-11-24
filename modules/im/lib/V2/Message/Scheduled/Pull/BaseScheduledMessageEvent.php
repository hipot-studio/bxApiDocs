<?php
declare(strict_types = 1);

namespace Bitrix\Im\V2\Message\Scheduled\Pull;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\BaseEvent;

abstract class BaseScheduledMessageEvent extends BaseEvent
{
	public function __construct(protected readonly int $authorId)
	{
		parent::__construct();
	}

	protected function getRecipients(): array
	{
		return [$this->authorId];
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return true;
	}

	public function getTarget(): ?Chat
	{
		return null;
	}
}
