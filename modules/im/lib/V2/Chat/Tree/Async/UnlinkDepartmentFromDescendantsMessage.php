<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree\Async;

use Bitrix\Im\V2\Async\QueueId;
use Bitrix\Main\Messenger\Entity\AbstractMessage;
use Bitrix\Main\Messenger\Entity\ProcessingParam\ItemIdParam;

/**
 * One bounded batch of the department down-cascade: "remove department $nodeId from the direct children of
 * $chatId whose ID > $cursor". The receiver re-enqueues the next batch (advancing the cursor) for wide
 * levels; each removal raises OnRelationDeleted(CHAT) on the child, which both drops its members and
 * enqueues that child's own down-cascade — so the tree is walked level by level, never in a single pass.
 */
class UnlinkDepartmentFromDescendantsMessage extends AbstractMessage
{
	public function __construct(
		public readonly int $chatId,
		public readonly int $nodeId,
		public readonly int $cursor = 0,
	)
	{
	}

	public function sendToQueue(): void
	{
		$this->send(QueueId::DepartmentTreeSync->value, [new ItemIdParam($this->getItemId())]);
	}

	public function withCursor(int $cursor): static
	{
		return new static(chatId: $this->chatId, nodeId: $this->nodeId, cursor: $cursor);
	}

	private function getItemId(): string
	{
		return "im_dept_unlink_{$this->chatId}_{$this->nodeId}_{$this->cursor}";
	}
}
