<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat;

class AfterReadAllMessagesEvent extends ChatEvent
{
	public function __construct(ExternalChat $chat, int $readerId, int $lastMessageId = 0)
	{
		$parameters = ['readerId' => $readerId, 'lastMessageId' => $lastMessageId];

		parent::__construct($chat, $parameters);
	}

	protected function getActionName(): string
	{
		return 'AfterReadAllMessages';
	}

	public function getReaderId(): int
	{
		return $this->parameters['readerId'];
	}

	/**
	 * Snapshot boundary: the chat's last message id at the read-all moment.
	 * Consumers must mark only links whose IM_MESSAGE_ID <= this id, so a post whose
	 * system message arrived AFTER the read-all (cross-post in the gap before the
	 * deferred handler runs) is not falsely marked seen. 0 - no boundary (legacy).
	 */
	public function getLastMessageId(): int
	{
		return $this->parameters['lastMessageId'] ?? 0;
	}
}
