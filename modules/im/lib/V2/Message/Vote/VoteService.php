<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Vote;

use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Event;

class VoteService
{
	use ContextCustomer;

	private const MODULE_ID = 'im';
	private const EVENT_ON_VOTE_SEND = 'OnMessageVoteSend';

	/** Does not persist the vote — only fires an event for external consumers. */
	public function send(Message $message, Vote $vote): void
	{
		$userId = $this->getContext()->getUserId();

		$event = new Event(
			self::MODULE_ID,
			self::EVENT_ON_VOTE_SEND,
			[
				'message' => $message,
				'userId' => $userId,
				'vote' => $vote,
			]
		);
		$event->send();
	}
}
