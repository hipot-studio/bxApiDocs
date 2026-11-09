<?php

namespace Bitrix\Sender\Posting\SegmentThreadStrategy;

use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sender\Internals\Model\GroupThreadTable;
use Bitrix\Sender\PostingRecipientTable;

class TenThreadsStrategy extends AbstractThreadStrategy
{
	public const THREADS_COUNT = 10;

	/**
	 * Returns false if sending not available
	 * @return bool
	 */
	public function isProcessLimited(): bool
	{
		$maxParallelExecutions = \COption::GetOptionInt(
			"sender",
			"max_parallel_threads",
			10
		);

		$count = GroupThreadTable::getCount(
			[
				'=STATUS' => GroupThreadTable::STATUS_IN_PROGRESS,
				'>=EXPIRE_AT' => new DateTime(),
			]
		);

		// The caller asks before it takes a thread of its own, so the cap is reached already
		// when the busy threads are as many as the option allows. A thread whose capture has
		// expired is exactly the one lockThread() hands back to work, so it runs nothing and
		// takes no slot: counted, a handler that died mid window would hold a slot of the portal
		// for good, and at a cap of one it would stop every build of it. The bound mirrors the
		// capture, which takes a row back at EXPIRE_AT < now, so every row belongs to one of the
		// two sets and to none of them twice.
		return $count >= $maxParallelExecutions;
	}
}
