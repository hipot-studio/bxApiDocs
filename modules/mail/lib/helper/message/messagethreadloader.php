<?php

namespace Bitrix\Mail\Helper\Message;

use Bitrix\Mail\Internals\MessageClosureTable;

class MessageThreadLoader
{
	/** Ceiling for the ids assembled from closure; legitimate threads are far smaller. */
	public const MAX_THREAD_MESSAGE_IDS = 500;

	/** @var int[] branch members other than the anchor */
	private array $branchMessageIds = [];

	public function __construct(
		private int $messageId,
	){}

	public function getMessageId(): int
	{
		return $this->messageId;
	}

	/** @return int[] ascending, the anchor always among them */
	public function getThreadMessageIds(): array
	{
		$threadMessageIds = $this->branchMessageIds;
		$threadMessageIds[] = $this->messageId;
		sort($threadMessageIds);

		return $threadMessageIds;
	}

	/** drops the assembled branch, leaving getThreadMessageIds() with the anchor alone */
	public function clearThreadMessageIds(): void
	{
		$this->branchMessageIds = [];
	}

	/**
	 * The branch of the conversation the anchor belongs to - its ancestors and the replies to it,
	 * without the parallel branches. Ids are never compared with one another: a message resynced
	 * out of order carries an id smaller than its own parent's, so an id-based filter would drop it.
	 */
	public function loadThreadBranchMessageIds(): void
	{
		$this->clearThreadMessageIds();

		$anchorId = $this->messageId;

		$branchIds = [];
		foreach ($this->selectAncestorIds($anchorId) as $id)
		{
			$branchIds[$id] = true;
		}
		foreach ($this->selectDescendantIds($anchorId) as $id)
		{
			$branchIds[$id] = true;
		}

		// getThreadMessageIds() always serves the anchor back, so leave it a slot under the ceiling
		unset($branchIds[$anchorId]);

		$ids = array_keys($branchIds);
		rsort($ids);

		$this->branchMessageIds = array_slice($ids, 0, self::MAX_THREAD_MESSAGE_IDS - 1);
	}

	/**
	 * The limit is not a property of the thread: MSG_ID is not unique and comes from the remote
	 * sender, so CMail::makeMessageClosureChain writes an ancestor row per message carrying that
	 * MSG_ID and the row count becomes external input. Newest ids first, so a branch over the
	 * ceiling keeps its recent part.
	 */
	protected function selectAncestorIds(int $anchorId): array
	{
		$rows = MessageClosureTable::query()
			->setSelect(['PARENT_ID'])
			->where('MESSAGE_ID', $anchorId)
			->setOrder(['PARENT_ID' => 'DESC'])
			->setLimit(self::MAX_THREAD_MESSAGE_IDS)
			->fetchAll()
		;

		$ids = [];
		foreach ($rows as $row)
		{
			$ids[] = (int)$row['PARENT_ID'];
		}

		return $ids;
	}

	/** The same closure rows read the other way round, over IX_MAIL_MESSAGE_CL_R. */
	protected function selectDescendantIds(int $anchorId): array
	{
		$rows = MessageClosureTable::query()
			->setSelect(['MESSAGE_ID'])
			->where('PARENT_ID', $anchorId)
			->setOrder(['MESSAGE_ID' => 'DESC'])
			->setLimit(self::MAX_THREAD_MESSAGE_IDS)
			->fetchAll()
		;

		$ids = [];
		foreach ($rows as $row)
		{
			$ids[] = (int)$row['MESSAGE_ID'];
		}

		return $ids;
	}
}
