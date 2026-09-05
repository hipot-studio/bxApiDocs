<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\Message\Delete\DeletionMode;
use Bitrix\Im\V2\MessageCollection;

class AfterDeleteMessagesEvent extends ChatEvent implements InitiatedByUserInterface
{
	public function __construct(
		ExternalChat $chat,
		int $initiatorId,
		MessageCollection $messages,
		DeletionMode $deletionMode
	)
	{
		$parameters = ['initiatorId' => $initiatorId, 'messages' => $messages, 'deletionMode' => $deletionMode];

		parent::__construct($chat, $parameters);
	}

	protected function getActionName(): string
	{
		return 'AfterDeleteMessages';
	}

	public function getInitiatorId(): int
	{
		return $this->parameters['initiatorId'];
	}

	public function getMessages(): MessageCollection
	{
		return $this->parameters['messages'];
	}

	public function getDeletionMode(): DeletionMode
	{
		return $this->parameters['deletionMode'];
	}
}
