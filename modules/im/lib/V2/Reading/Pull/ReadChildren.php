<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Pull;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\Event\BaseChatEvent;
use Bitrix\Im\V2\Pull\EventType;

class ReadChildren extends BaseChatEvent
{
	public function __construct(
		protected Chat $chat,
		protected int $userId,
	) {
		parent::__construct($chat);
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'chatId' => $this->chat->getChatId(),
		];
	}

	protected function getType(): EventType
	{
		return EventType::ReadChildren;
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return true;
	}

	protected function getRecipients(): array
	{
		return [$this->userId];
	}
}
