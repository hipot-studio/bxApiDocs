<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\DockedUpload;

use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadQueue;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadQueueCollection;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemType;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Internal\Model\DockedUploadItemTable;
use Bitrix\Disk\Internal\Model\DockedUploadQueueTable;
use Bitrix\Disk\Internal\Model\EO_DockedUploadQueue;
use Bitrix\Disk\Internal\Repository\Interface\DockedUploadQueueRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Mapper\DockedUploadQueueMapper;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Type\DateTime;
use Throwable;

class DockedUploadQueueRepository implements DockedUploadQueueRepositoryInterface
{
	public function __construct(
		protected readonly DockedUploadQueueMapper $mapper = new DockedUploadQueueMapper(),
	)
	{
	}

	public function getById(int $id): ?DockedUploadQueue
	{
		/** @var EO_DockedUploadQueue|null $ormModel */
		$ormModel = DockedUploadQueueTable::getById($id)->fetchObject();

		if (!$ormModel instanceof EO_DockedUploadQueue)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormModel);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getByIds(array $ids, int $userId, int $chunkSize = 500): DockedUploadQueueCollection
	{
		$ids = array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		)));

		$collection = new DockedUploadQueueCollection();
		if ($ids === [] || $userId <= 0)
		{
			return $collection;
		}

		foreach (array_chunk($ids, max(1, $chunkSize)) as $chunk)
		{
			$ormCollection = DockedUploadQueueTable::query()
				->setSelect(['*'])
				->whereIn('ID', $chunk)
				->where('USER_ID', $userId)
				->fetchCollection()
			;

			foreach ($ormCollection as $ormModel)
			{
				$collection->add($this->mapper->convertFromOrm($ormModel));
			}
		}

		return $collection;
	}

	public function save(DockedUploadQueue $queue): void
	{
		try
		{
			$result = $this->mapper->convertToOrm($queue)->save();
		}
		catch (Throwable $exception)
		{
			throw new PersistenceException(
				message: $exception->getMessage(),
				previous: $exception,
			);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				message: 'Unable to save docked upload queue',
				errors: $result->getErrorMessages(),
			);
		}

		if (!is_int($queue->getId()))
		{
			$queue->setId((int)$result->getId());
		}
	}

	public function delete(int $id): void
	{
		try
		{
			$result = DockedUploadQueueTable::delete($id);
		}
		catch (Throwable $exception)
		{
			throw new PersistenceException(
				message: $exception->getMessage(),
				previous: $exception,
			);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				message: 'Unable to delete docked upload queue',
				errors: $result->getErrorMessages(),
			);
		}
	}

	/**
	 * Guarded lifecycle transition of a drained queue to completed (AC-077).
	 *
	 * A queue is drained when none of its FILE items is left outside a clean terminal
	 * state (committed / canceled). Folder-items are deliberately not looked at: they
	 * are grouping rows that never commit, their real state is the aggregate over the
	 * file leaves of their sub-tree. A leaf still in failed / interrupted_needs_file /
	 * duplicate_conflict keeps the queue actionable, so it is NOT drained and stays
	 * restorable - exactly the split the restore filter expects.
	 *
	 * The drain check lives inside the guarded UPDATE (NOT EXISTS over the item table)
	 * rather than in a read-then-write: two commits finishing the last leaves
	 * concurrently would both read a drained queue, and only one row transition may
	 * win. The status guard keeps a queue the user meanwhile dismissed from being
	 * resurrected.
	 *
	 * @return bool whether THIS call flipped the queue to completed
	 */
	public function markCompletedIfDrained(int $queueId): bool
	{
		if ($queueId <= 0)
		{
			return false;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$queueTable = DockedUploadQueueTable::getTableName();
		$itemTable = DockedUploadItemTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$completed = $helper->forSql(QueueStatus::Completed->value);
		// The queues that are not over yet, taken from the single declaration in the
		// enum: a stopped queue completes too when its last unfinished element is
		// cancelled, and a pending one when it is cancelled before it ever started.
		$open = implode(', ', array_map(
			static fn(string $status): string => "'" . $helper->forSql($status) . "'",
			QueueStatus::restorableValues(),
		));
		$fileType = $helper->forSql(ItemType::File->value);
		$committed = $helper->forSql(ItemStatus::Committed->value);
		$canceled = $helper->forSql(ItemStatus::Canceled->value);

		try
		{
			$connection->queryExecute("
				UPDATE {$queueTable}
				SET STATUS = '{$completed}', UPDATE_TIME = {$now}
				WHERE ID = {$queueId}
					AND STATUS IN ({$open})
					AND NOT EXISTS (
						SELECT 1 FROM {$itemTable}
						WHERE QUEUE_ID = {$queueId}
							AND ITEM_TYPE = '{$fileType}'
							AND STATUS NOT IN ('{$committed}', '{$canceled}')
					)
			");
		}
		catch (Throwable $exception)
		{
			throw new PersistenceException(
				message: $exception->getMessage(),
				previous: $exception,
			);
		}

		return $connection->getAffectedRowsCount() === 1;
	}

	/**
	 * Promotes a queue to active on the first transfer started in it (ENUM-02).
	 *
	 * A guarded UPDATE rather than a read-modify-write save(): several tabs may start
	 * their elements at once, and a full-row save built from a snapshot would race a
	 * quota stop applied meanwhile and resurrect the queue as active. The pending guard
	 * also makes the call idempotent - every later transfer in the queue is a no-op.
	 *
	 * @return bool whether THIS call promoted the queue
	 */
	public function markActiveIfPending(int $queueId): bool
	{
		if ($queueId <= 0)
		{
			return false;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$active = $helper->forSql(QueueStatus::Active->value);
		$pending = $helper->forSql(QueueStatus::Pending->value);

		try
		{
			$connection->queryExecute("
				UPDATE {$queueTable}
				SET STATUS = '{$active}', UPDATE_TIME = {$now}
				WHERE ID = {$queueId} AND STATUS = '{$pending}'
			");
		}
		catch (Throwable $exception)
		{
			throw new PersistenceException(
				message: $exception->getMessage(),
				previous: $exception,
			);
		}

		return $connection->getAffectedRowsCount() === 1;
	}

	/**
	 * Guarded lifecycle transition of a queue stopped in place back to active (API-07).
	 *
	 * The gate of {@see \Bitrix\Disk\Public\Command\DockedUpload\ResumeCommand} and its
	 * write in one statement, for the same reason {@see self::markActiveIfPending()} is
	 * one: a full-row save built from the snapshot the command read would race a
	 * concurrent cancel that drained the queue to completed - and resurrect it as active
	 * against the user's own cancellation. The status guard also makes a second resume of
	 * an already-restored queue a no-op instead of a repeated restore.
	 *
	 * @return bool whether THIS call restored the queue
	 */
	public function markActiveIfStopped(int $queueId): bool
	{
		if ($queueId <= 0)
		{
			return false;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$active = $helper->forSql(QueueStatus::Active->value);
		$stoppedByQuota = $helper->forSql(QueueStatus::StoppedByQuota->value);
		$requiresAction = $helper->forSql(QueueStatus::RequiresAction->value);

		try
		{
			$connection->queryExecute("
				UPDATE {$queueTable}
				SET STATUS = '{$active}', UPDATE_TIME = {$now}
				WHERE ID = {$queueId} AND STATUS IN ('{$stoppedByQuota}', '{$requiresAction}')
			");
		}
		catch (Throwable $exception)
		{
			throw new PersistenceException(
				message: $exception->getMessage(),
				previous: $exception,
			);
		}

		return $connection->getAffectedRowsCount() === 1;
	}

	/**
	 * Guarded lifecycle transition of a running queue to the stop state a space shortage
	 * earned it - the only write of a stop state
	 * ({@see \Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService::recordQuotaStop()}).
	 *
	 * One guarded UPDATE rather than a read-then-save for the same reason
	 * {@see self::markActiveIfStopped()} is one, and the window it closes is the widest of
	 * the family: a concurrent dismiss holds the row lock until ITS transaction ends, so the
	 * full-row save of a snapshot read before that would park on the lock and land right
	 * after the close - handing the user back a queue they dismissed, with a resume button
	 * over cancelled elements. The status guard also keeps the state the FIRST culprit
	 * earned: a queue already stopped is not re-stopped by a late report, and one that
	 * drained to completed meanwhile keeps its completion.
	 *
	 * @return bool whether THIS call stopped the queue
	 */
	public function markStoppedByQuota(int $queueId, QueueStatus $status): bool
	{
		if ($queueId <= 0)
		{
			return false;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$stopped = $helper->forSql($status->value);
		// The queues a commit is still fixed in, taken from the single declaration in the
		// enum: a stop is exactly what closes that gate, so it may only fire while it is open.
		$accepting = implode(', ', array_map(
			static fn(string $value): string => "'" . $helper->forSql($value) . "'",
			QueueStatus::commitAcceptingValues(),
		));

		try
		{
			$connection->queryExecute("
				UPDATE {$queueTable}
				SET STATUS = '{$stopped}', UPDATE_TIME = {$now}
				WHERE ID = {$queueId} AND STATUS IN ({$accepting})
			");
		}
		catch (Throwable $exception)
		{
			throw new PersistenceException(
				message: $exception->getMessage(),
				previous: $exception,
			);
		}

		return $connection->getAffectedRowsCount() === 1;
	}

	/**
	 * Marks the named unfinished queues dismissed - the widget-level close (SC-015) - in
	 * ONE statement whatever the number of queues, so the request cost of the close does
	 * not grow with how many queues the widget holds.
	 *
	 * The status guard is what implements AC-086: only a queue that is not over yet
	 * ({@see QueueStatus::restorableValues()}) becomes dismissed, while one that finished
	 * (or drained to completed while the close was running) keeps its completed state and
	 * its history. USER_ID is in the WHERE clause as defense-in-depth on top of the
	 * caller's own read.
	 *
	 * @param int[] $queueIds
	 * @return int number of queues actually dismissed
	 */
	public function markDismissedByIds(array $queueIds, int $userId, int $chunkSize = 500): int
	{
		$queueIds = array_values(array_unique(array_filter(
			array_map('intval', $queueIds),
			static fn(int $id): bool => $id > 0,
		)));

		if ($queueIds === [] || $userId <= 0)
		{
			return 0;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$dismissed = $helper->forSql(QueueStatus::Dismissed->value);
		$open = implode(', ', array_map(
			static fn(string $status): string => "'" . $helper->forSql($status) . "'",
			QueueStatus::restorableValues(),
		));

		$affected = 0;
		try
		{
			foreach (array_chunk($queueIds, max(1, $chunkSize)) as $chunk)
			{
				$idList = implode(',', $chunk);
				$connection->queryExecute("
					UPDATE {$queueTable}
					SET STATUS = '{$dismissed}', UPDATE_TIME = {$now}
					WHERE ID IN ({$idList})
						AND USER_ID = {$userId}
						AND STATUS IN ({$open})
				");
				$affected += $connection->getAffectedRowsCount();
			}
		}
		catch (Throwable $exception)
		{
			throw new PersistenceException(
				message: $exception->getMessage(),
				previous: $exception,
			);
		}

		return $affected;
	}

	/**
	 * Which of the named queues of the user are dismissed - the scope the widget-level close
	 * reads back right after its own guarded UPDATE.
	 *
	 * The close cannot take its list for granted: {@see self::markDismissedByIds()} leaves a
	 * queue that drained to completed meanwhile alone (AC-086), and the elements of that
	 * queue must then keep their statuses and its tabs must not be told it was dismissed.
	 * Answering "how many" is not enough for either - both need the ids - and re-reading them
	 * is one narrow statement per chunk, not one per queue.
	 *
	 * Meant to be called inside the transaction that did the UPDATE: the rows it names are
	 * the ones that transaction holds, so the answer cannot go stale between the two.
	 *
	 * @param int[] $queueIds
	 * @return int[]
	 */
	public function findDismissedIds(array $queueIds, int $userId, int $chunkSize = 500): array
	{
		$queueIds = array_values(array_unique(array_filter(
			array_map('intval', $queueIds),
			static fn(int $id): bool => $id > 0,
		)));

		if ($queueIds === [] || $userId <= 0)
		{
			return [];
		}

		$dismissedIds = [];
		foreach (array_chunk($queueIds, max(1, $chunkSize)) as $chunk)
		{
			$rows = DockedUploadQueueTable::query()
				->setSelect(['ID'])
				->whereIn('ID', $chunk)
				->where('USER_ID', $userId)
				->where('STATUS', QueueStatus::Dismissed->value)
				->exec()
			;

			while ($row = $rows->fetch())
			{
				$dismissedIds[] = (int)$row['ID'];
			}
		}

		return $dismissedIds;
	}

	/**
	 * The queues restore brings back (API-02) - the row form of
	 * {@see DockedUploadQueue::isRestorable()}, which is what the client is told
	 * through QueueDto. The predicate comes from {@see self::buildRestorableFilter()},
	 * so this selection and that verdict select the same queues (covered by
	 * DockedUploadQueueRepositoryTest).
	 */
	public function getActiveByUser(int $userId): DockedUploadQueueCollection
	{
		$ormCollection = DockedUploadQueueTable::query()
			->setSelect(['*'])
			->where($this->buildRestorableFilter($userId))
			->fetchCollection()
		;

		$collection = new DockedUploadQueueCollection();
		foreach ($ormCollection as $ormModel)
		{
			$collection->add($this->mapper->convertFromOrm($ormModel));
		}

		return $collection;
	}

	/**
	 * Whether the user has anything to restore at all - the cheap probe a page asks
	 * before it pays for the restore itself (P17.T4).
	 *
	 * Shares its predicate with {@see self::getActiveByUser()}: a probe allowed to drift
	 * from the selection would answer "nothing" over a queue restore does return, and the
	 * page would silently never bring it back. One row is the whole answer, and
	 * IX_DISK_DOCKED_UPLOAD_QUEUE_USER_ID_STATUS narrows the scan to it - EXPIRES_AT
	 * is then evaluated on the few rows read.
	 */
	public function hasActiveByUser(int $userId): bool
	{
		return (bool)DockedUploadQueueTable::query()
			->setSelect(['ID'])
			->where($this->buildRestorableFilter($userId))
			->setLimit(1)
			->fetch()
		;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The predicate is written out here instead of being taken from
	 * {@see self::buildRestorableFilter()} with its date comparison made optional: that
	 * filter is the row form of {@see DockedUploadQueue::isRestorable()} and the two must
	 * keep selecting the same queues, which a parameter would quietly end.
	 * IX_DISK_DOCKED_UPLOAD_QUEUE_USER_ID_STATUS covers this selection whole.
	 */
	public function getUnfinishedByUser(int $userId): DockedUploadQueueCollection
	{
		$ormCollection = DockedUploadQueueTable::query()
			->setSelect(['*'])
			->where('USER_ID', $userId)
			->whereIn('STATUS', QueueStatus::restorableValues())
			->fetchCollection()
		;

		$collection = new DockedUploadQueueCollection();
		foreach ($ormCollection as $ormModel)
		{
			$collection->add($this->mapper->convertFromOrm($ormModel));
		}

		return $collection;
	}

	/**
	 * "A queue of this user restore may bring back" as ONE reusable SQL predicate: the
	 * status set comes from the single declaration in {@see QueueStatus::restorableValues()}
	 * and EXPIRES_AT is compared against now exactly as
	 * {@see DockedUploadQueue::isRestorable()} does.
	 */
	private function buildRestorableFilter(int $userId): ConditionTree
	{
		return Query::filter()
			->where('USER_ID', $userId)
			->whereIn('STATUS', QueueStatus::restorableValues())
			->where('EXPIRES_AT', '>', new DateTime())
		;
	}
}
