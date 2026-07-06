<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Pull;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Pull\Event\BaseMessageEvent;
use Bitrix\Im\V2\Pull\EventType;

class BuilderBlockDelete extends BaseMessageEvent
{
	protected string $blockId;

	public function __construct(Message $message, string $blockId)
	{
		parent::__construct($message);
		$this->blockId = $blockId;
	}

	protected function getRecipients(): array
	{
		return $this->chat->getRelations()->filterActive()->getUserIds();
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return false;
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'messageId' => $this->message->getId(),
			'chatId' => $this->message->getChatId(),
			'text' => $this->message->getParsedMessage(),
			'blockId' => $this->blockId,
		];
	}

	protected function getType(): EventType
	{
		return EventType::BuilderBlockDelete;
	}
}
