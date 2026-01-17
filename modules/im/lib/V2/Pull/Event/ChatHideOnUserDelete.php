<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\Helper;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\BaseEvent;
use Bitrix\Im\V2\Pull\EventType;

class ChatHideOnUserDelete extends BaseEvent
{
	private int $deletedUserId;

	public function __construct(int $deletedUserId)
	{
		$this->deletedUserId = $deletedUserId;
		parent::__construct();
	}

	protected function getRecipients(): array
	{
		return array_map('intval', Helper::getOnlineIntranetUsers());
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'dialogId' => $this->deletedUserId,
			'chatId' => null,
			'lines' => false,
			'recentConfigToHide' => null,
		];
	}

	protected function getType(): EventType
	{
		return EventType::ChatHide;
	}

	public function getTarget(): ?Chat
	{
		return null;
	}
}