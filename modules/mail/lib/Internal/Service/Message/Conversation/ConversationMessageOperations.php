<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Message\Conversation;

use Bitrix\Mail\Helper\Message\MessageActions;
use Bitrix\Main\Result;

/**
 * The single-message operations of the plain list, behind one narrow seam.
 *
 * Every method delegates to {@see MessageActions} as it is: a group action of the grid owns which
 * messages are touched, never how they are touched. Counters, IMAP flags, the blacklist and the
 * cascades of a deletion therefore keep the one owner they already have.
 *
 * The identifiers are the composite `{uidId}-{mailboxId}` ones the operations expect, and every answer
 * reports the elements it really changed in `getData()['processedIds']`.
 */
class ConversationMessageOperations
{
	/** @param string[] $compositeIds */
	public function markSeen(array $compositeIds): Result
	{
		return MessageActions::markAsSeen($compositeIds);
	}

	/** @param string[] $compositeIds */
	public function markUnseen(array $compositeIds): Result
	{
		return MessageActions::markAsUnseen($compositeIds);
	}

	/** @param string[] $compositeIds */
	public function move(array $compositeIds, string $folderPath, int $userId): Result
	{
		return MessageActions::moveToFolder($compositeIds, $folderPath, $userId);
	}

	/** @param string[] $compositeIds */
	public function delete(array $compositeIds, int $userId): Result
	{
		return MessageActions::delete($compositeIds, false, $userId);
	}

	/** @param string[] $compositeIds */
	public function markSpam(array $compositeIds, int $userId): Result
	{
		return MessageActions::markAsSpam($compositeIds, $userId);
	}
}
