<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree\Async;

use Bitrix\Im\V2\Chat\Tree\ChatTreeDepartmentSynchronizer;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;

class DepartmentTreeSyncReceiver extends AbstractReceiver
{
	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof UnlinkDepartmentFromDescendantsMessage)
		{
			throw new UnprocessableMessageException($message);
		}

		$batch = ServiceLocator::getInstance()
			->get(ChatTreeDepartmentSynchronizer::class)
			->unlinkFromDescendantsBatch($message->chatId, $message->nodeId, $message->cursor)
		;

		// Deeper levels are enqueued implicitly: unlinking a child raises OnRelationDeleted(CHAT) for it,
		// which enqueues that child's own down-cascade. Here we only advance to the next batch of this level.
		if ($batch->hasMore)
		{
			$message->withCursor($batch->lastId)->sendToQueue();
		}
	}
}
