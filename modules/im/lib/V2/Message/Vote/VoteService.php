<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Vote;

use Bitrix\Im\V2\Analytics\CopilotAnalytics;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Vote\Event\CollectVotesEvent;
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

		// BitrixGPT v2 ai-label: add_reaction for copilot chats under flag
		if ($message->getChat() instanceof CopilotChat && Features::isBitrixGptV2Available())
		{
			(new CopilotAnalytics($message->getChat()))
				->withContext($this->getContext())
				->addAddReaction($message, $vote)
			;
		}
	}

	/**
	 * Collects votes for the given message ids on behalf of $userId.
	 * Fires OnMessageVoteCollect event; subscribers return SUCCESS results
	 * with [messageId => ('like'|'dislike')[]] maps.
	 *
	 * @param int[] $messageIds
	 * @return array<int, Vote[]> [messageId => Vote[]]
	 */
	public function collectVotes(array $messageIds, int $userId): array
	{
		if (empty($messageIds))
		{
			return [];
		}

		$event = new CollectVotesEvent($messageIds, $userId);
		$event->send();

		return $event->getCollectedVotes();
	}
}
