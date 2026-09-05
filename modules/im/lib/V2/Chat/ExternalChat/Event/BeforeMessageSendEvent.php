<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Send\SendingConfig;

class BeforeMessageSendEvent extends ChatEvent implements InitiatedByUserInterface
{
	public function __construct(ExternalChat $chat, int $initiatorId, Message $message)
	{
		$parameters = ['initiatorId' => $initiatorId, 'message' => $message];

		parent::__construct($chat, $parameters);
	}

	protected function getActionName(): string
	{
		return 'BeforeSendMessage';
	}

	public function getInitiatorId(): int
	{
		return $this->parameters['initiatorId'];
	}

	public function getMessage(): Message
	{
		return $this->parameters['message'];
	}
}
