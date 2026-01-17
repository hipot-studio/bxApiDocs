<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Pull;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\BaseEvent;
use Bitrix\Im\V2\Pull\EventType;

class ReadAll extends BaseEvent
{
	private int $userId;

	public function __construct(int $userId)
	{
		$this->userId = $userId;
		parent::__construct();
	}

	protected function getRecipients(): array
	{
		return [$this->userId];
	}

	protected function getBasePullParamsInternal(): array
	{
		return [];
	}

	protected function getType(): EventType
	{
		return EventType::ReadAll;
	}

	public function getTarget(): ?Chat
	{
		return null;
	}
}
