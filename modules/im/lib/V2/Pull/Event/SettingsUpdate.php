<?php

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\BaseEvent;
use Bitrix\Im\V2\Pull\EventType;

class SettingsUpdate extends BaseEvent
{
	private int $userId;
	private array $settings;

	public function __construct(int $userId, array $settings)
	{
		parent::__construct();
		$this->userId = $userId;
		$this->settings = $settings;
		$this->expiry = 5;
	}

	protected function getRecipients(): array
	{
		return [$this->userId];
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return true;
	}

	protected function getBasePullParamsInternal(): array
	{
		return $this->settings;
	}

	protected function getType(): EventType
	{
		return EventType::SettingsUpdate;
	}

	public function getTarget(): ?Chat
	{
		return null;
	}
}
