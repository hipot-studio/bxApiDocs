<?php

namespace Bitrix\Im\V2\Reading\Notification\Pull;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\BaseEvent;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Reading\Notification\UnreadResult;

class UnreadNotifications extends BaseEvent
{
	private readonly UnreadResult $unreadResult;

	public function __construct(UnreadResult $unreadResult)
	{
		$this->unreadResult = $unreadResult;
		parent::__construct();
	}

	protected function getRecipients(): array
	{
		return [$this->unreadResult->userId];
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'chatId' => $this->unreadResult->chatId,
			'list' => array_values($this->unreadResult->unreadList),
			'counter' => $this->unreadResult->counter,
		];
	}

	protected function getType(): EventType
	{
		return EventType::UnreadNotifications;
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