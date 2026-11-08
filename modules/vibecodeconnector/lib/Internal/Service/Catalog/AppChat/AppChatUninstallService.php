<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

use Bitrix\Main\Messenger\Internals\Storage\Db\Model\MessageStatus;
use Bitrix\Main\Messenger\Internals\Worker;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\QueueId;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Storage\VibecodeMessengerMessageTable;

final class AppChatUninstallService
{
	private readonly \Closure $drainPendingTeardowns;
	private readonly \Closure $countDeletionIntents;
	private readonly \Closure $makeNextDeletionIntentAvailable;
	private readonly \Closure $processDeletionQueue;
	private readonly \Closure $drainOrphanChats;

	public function __construct(
		?\Closure $drainPendingTeardowns = null,
		?\Closure $countDeletionIntents = null,
		?\Closure $makeNextDeletionIntentAvailable = null,
		?\Closure $processDeletionQueue = null,
		?\Closure $drainOrphanChats = null,
	) {
		$this->drainPendingTeardowns = $drainPendingTeardowns
			?? static fn () => (new AppChatTeardownService())->drainPendingTeardowns();
		$this->countDeletionIntents = $countDeletionIntents ?? self::countDeletionIntents(...);
		$this->makeNextDeletionIntentAvailable = $makeNextDeletionIntentAvailable
			?? self::makeNextDeletionIntentAvailable(...);
		$this->processDeletionQueue = $processDeletionQueue ?? static fn () => (new Worker(false))->process([
			'queues' => [QueueId::AppChatDeletion->value],
		]);
		$this->drainOrphanChats = $drainOrphanChats
			?? static fn () => (new AppChatOrphanCleanupService(gracePeriodSeconds: 0))->drain();
	}

	public function cleanup(): void
	{
		($this->drainPendingTeardowns)();
		$pendingBefore = ($this->countDeletionIntents)();
		while ($pendingBefore > 0)
		{
			($this->makeNextDeletionIntentAvailable)();
			($this->processDeletionQueue)();
			$pendingAfter = ($this->countDeletionIntents)();
			if ($pendingAfter >= $pendingBefore)
			{
				throw new \RuntimeException(
					"Unable to resolve {$pendingAfter} pending app-chat deletion intent(s)",
				);
			}

			$pendingBefore = $pendingAfter;
		}
		($this->drainOrphanChats)();
	}

	private static function countDeletionIntents(): int
	{
		return (int)VibecodeMessengerMessageTable::query()
			->where('QUEUE_ID', QueueId::AppChatDeletion->value)
			->queryCountTotal()
		;
	}

	private static function makeNextDeletionIntentAvailable(): void
	{
		$row = VibecodeMessengerMessageTable::query()
			->setSelect(['ID'])
			->where('QUEUE_ID', QueueId::AppChatDeletion->value)
			->where('STATUS', MessageStatus::New->value)
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;
		if ($row === false)
		{
			return;
		}

		VibecodeMessengerMessageTable::update(
			(int)$row['ID'],
			['AVAILABLE_AT' => DateTime::createFromTimestamp(time() - 1)],
		);
	}
}
