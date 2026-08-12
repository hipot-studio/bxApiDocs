<?php

namespace Bitrix\Im\V2\Sync\Recent;

use Bitrix\Im\V2\Recent\RecentItem;

class RecentSyncItem extends RecentItem
{
	public function toRestFormat(array $option = []): array
	{
		$rest = [
			'dialogId' => $this->dialogId,
			'chatId' => $this->chatId,
			'messageId' => $this->messageId,
			'pinned' => $this->pinned,
			'unread' => $this->unread,
			'dateUpdate' => $this->dateUpdate,
			'dateLastActivity' => $this->dateLastActivity,
		];

		// This override uses a sync-specific field set, so ownMessageId must be re-emitted here.
		// Always emit it (mirrors the parent): the client never derives it, derive is gone.
		if ($this->ownMessageId > 0)
		{
			$rest['ownMessageId'] = $this->ownMessageId;
		}

		return $rest;
	}
}
