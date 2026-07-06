<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Pull;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\AbstractBlock;
use Bitrix\Im\V2\Pull\Event\BaseMessageEvent;
use Bitrix\Im\V2\Pull\EventType;

class BuilderBlockAppend extends BaseMessageEvent
{
	protected ?AbstractBlock $block;

	public function __construct(Message $message, ?AbstractBlock $block)
	{
		parent::__construct($message);
		$this->block = $block;
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
			'block' => $this->block?->jsonSerialize(),
		];
	}

	protected function getType(): EventType
	{
		return EventType::BuilderBlockAppend;
	}
}
