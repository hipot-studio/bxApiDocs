<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Notification\Pull;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\BaseEvent;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Reading\Notification\ReadAllResult;

class ReadAllNotifications extends BaseEvent
{
	private readonly ReadAllResult $result;

	public function __construct(ReadAllResult $readResult)
	{
		$this->result = $readResult;
		parent::__construct();
	}

	protected function getRecipients(): array
	{
		return [$this->result->userId];
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'chatId' => $this->result->chatId,
			'excludeIds' => $this->result->excludeIds,
			'newCounter' => $this->result->counter,
		];
	}

	protected function getType(): EventType
	{
		return EventType::ReadAllNotifications;
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
