<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\Chat\GroupChat;

abstract class ChildChatEvent extends ChatEvent
{
	public function __construct(ExternalChat $chat, GroupChat $childChat, int $userId)
	{
		$parameters = [
			'childChat' => $childChat,
			'userId' => $userId,
		];

		parent::__construct($chat, $parameters);
	}

	public function getChildChat(): GroupChat
	{
		return $this->parameters['childChat'];
	}

	public function getUserId(): int
	{
		return (int)$this->parameters['userId'];
	}
}
