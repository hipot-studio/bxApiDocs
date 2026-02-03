<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Send\Mention;

use Bitrix\Im\V2\Message;

final class MentionChange
{
	public function __construct(
		public readonly array $addedUserIds,
		public readonly array $removedUserIds,
		public readonly array $currentUserIds,
		public readonly Message $message,
	)
	{
	}

	public static function fromMessages(Message $newMessage, ?Message $previousMessage = null): self
	{
		$newMentionedUserIds = $newMessage->getMentionedUserIds();
		unset($newMentionedUserIds[$newMessage->getAuthorId()]);

		if ($previousMessage === null)
		{
			return new self($newMentionedUserIds, [], $newMentionedUserIds, $newMessage);
		}

		$previousMentionedUserIds = $previousMessage->getMentionedUserIds();
		unset($previousMentionedUserIds[$previousMessage->getAuthorId()]);

		$toDelete = array_diff_key($previousMentionedUserIds, $newMentionedUserIds);
		$toAdd = array_diff_key($newMentionedUserIds, $previousMentionedUserIds);

		return new self($toAdd, $toDelete, $newMentionedUserIds, $newMessage);
	}
}
