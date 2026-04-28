<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Notification\Pull;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\BaseEvent;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Reading\Notification\ReadResult;

class ReadNotifications extends BaseEvent
{
	private readonly ReadResult $readResult;

	public function __construct(ReadResult $readResult)
	{
		$this->readResult = $readResult;
		parent::__construct();
	}

	protected function getRecipients(): array
	{
		return [$this->readResult->userId];
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'chatId' => $this->readResult->chatId,
			'list' => array_values($this->readResult->readList),
			'counter' => $this->readResult->counter,
		];
	}

	protected function getType(): EventType
	{
		return EventType::ReadNotifications;
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
