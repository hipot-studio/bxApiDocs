<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\DockedUpload;

use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItemCollection;
use Bitrix\Disk\Internal\Enum\DockedUpload\ConflictPolicy;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemType;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Internal\Model\DockedUploadItemTable;
use Bitrix\Disk\Internal\Model\DockedUploadQueueTable;
use Bitrix\Disk\Internal\Model\EO_DockedUploadItem;
use Bitrix\Disk\Internal\Repository\Mapper\DockedUploadItemMapper;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlHelper;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Type\DateTime;
use Throwable;

/**
 * Single ORM access point to the docked upload item table.
 *
 * Every list/token lookup is scoped by USER_ID through the QUEUE reference
 * (USER_ID lives on the queue), keeping the isolation invariant.
 */
class DockedUploadItemRepository
{
	public function __construct(
		protected readonly DockedUploadItemMapper $mapper = new DockedUploadItemMapper(),
	)
	{
	}

	public function getById(int $id): ?DockedUploadItem
	{
		/** @var EO_DockedUploadItem|null $ormModel */
		$ormModel = DockedUploadItemTable::getById($id)->fetchObject();

		if (!$ormModel instanceof EO_DockedUploadItem)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormModel);
	}

	public function save(DockedUploadItem $item): void
	{
		try
		{
			$result = $this->mapper->convertToOrm($item)->save();
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
				message: 'Unable to save docked upload item',
				errors: $result->getErrorMessages(),
			);
		}

		if (!is_int($item->getId()))
		{
			$item->setId((int)$result->getId());
		}
	}

	/**
	 * Inserts a collection of new items with a single multi-row INSERT instead
	 * of one round-trip per item.
	 *
	 * Note: the multi-row path ignores ORM events, and the underlying driver
	 * does not report per-row auto-increment IDs, so the entities are NOT
	 * populated with generated IDs. Use save() when the caller needs the ID
	 * back (e.g. to link child items to a freshly inserted parent).
	 */
	public function saveMany(DockedUploadItemCollection $items): void
	{
		if ($items->isEmpty())
		{
			return;
		}

		$rows = [];
		foreach ($items as $item)
		{
			$rows[] = ['__object' => $this->mapper->convertToOrm($item)];
		}

		try
		{
			$result = DockedUploadItemTable::addMulti($rows, true);
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
				message: 'Unable to save docked upload items',
				errors: $result->getErrorMessages(),
			);
		}
	}

	public function delete(int $id): void
	{
		try
		{
			$result = DockedUploadItemTable::delete($id);
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
				message: 'Unable to delete docked upload item',
				errors: $result->getErrorMessages(),
			);
		}
	}

	/**
	 * Removes every item of a queue with a single DELETE instead of one
	 * round-trip per row. USER_ID isolation is the caller's responsibility
	 * (the caller has already resolved the owned/expired queue).
	 */
	public function deleteByQueue(int $queueId): void
	{
		$connection = Application::getConnection();
		$tableName = DockedUploadItemTable::getTableName();

		try
		{
			$connection->queryExecute(
				"DELETE FROM {$tableName} WHERE QUEUE_ID = {$queueId}",
			);
		}
		catch (Throwable $exception)
		{
			throw new PersistenceException(
				message: $exception->getMessage(),
				previous: $exception,
			);
		}
	}

	/**
	 * Batch status transition for an explicit set of items - one UPDATE per
	 * chunk (default 500 ids) instead of one round-trip per row.
	 *
	 * USER_ID isolation is baked into the WHERE clause itself
	 * (QUEUE_ID IN (SELECT ID FROM queue WHERE USER_ID = ...)), the same
	 * defense-in-depth gate {@see \Bitrix\Disk\Internal\Service\DockedUpload\OwnershipService}
	 * uses: even if $ids leaked from an already-filtered read, a foreign item
	 * can never be flipped by this call.
	 *
	 * Terminal rows (committed / canceled) are guarded out of the UPDATE, mirroring
	 * {@see self::updateTempToken()} and the updateStatusByQueue excludeStatuses gate:
	 * callers resolve the target ids from an in-memory snapshot, so a row that raced to
	 * a terminal status (e.g. a concurrent commit) must not be flipped back by a cancel
	 * or retry replaying that stale snapshot.
	 *
	 * @param int[] $ids
	 * @param array<string, scalar|null> $extraFields extra columns to set alongside STATUS, e.g. ['ERROR_CODE' => null]
	 * @return int number of rows actually updated
	 */
	public function updateStatusByIds(array $ids, ItemStatus $status, int $userId, array $extraFields = [], int $chunkSize = 500): int
	{
		$ids = array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		)));

		if ($ids === [] || $userId <= 0)
		{
			return 0;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$itemTable = DockedUploadItemTable::getTableName();
		$queueTable = DockedUploadQueueTable::getTableName();
		$setSql = $this->buildStatusSetSql($status, $extraFields, $helper);
		$committed = ItemStatus::Committed->value;
		$canceled = ItemStatus::Canceled->value;

		$affected = 0;
		try
		{
			foreach (array_chunk($ids, max(1, $chunkSize)) as $chunk)
			{
				$idList = implode(',', $chunk);
				$connection->queryExecute("
					UPDATE {$itemTable}
					SET {$setSql}
					WHERE ID IN ({$idList})
						AND STATUS NOT IN ('{$committed}', '{$canceled}')
						AND QUEUE_ID IN (SELECT ID FROM {$queueTable} WHERE USER_ID = {$userId})
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
	 * Batch-binds items to one target folder in a single UPDATE per folder, replacing
	 * a per-row save() when a folder-tree build routes many files into the same folder.
	 * The caller groups ids by TARGET_FOLDER_ID (one call per distinct folder). USER_ID
	 * isolation via the queue subquery; terminal rows are left untouched.
	 *
	 * $storageId, when known, rides along in the same SET: every folder of a docked tree
	 * lives under the same root, so the whole build shares one storage and stamping it costs
	 * no extra statement.
	 *
	 * @param int[] $ids
	 * @return int rows updated
	 */
	public function bindTargetFolderByIds(
		array $ids,
		int $targetFolderId,
		int $userId,
		?int $storageId = null,
		int $chunkSize = 500,
	): int
	{
		$ids = array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		)));

		if ($ids === [] || $userId <= 0)
		{
			return 0;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$itemTable = DockedUploadItemTable::getTableName();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$setSql = $helper->quote('TARGET_FOLDER_ID') . ' = ' . (int)$targetFolderId
			. ', ' . $helper->quote('UPDATE_TIME') . ' = ' . $now;
		if ($storageId !== null && $storageId > 0)
		{
			$setSql .= ', ' . $helper->quote('STORAGE_ID') . ' = ' . (int)$storageId;
		}
		$committed = ItemStatus::Committed->value;
		$canceled = ItemStatus::Canceled->value;

		$affected = 0;
		try
		{
			foreach (array_chunk($ids, max(1, $chunkSize)) as $chunk)
			{
				$idList = implode(',', $chunk);
				$connection->queryExecute("
					UPDATE {$itemTable}
					SET {$setSql}
					WHERE ID IN ({$idList})
						AND STATUS NOT IN ('{$committed}', '{$canceled}')
						AND QUEUE_ID IN (SELECT ID FROM {$queueTable} WHERE USER_ID = {$userId})
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
	 * Batch status transition for a whole queue - a single UPDATE over the QUEUE_ID
	 * index instead of one round-trip per row. $excludeStatuses keeps terminal rows
	 * (committed / already canceled) untouched.
	 *
	 * USER_ID isolation is baked into the WHERE clause when $userId is given
	 * (defense-in-depth on top of the caller's own queue-ownership check, mirrors
	 * {@see self::deleteByQueue()} plus the OwnershipService CAS-update gate);
	 * pass null only when the caller has already verified ownership and wants to
	 * skip the extra subquery.
	 *
	 * @param ItemStatus[] $excludeStatuses
	 * @param array<string, scalar|null> $extraFields
	 * @return int number of rows actually updated
	 */
	public function updateStatusByQueue(
		int $queueId,
		ItemStatus $status,
		array $excludeStatuses = [],
		array $extraFields = [],
		?int $userId = null,
	): int
	{
		return $this->updateStatusByQueues([$queueId], $status, $excludeStatuses, $extraFields, $userId);
	}

	/**
	 * The same transition over SEVERAL queues at once - the widget-level close cancels
	 * the unfinished elements of every queue the user has (SC-015), and doing it one
	 * queue at a time would make the cost of the close grow with their number.
	 *
	 * @param int[] $queueIds
	 * @param ItemStatus[] $excludeStatuses
	 * @param array<string, scalar|null> $extraFields
	 * @return int number of rows actually updated
	 */
	public function updateStatusByQueues(
		array $queueIds,
		ItemStatus $status,
		array $excludeStatuses = [],
		array $extraFields = [],
		?int $userId = null,
		int $chunkSize = 500,
	): int
	{
		$queueIds = array_values(array_unique(array_filter(
			array_map('intval', $queueIds),
			static fn(int $id): bool => $id > 0,
		)));

		if ($queueIds === [])
		{
			return 0;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$itemTable = DockedUploadItemTable::getTableName();
		$setSql = $this->buildStatusSetSql($status, $extraFields, $helper);

		$excludeSql = '';
		if ($excludeStatuses !== [])
		{
			$values = implode(',', array_map(
				static fn(ItemStatus $excluded): string => "'" . $helper->forSql($excluded->value) . "'",
				$excludeStatuses,
			));
			$excludeSql = "AND STATUS NOT IN ({$values})";
		}

		$userGuard = '';
		if ($userId !== null && $userId > 0)
		{
			$queueTable = DockedUploadQueueTable::getTableName();
			$userGuard = "AND QUEUE_ID IN (SELECT ID FROM {$queueTable} WHERE USER_ID = {$userId})";
		}

		$affected = 0;
		try
		{
			foreach (array_chunk($queueIds, max(1, $chunkSize)) as $chunk)
			{
				$idList = implode(',', $chunk);
				$connection->queryExecute("
					UPDATE {$itemTable}
					SET {$setSql}
					WHERE QUEUE_ID IN ({$idList})
						{$excludeSql}
						{$userGuard}
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
	 * Atomic guarded status transition for a single item - the compare-and-swap the
	 * transport transition relies on (TOCTOU-safe). Flips STATUS (and optionally
	 * TEMP_TOKEN) only when the row is currently in one of $allowedCurrent, mirroring
	 * {@see \Bitrix\Disk\Internal\Service\DockedUpload\OwnershipService} and
	 * {@see \Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService} atomic
	 * updates: a stale full-row save can never overwrite a concurrent commit/cancel.
	 *
	 * USER_ID isolation is baked into the WHERE clause through the QUEUE reference.
	 *
	 * The landing of the transition is "the row stands in the requested state", NOT "one
	 * row was changed". MySQL counts the rows CHANGED rather than the rows matched (the
	 * core connects without CLIENT_FOUND_ROWS), and the transport matrix allows every
	 * transport status to transition into itself, so a legal repeat within the same second
	 * - same status, same token, and UPDATE_TIME accurate to the second - changes nothing
	 * and would otherwise read as a lost race. For uploaded_to_temp that is the only
	 * trigger of the commit, so a false loss would leave the bytes in temp with no tab
	 * still fighting for them. Hence the read-back below, the same shape
	 * {@see \Bitrix\Disk\Internal\Service\DockedUpload\OwnershipService::heartbeat()} and
	 * {@see self::stampFolderPolicy()} already use. PostgreSQL counts matched rows and
	 * never needed it; after the read-back the two engines agree.
	 *
	 * $requireCommitAcceptingQueue adds the queue status to the same guard: the row moves
	 * only while its queue still takes a commit ({@see QueueStatus::acceptsCommit()}). An
	 * element entering a transfer needs it - a stop written by a neighbour between the read
	 * of the queue and this UPDATE would otherwise leave the row in uploading with no
	 * transport behind it.
	 *
	 * @param ItemStatus[] $allowedCurrent the current statuses the row may transition from
	 * @return bool whether the row stands in the requested state after this call
	 */
	public function transitionStatus(
		int $itemId,
		int $userId,
		ItemStatus $to,
		array $allowedCurrent,
		?string $tempToken = null,
		bool $requireCommitAcceptingQueue = false,
	): bool
	{
		if ($itemId <= 0 || $userId <= 0 || $allowedCurrent === [])
		{
			return false;
		}

		// The token goes into hand-built SQL, so nothing truncates it on the way: refuse a
		// value the column cannot hold rather than store one that will never match the temp
		// the uploader handed out.
		if ($tempToken !== null && DockedUploadItemTable::exceedsColumnWidth('TEMP_TOKEN', $tempToken))
		{
			return false;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$itemTable = DockedUploadItemTable::getTableName();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());

		$allowedList = implode(',', array_map(
			static fn(ItemStatus $current): string => "'" . $helper->forSql($current->value) . "'",
			$allowedCurrent,
		));

		$set = "STATUS = '" . $helper->forSql($to->value) . "', UPDATE_TIME = {$now}";
		if ($tempToken !== null && $tempToken !== '')
		{
			$set .= ", TEMP_TOKEN = '" . $helper->forSql($tempToken) . "'";
		}

		$queueGate = "USER_ID = {$userId}";
		if ($requireCommitAcceptingQueue)
		{
			$queueGate .= ' AND STATUS IN (' . self::sqlValueList(QueueStatus::commitAcceptingValues(), $helper) . ')';
		}

		try
		{
			$connection->queryExecute("
				UPDATE {$itemTable}
				SET {$set}
				WHERE ID = {$itemId}
					AND STATUS IN ({$allowedList})
					AND QUEUE_ID IN (SELECT ID FROM {$queueTable} WHERE {$queueGate})
			");
		}
		catch (Throwable $exception)
		{
			throw new PersistenceException(
				message: $exception->getMessage(),
				previous: $exception,
			);
		}

		if ($connection->getAffectedRowsCount() === 1)
		{
			return true;
		}

		return $this->standsInTransportState($itemId, $userId, $to, $tempToken);
	}

	/**
	 * The read half of {@see self::transitionStatus()}: whether the row already stands
	 * exactly where the guarded UPDATE was taking it.
	 *
	 * It re-asks the same predicate the UPDATE would have satisfied - the status AND the
	 * token, never mere existence. Asking only for the row would turn a race genuinely lost
	 * to a concurrent committed/canceled into a success and hand the caller a commit
	 * trigger on a terminal row.
	 */
	private function standsInTransportState(
		int $itemId,
		int $userId,
		ItemStatus $to,
		?string $tempToken,
	): bool
	{
		$query = DockedUploadItemTable::query()
			->setSelect(['ID'])
			->where('ID', $itemId)
			->where('STATUS', $to->value)
			->where('QUEUE.USER_ID', $userId)
			->setLimit(1)
		;

		if ($tempToken !== null && $tempToken !== '')
		{
			$query->where('TEMP_TOKEN', $tempToken);
		}

		return is_array($query->fetch());
	}

	/**
	 * Point update of TEMP_TOKEN only (no status change), gated against the terminal
	 * statuses so a tempToken-only updateItem never resurrects a committed/canceled
	 * row nor rewrites it from a stale snapshot. USER_ID isolation via the QUEUE
	 * reference.
	 *
	 * @return bool whether the row was updated
	 */
	public function updateTempToken(int $itemId, int $userId, string $tempToken): bool
	{
		if ($itemId <= 0 || $userId <= 0 || $tempToken === '')
		{
			return false;
		}

		// Hand-built SQL again: an overlong token is refused, not silently cut down.
		if (DockedUploadItemTable::exceedsColumnWidth('TEMP_TOKEN', $tempToken))
		{
			return false;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$itemTable = DockedUploadItemTable::getTableName();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$committed = ItemStatus::Committed->value;
		$canceled = ItemStatus::Canceled->value;
		$token = $helper->forSql($tempToken);

		try
		{
			$connection->queryExecute("
				UPDATE {$itemTable}
				SET TEMP_TOKEN = '{$token}', UPDATE_TIME = {$now}
				WHERE ID = {$itemId}
					AND STATUS NOT IN ('{$committed}', '{$canceled}')
					AND QUEUE_ID IN (SELECT ID FROM {$queueTable} WHERE USER_ID = {$userId})
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
	 * Guarded write of what a folder-tree build stamps on its folder-item: the folder-level
	 * conflict policy (AC-071/072) and the storage of the tree root, which a structural
	 * folder-item may have arrived without.
	 *
	 * A point UPDATE, never the full-row save the build used to do: the row is read before
	 * the tree is built, and a cancel or a dismiss landing in that window would be clobbered
	 * back by the stale snapshot - the canceled folder would read as "queued" again and
	 * re-enter the user's active-item ceiling. Terminal rows are guarded out and USER_ID
	 * isolation runs through the QUEUE reference, mirroring {@see self::updateTempToken()}.
	 *
	 * STORAGE_ID is inherited, never overwritten, so a storage written meanwhile wins over
	 * the caller's snapshot.
	 *
	 * @return bool whether the row is still writable, i.e. whether the build may go on
	 */
	public function stampFolderPolicy(
		int $itemId,
		int $userId,
		ConflictPolicy $conflictPolicy,
		?int $storageId = null,
	): bool
	{
		if ($itemId <= 0 || $userId <= 0)
		{
			return false;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$itemTable = DockedUploadItemTable::getTableName();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$policy = $helper->forSql($conflictPolicy->value);
		$committed = ItemStatus::Committed->value;
		$canceled = ItemStatus::Canceled->value;

		$set = "CONFLICT_POLICY = '{$policy}', UPDATE_TIME = {$now}";
		if ($storageId !== null && $storageId > 0)
		{
			$set .= ', STORAGE_ID = COALESCE(STORAGE_ID, ' . $storageId . ')';
		}

		try
		{
			$connection->queryExecute("
				UPDATE {$itemTable}
				SET {$set}
				WHERE ID = {$itemId}
					AND STATUS NOT IN ('{$committed}', '{$canceled}')
					AND QUEUE_ID IN (SELECT ID FROM {$queueTable} WHERE USER_ID = {$userId})
			");
		}
		catch (Throwable $exception)
		{
			throw new PersistenceException(
				message: $exception->getMessage(),
				previous: $exception,
			);
		}

		if ($connection->getAffectedRowsCount() === 1)
		{
			return true;
		}

		// MySQL counts the rows CHANGED, not the rows matched: a repeated build writing the
		// same policy within the same second changes nothing, so a zero here is no proof the
		// guard rejected the row - only then is the row itself asked.
		return $this->isWritable($itemId, $userId);
	}

	/**
	 * Whether the row is still open to a lifecycle write, i.e. neither committed nor
	 * canceled - the read half of {@see self::stampFolderPolicy()}, never a gate of its own
	 * (a guarded write is what keeps a decision race-safe).
	 */
	private function isWritable(int $itemId, int $userId): bool
	{
		$row = DockedUploadItemTable::query()
			->setSelect(['ID'])
			->where('ID', $itemId)
			->whereNotIn('STATUS', [ItemStatus::Committed->value, ItemStatus::Canceled->value])
			->where('QUEUE.USER_ID', $userId)
			->setLimit(1)
			->fetch()
		;

		return is_array($row);
	}

	/**
	 * Guarded bind of the replace_all auto-resolution target: atomically pins
	 * TARGET_FILE_ID and moves the row to uploaded_to_temp (clearing ERROR_CODE) only
	 * from a non-terminal transport state, mirroring the FileCommitService atomicMark*
	 * guard (STATUS NOT IN committed/canceled). A replace racing a concurrent cancel (or
	 * a durable commit) that lost the row (0 rows) must abort the commit path instead of
	 * clobbering the terminal state with a full-row save.
	 *
	 * USER_ID isolation is baked into the WHERE clause through the QUEUE reference.
	 *
	 * As in {@see self::transitionStatus()}, the guard does not exclude the target status,
	 * so a repeat of a bind the row already carries changes nothing and MySQL reports zero
	 * changed rows. A read-back tells that apart from a genuinely lost race - without it
	 * {@see \Bitrix\Disk\Public\Command\DockedUpload\ResolveConflictCommand::replace()}
	 * would read the row as terminal and refuse to commit, and
	 * {@see \Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService::applyReplaceAll()}
	 * would additionally drop the b_file it had just materialised.
	 *
	 * @return bool whether the row stands bound to that target after this call
	 */
	public function bindReplaceTarget(int $itemId, int $userId, int $targetFileId): bool
	{
		if ($itemId <= 0 || $userId <= 0 || $targetFileId <= 0)
		{
			return false;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$itemTable = DockedUploadItemTable::getTableName();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$uploadedToTemp = $helper->forSql(ItemStatus::UploadedToTemp->value);
		$committed = ItemStatus::Committed->value;
		$canceled = ItemStatus::Canceled->value;

		try
		{
			$connection->queryExecute("
				UPDATE {$itemTable}
				SET STATUS = '{$uploadedToTemp}', TARGET_FILE_ID = {$targetFileId}, ERROR_CODE = NULL, UPDATE_TIME = {$now}
				WHERE ID = {$itemId}
					AND STATUS NOT IN ('{$committed}', '{$canceled}')
					AND QUEUE_ID IN (SELECT ID FROM {$queueTable} WHERE USER_ID = {$userId})
			");
		}
		catch (Throwable $exception)
		{
			throw new PersistenceException(
				message: $exception->getMessage(),
				previous: $exception,
			);
		}

		if ($connection->getAffectedRowsCount() === 1)
		{
			return true;
		}

		// The full SET, re-asked: status, target and a cleared error code together. A row
		// merely present, or one a concurrent writer parked elsewhere, is not a landed bind.
		$stands = DockedUploadItemTable::query()
			->setSelect(['ID'])
			->where('ID', $itemId)
			->where('STATUS', ItemStatus::UploadedToTemp->value)
			->where('TARGET_FILE_ID', $targetFileId)
			->whereNull('ERROR_CODE')
			->where('QUEUE.USER_ID', $userId)
			->setLimit(1)
			->fetch()
		;

		return is_array($stands);
	}

	/**
	 * Guarded retry of a single failed item: atomically moves it failed -> pending and
	 * clears ERROR_CODE only while the row is still failed, so a retry racing a
	 * concurrent cancel (or a durable commit) can never resurrect a terminal row from a
	 * stale in-memory snapshot. USER_ID isolation via the QUEUE reference.
	 *
	 * @return bool whether exactly one row made the transition
	 */
	public function markPendingFromFailed(int $itemId, int $userId): bool
	{
		if ($itemId <= 0 || $userId <= 0)
		{
			return false;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$itemTable = DockedUploadItemTable::getTableName();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$pending = $helper->forSql(ItemStatus::Pending->value);
		$failed = $helper->forSql(ItemStatus::Failed->value);

		try
		{
			$connection->queryExecute("
				UPDATE {$itemTable}
				SET STATUS = '{$pending}', ERROR_CODE = NULL, UPDATE_TIME = {$now}
				WHERE ID = {$itemId}
					AND STATUS = '{$failed}'
					AND QUEUE_ID IN (SELECT ID FROM {$queueTable} WHERE USER_ID = {$userId})
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
	 * Batch form of {@see self::markPendingFromFailed()}, and it carries the same exact
	 * guard: STATUS = 'failed', not merely "not terminal".
	 *
	 * The batch retry branches pick their rows from an in-memory snapshot, so a row that
	 * left failed after that read must not be dragged back. The looser
	 * {@see self::updateStatusByIds()} gate would take it: a duplicate_conflict row would
	 * lose the decision waiting on it (ERROR_CODE cleared, the element back in the queue as
	 * an ordinary one) and an uploaded_to_temp row would be transferred a second time.
	 *
	 * @param int[] $ids
	 * @return int rows that actually made the transition
	 */
	public function markPendingFromFailedByIds(array $ids, int $userId, int $chunkSize = 500): int
	{
		$ids = array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		)));

		if ($ids === [] || $userId <= 0)
		{
			return 0;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$itemTable = DockedUploadItemTable::getTableName();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$pending = $helper->forSql(ItemStatus::Pending->value);
		$failed = $helper->forSql(ItemStatus::Failed->value);

		$affected = 0;
		try
		{
			foreach (array_chunk($ids, max(1, $chunkSize)) as $chunk)
			{
				$idList = implode(',', $chunk);
				$connection->queryExecute("
					UPDATE {$itemTable}
					SET STATUS = '{$pending}', ERROR_CODE = NULL, UPDATE_TIME = {$now}
					WHERE ID IN ({$idList})
						AND STATUS = '{$failed}'
						AND QUEUE_ID IN (SELECT ID FROM {$queueTable} WHERE USER_ID = {$userId})
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
	 * Guarded requeue of the elements a restore found holding no bytes to fix
	 * ({@see \Bitrix\Disk\Public\Command\DockedUpload\ResumeCommand}, QA 257959): the
	 * culprit of a quota stop (failed with one of $quotaErrorCodes) and an element left in
	 * uploading without a temp token. Both go back to pending with ERROR_CODE and TEMP_TOKEN
	 * cleared, and the client transfers them like any other queued element.
	 *
	 * The guard repeats the selection in SQL, since the caller picks the ids from a snapshot:
	 * a row that left the state meanwhile (a token reported by its transport, a cancel) is
	 * kept. The queue has to accept a commit as well, so a restore that stopped the queue
	 * again requeues nothing - its byte-less elements stay held by the stop.
	 *
	 * @param int[] $ids
	 * @param string[] $quotaErrorCodes
	 * @return int rows that actually made the transition
	 */
	public function markPendingFromStranded(
		array $ids,
		int $userId,
		array $quotaErrorCodes,
		int $chunkSize = 500,
	): int
	{
		$ids = self::normalizeIds($ids);
		if ($ids === [] || $userId <= 0 || $quotaErrorCodes === [])
		{
			return 0;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$itemTable = DockedUploadItemTable::getTableName();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$pending = $helper->forSql(ItemStatus::Pending->value);
		$failed = $helper->forSql(ItemStatus::Failed->value);
		$uploading = $helper->forSql(ItemStatus::Uploading->value);
		$quotaCodes = self::sqlValueList($quotaErrorCodes, $helper);
		$acceptingQueue = self::sqlValueList(QueueStatus::commitAcceptingValues(), $helper);

		$affected = 0;
		try
		{
			foreach (array_chunk($ids, max(1, $chunkSize)) as $chunk)
			{
				$idList = implode(',', $chunk);
				$connection->queryExecute("
					UPDATE {$itemTable}
					SET STATUS = '{$pending}', ERROR_CODE = NULL, TEMP_TOKEN = NULL, UPDATE_TIME = {$now}
					WHERE ID IN ({$idList})
						AND (
							(STATUS = '{$failed}' AND ERROR_CODE IN ({$quotaCodes}))
							OR (STATUS = '{$uploading}' AND (TEMP_TOKEN IS NULL OR TEMP_TOKEN = ''))
						)
						AND QUEUE_ID IN (
							SELECT ID FROM {$queueTable} WHERE USER_ID = {$userId} AND STATUS IN ({$acceptingQueue})
						)
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
	 * @param string[] $values
	 */
	private static function sqlValueList(array $values, SqlHelper $helper): string
	{
		return implode(',', array_map(
			static fn(string $value): string => "'" . $helper->forSql($value) . "'",
			$values,
		));
	}

	/**
	 * Guarded batch transition duplicate_conflict -> interrupted_needs_file with the owner
	 * cleared, for the rows whose temp a restore confirmed gone (AC-042 / ERR-013).
	 *
	 * The Replace such a row still offers has no bytes left to commit, so the restore reports
	 * it as interrupted and offers the re-select instead. That verdict has to be PERSISTED:
	 * the client answers the prompt with status=uploading, and
	 * {@see \Bitrix\Disk\Internal\Service\DockedUpload\ItemMutationApplier} gates it against the
	 * STORED status - a row left duplicate_conflict in the table rejects the very button the
	 * restore put on it (DISK_DOCKED_ITEM_TRANSITION_INVALID), with nothing to show the user.
	 *
	 * STATUS = 'duplicate_conflict' IS the compare-and-swap: a Replace
	 * ({@see \Bitrix\Disk\Public\Command\DockedUpload\ResolveConflictCommand}) that resolved the
	 * row first keeps it and this call is a no-op for it. ERROR_CODE is cleared along with the
	 * status, the way {@see self::markPendingFromFailed()} clears it on leaving failed: the
	 * conflict code on a row that is no longer a conflict travels to the client in ItemDto and
	 * rolls up into the folder row through its blocking child.
	 *
	 * Chunked, so the query count grows as ceil(N / $chunkSize) rather than with N.
	 *
	 * @param int[] $ids
	 * @return int[] ids of the rows that are now interrupted_needs_file and unowned
	 */
	public function markInterruptedFromConflict(array $ids, int $userId, int $chunkSize = 500): array
	{
		$ids = self::normalizeIds($ids);
		if ($ids === [] || $userId <= 0)
		{
			return [];
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$itemTable = DockedUploadItemTable::getTableName();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$interrupted = $helper->forSql(ItemStatus::InterruptedNeedsFile->value);
		$conflict = $helper->forSql(ItemStatus::DuplicateConflict->value);
		$userGate = "QUEUE_ID IN (SELECT ID FROM {$queueTable} WHERE USER_ID = {$userId})";

		$degraded = [];
		try
		{
			foreach (array_chunk($ids, max(1, $chunkSize)) as $chunk)
			{
				$idList = implode(',', $chunk);
				$connection->queryExecute("
					UPDATE {$itemTable}
					SET STATUS = '{$interrupted}', ERROR_CODE = NULL, OWNER_CLIENT_ID = NULL,
						OWNER_HEARTBEAT_AT = NULL, UPDATE_TIME = {$now}
					WHERE ID IN ({$idList})
						AND STATUS = '{$conflict}'
						AND {$userGate}
				");

				// Read back rather than trust affectedRows: a concurrent restore that degraded
				// the same row first leaves it in exactly the wanted state and still counts as
				// degraded for this caller.
				$rows = $connection->query("
					SELECT ID FROM {$itemTable}
					WHERE ID IN ({$idList})
						AND STATUS = '{$interrupted}'
						AND OWNER_CLIENT_ID IS NULL
						AND {$userGate}
				");
				while ($row = $rows->fetch())
				{
					$degraded[] = (int)$row['ID'];
				}
			}
		}
		catch (Throwable $exception)
		{
			throw new PersistenceException(
				message: $exception->getMessage(),
				previous: $exception,
			);
		}

		return $degraded;
	}

	/**
	 * Counts the user's active (non-terminal) items across their live queues - the
	 * cumulative ceiling {@see \Bitrix\Disk\Public\Command\DockedUpload\CreateQueueCommand}
	 * enforces before an insert. USER_ID isolation via the QUEUE reference; committed
	 * and canceled rows are excluded as terminal.
	 *
	 * Only the queues restore can bring back count, on both of the conditions
	 * {@see \Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadQueueRepository::getActiveByUser()}
	 * selects them by: the status set of {@see QueueStatus::restorableValues()} (the single
	 * declaration of that set) and an EXPIRES_AT still ahead. A completed or dismissed queue is
	 * over, an expired one is the cleanup agent's, and the rows of both just wait for that agent.
	 * Budgeting the closed ones would let a handful of finished folder uploads block every new
	 * queue - a folder-item never commits, so it stays non-terminal for the whole 24h window
	 * while its files are long since stored; budgeting the expired ones would be worse still,
	 * since restore no longer offers such a queue back and the widget has nothing left to cancel
	 * it with, so its rows would refuse every new queue until the agent reached them.
	 */
	public function countActiveItemsByUser(int $userId): int
	{
		if ($userId <= 0)
		{
			return 0;
		}

		return (int)DockedUploadItemTable::getCount([
			'=QUEUE.USER_ID' => $userId,
			'@QUEUE.STATUS' => QueueStatus::restorableValues(),
			'>QUEUE.EXPIRES_AT' => new DateTime(),
			'!@STATUS' => [ItemStatus::Committed->value, ItemStatus::Canceled->value],
		]);
	}

	/**
	 * Counts every row the user still keeps in the table - all items of their queues that
	 * have not reached EXPIRES_AT - whatever the queue and the item status say. This is the
	 * durable half of the Q-6 ceiling
	 * ({@see \Bitrix\Disk\Public\Command\DockedUpload\CreateQueueCommand::enforceUserItemCeiling()}),
	 * reported here for callers that want the number itself. The ceiling is not enforced with
	 * it: {@see self::hasRetainedItemsOverLimit()} answers that with a read the ceiling bounds.
	 *
	 * Status-blind on purpose, unlike {@see self::countActiveItemsByUser()}: closing a queue
	 * (dismiss, or a cancel that drains it to completed) frees the ACTIVE budget at once, but
	 * frees no row - the rows live their full TTL and only the cleanup agent removes them. So
	 * the two counters answer two different questions and neither replaces the other.
	 *
	 * Rows past their queue EXPIRES_AT are out: they are the agent's to collect and must not
	 * hold a budget hostage until it gets to them.
	 */
	public function countRetainedItemsByUser(int $userId): int
	{
		if ($userId <= 0)
		{
			return 0;
		}

		return (int)DockedUploadItemTable::getCount(self::retainedItemsFilter($userId));
	}

	/**
	 * Whether the user keeps MORE than $limit retained rows - the only thing the RETAINED
	 * ceiling asks. Reads at most $limit + 1 rows, which bounds the worst case rather than
	 * speeding up the common one: while the user is under the ceiling LIMIT $limit, 1 walks
	 * every retained row before it can answer, exactly as the COUNT it replaced did, and pays
	 * a little more per row for producing them. What the bound buys is the user already past
	 * $limit - a lowered option, or rows written through a caller that passes no ceiling -
	 * where the COUNT grew with the table inside the per-user create lock that serializes
	 * that user's enqueues, while this stops at $limit + 1.
	 *
	 * Same predicate as {@see self::countRetainedItemsByUser()} - both take it from
	 * {@see self::retainedItemsFilter()}, so the enforced ceiling and the reported number
	 * cannot drift apart. Row order is irrelevant: the question is only whether a
	 * ($limit + 1)-th matching row exists.
	 *
	 * A negative $limit leaves room for nothing, so even an empty table is over it.
	 */
	public function hasRetainedItemsOverLimit(int $userId, int $limit): bool
	{
		if ($limit < 0)
		{
			return true;
		}

		if ($userId <= 0)
		{
			return false;
		}

		$row = DockedUploadItemTable::query()
			->setSelect(['ID'])
			->setFilter(self::retainedItemsFilter($userId))
			->setLimit(1)
			->setOffset($limit)
			->fetch()
		;

		return $row !== false;
	}

	/**
	 * The RETAINED predicate, declared once: every item of the user's queues that have not
	 * reached EXPIRES_AT.
	 */
	private static function retainedItemsFilter(int $userId): array
	{
		return [
			'=QUEUE.USER_ID' => $userId,
			'>QUEUE.EXPIRES_AT' => new DateTime(),
		];
	}

	/**
	 * Columns callers may set through $extraFields. Keys are used as raw SQL
	 * identifiers, so an unknown key is rejected rather than concatenated.
	 */
	private const EXTRA_FIELD_WHITELIST = ['ERROR_CODE'];

	private function buildStatusSetSql(ItemStatus $status, array $extraFields, SqlHelper $helper): string
	{
		$now = $helper->convertToDbDateTime(new DateTime());
		$parts = [
			(new SqlExpression('?# = ?s', 'STATUS', $status->value))->compile(),
			$helper->quote('UPDATE_TIME') . ' = ' . $now,
		];

		foreach ($extraFields as $field => $value)
		{
			if (!in_array($field, self::EXTRA_FIELD_WHITELIST, true))
			{
				throw new ArgumentException("Unsupported docked upload status column: {$field}");
			}

			$parts[] = match (true)
			{
				$value === null => (new SqlExpression('?# = NULL', $field))->compile(),
				is_int($value) => (new SqlExpression('?# = ?i', $field, $value))->compile(),
				default => (new SqlExpression('?# = ?s', $field, (string)$value))->compile(),
			};
		}

		return implode(', ', $parts);
	}

	public function getByQueue(int $queueId, int $userId): DockedUploadItemCollection
	{
		$query = DockedUploadItemTable::query()
			->setSelect(['*'])
			->where('QUEUE_ID', $queueId)
			->where('QUEUE.USER_ID', $userId)
		;

		return $this->buildCollection($query);
	}

	/**
	 * The columns the queue projection consumes: the folder rows it rebuilds
	 * ({@see \Bitrix\Disk\Internal\Dto\DockedUpload\ItemDto::fromFolderItem()} plus the
	 * aggregate over their leaves) and the queue-level roll-up read nothing else, while
	 * RELATIVE_PATH alone is a varchar(1000) on every row of a queue capped at
	 * {@see \Bitrix\Disk\Controller\DockedUploader\Queue::MAX_QUEUE_ITEMS}.
	 *
	 * The entities come back PARTIAL - every column outside the list reads as null on
	 * them - so this is the projection's own read, not a narrower {@see self::getByQueue()}.
	 */
	private const PROJECTION_SELECT = [
		'ID',
		'PARENT_ITEM_ID',
		'ITEM_TYPE',
		'STATUS',
		'NAME',
		'SIZE',
		'TARGET_FOLDER_ID',
		'STORAGE_ID',
		'ERROR_CODE',
	];

	/**
	 * Queue read for {@see \Bitrix\Disk\Internal\Service\DockedUpload\QueueChangeProjector},
	 * narrowed to {@see self::PROJECTION_SELECT}. Never use it where a full element is
	 * expected - see the constant.
	 */
	public function getByQueueForProjection(int $queueId, int $userId): DockedUploadItemCollection
	{
		$query = DockedUploadItemTable::query()
			->setSelect(self::PROJECTION_SELECT)
			->where('QUEUE_ID', $queueId)
			->where('QUEUE.USER_ID', $userId)
		;

		return $this->buildCollection($query);
	}

	/**
	 * Batch variant of {@see self::getByQueueForProjection()}: the rows of several queues in
	 * ONE read, narrowed to {@see self::PROJECTION_SELECT} plus QUEUE_ID, which the caller
	 * groups the rows by ({@see \Bitrix\Disk\Internal\Service\DockedUpload\QueueChangeProjector::projectMany()}).
	 *
	 * Kept apart from {@see self::getByQueues()} - restore reads the same queues and needs
	 * whole elements - and ordered exactly like it, so the projection built off these rows
	 * lists its folder-items in the same order either way.
	 *
	 * The entities come back PARTIAL. Never use it where a full element is expected.
	 *
	 * @param int[] $queueIds
	 */
	public function getByQueuesForProjection(array $queueIds, int $userId): DockedUploadItemCollection
	{
		if ($queueIds === [])
		{
			return new DockedUploadItemCollection();
		}

		$query = DockedUploadItemTable::query()
			->setSelect([...self::PROJECTION_SELECT, 'QUEUE_ID'])
			->whereIn('QUEUE_ID', $queueIds)
			->where('QUEUE.USER_ID', $userId)
			->setOrder(['UPDATE_TIME' => 'DESC', 'ID' => 'DESC'])
		;

		return $this->buildCollection($query);
	}

	/**
	 * The rows the widget-level close reads (SC-015): the temp tokens it frees AND the state
	 * its events are built from, for every queue of the widget in ONE read.
	 *
	 * Narrowed to exactly what those two consumers touch. Wider than
	 * {@see self::PROJECTION_SELECT}, because the close carries the WHOLE queue projection
	 * ({@see \Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto::fromEntities()}) and a leaf DTO
	 * reports three fields a folder row has no use for - its version target and its transport
	 * owner - so the payload stays the one a full read produces (EVENT-01). Still without the
	 * columns no reader of the close looks at, RELATIVE_PATH (a varchar(1000) on every row)
	 * among them.
	 *
	 * Ordered exactly like {@see self::getByQueues()} and {@see self::getByQueuesForProjection()},
	 * because the order is part of that same form and not an implementation detail:
	 * {@see \Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto::fromEntities()} lists the elements
	 * in the order it receives them, so an order of its own here would make the close announce
	 * a queue in an order no other event of that queue uses. The filesort over up to
	 * {@see \Bitrix\Disk\Controller\DockedUploader\Queue::MAX_USER_ITEMS} rows is what the form
	 * costs.
	 *
	 * The entities come back PARTIAL. Never use them where a full element is expected.
	 *
	 * @param int[] $queueIds
	 */
	public function getByQueuesForDismiss(array $queueIds, int $userId): DockedUploadItemCollection
	{
		$queueIds = self::normalizeIds($queueIds);
		if ($queueIds === [])
		{
			return new DockedUploadItemCollection();
		}

		$query = DockedUploadItemTable::query()
			->setSelect([
				...self::PROJECTION_SELECT,
				'QUEUE_ID',
				'TEMP_TOKEN',
				'TARGET_FILE_ID',
				'OWNER_CLIENT_ID',
				'OWNER_HEARTBEAT_AT',
				// The crash-window pair: the close reads it to find the elements whose
				// domain write is already durable while no object was ever bound, and
				// hands them to
				// {@see \Bitrix\Disk\Internal\Service\DockedUpload\DurableOrphanReleaser}.
				'INTENT_TOKEN',
				'DISK_OBJECT_ID',
			])
			->whereIn('QUEUE_ID', $queueIds)
			->where('QUEUE.USER_ID', $userId)
			->setOrder(['UPDATE_TIME' => 'DESC', 'ID' => 'DESC'])
		;

		return $this->buildCollection($query);
	}

	/**
	 * The FAILED elements of several queues, narrowed to what the scope:failed batch reads
	 * off them ({@see \Bitrix\Disk\Public\Command\DockedUpload\RetryCommand}): the queue to
	 * group by, the id to flip and the ERROR_CODE the retriability rule is applied to.
	 *
	 * Only the status membership moves into SQL, and the rule that decides whether a
	 * failure is retriable stays in {@see \Bitrix\Disk\Internal\Service\DockedUpload\DisplayStatusResolver}.
	 * STATUS travels along so the caller's own predicate reads it rather than trusting the
	 * WHERE clause, and ITEM_TYPE because every element carries a type
	 * ({@see DockedUploadItemMapper::convertFromArray()} reads both without a fallback).
	 *
	 * Ordered exactly like {@see self::getByQueues()}, whose full read this replaces, so the
	 * batch acts on the elements in the same order.
	 *
	 * The entities come back PARTIAL. Never use them where a full element is expected.
	 *
	 * @param int[] $queueIds
	 */
	public function getFailedByQueues(array $queueIds, int $userId): DockedUploadItemCollection
	{
		$queueIds = self::normalizeIds($queueIds);
		if ($queueIds === [])
		{
			return new DockedUploadItemCollection();
		}

		$query = DockedUploadItemTable::query()
			->setSelect(['ID', 'QUEUE_ID', 'ITEM_TYPE', 'STATUS', 'ERROR_CODE'])
			->whereIn('QUEUE_ID', $queueIds)
			->where('STATUS', ItemStatus::Failed->value)
			->where('QUEUE.USER_ID', $userId)
			->setOrder(['UPDATE_TIME' => 'DESC', 'ID' => 'DESC'])
		;

		return $this->buildCollection($query);
	}

	/**
	 * Which of the named queues hold one of the named elements under a folder-item, i.e.
	 * which of them have an ancestor row for the projection to rebuild - the multi-queue
	 * {@see self::hasParentedItem()}, answered for all of them in ONE grouped probe instead
	 * of one probe per queue.
	 *
	 * The named elements span every queue of the batch, so the id list is chunked and a
	 * queue already answered drops out of the remaining chunks.
	 *
	 * @param int[] $queueIds
	 * @param int[] $itemIds
	 * @return array<int, true> keyed by queue id
	 */
	public function findQueuesWithParentedItems(
		array $queueIds,
		int $userId,
		array $itemIds,
		int $chunkSize = 500,
	): array
	{
		$queueIds = self::normalizeIds($queueIds);
		$itemIds = self::normalizeIds($itemIds);

		if ($userId <= 0 || $queueIds === [] || $itemIds === [])
		{
			return [];
		}

		$queues = [];
		$unanswered = $queueIds;
		foreach (array_chunk($itemIds, max(1, $chunkSize)) as $chunk)
		{
			$result = DockedUploadItemTable::query()
				->setDistinct()
				->setSelect(['QUEUE_ID'])
				->whereIn('QUEUE_ID', $unanswered)
				->whereIn('ID', $chunk)
				->where('PARENT_ITEM_ID', '>', 0)
				->where('QUEUE.USER_ID', $userId)
				->exec()
			;

			while ($row = $result->fetch())
			{
				$queues[(int)$row['QUEUE_ID']] = true;
			}

			$unanswered = array_values(array_filter(
				$unanswered,
				static fn(int $queueId): bool => !isset($queues[$queueId]),
			));
			if ($unanswered === [])
			{
				break;
			}
		}

		return $queues;
	}

	/**
	 * The queue-level signals of SEVERAL queues at once: the distinct
	 * (ITEM_TYPE, STATUS, ERROR_CODE) combinations each of the named queues holds, in one
	 * grouped read per chunk.
	 *
	 * This is what {@see \Bitrix\Disk\Internal\Service\DockedUpload\QueueChangeProjector}
	 * folds its flat-queue verdicts out of. Per queue the four verdicts used to be four
	 * existence probes, which is the right trade for ONE queue and the wrong one for a batch:
	 * a retry scope:failed or an updateItems volley spans as many queues as the user has, and
	 * nothing caps their number, so the probes grew 4 x N while this read does not grow at all.
	 *
	 * It is a read of GROUPS, not of rows: the queue is collapsed to its distinct
	 * combinations, so a queue of {@see \Bitrix\Disk\Controller\DockedUploader\Queue::MAX_QUEUE_ITEMS}
	 * rows answers with the handful of them its elements actually occupy.
	 *
	 * No predicate travels into SQL beyond the queue scope: which statuses mean a transfer in
	 * flight, which mean "waiting on the user" and which are terminal is declared once, by
	 * {@see \Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus} and
	 * {@see \Bitrix\Disk\Internal\Service\DockedUpload\DisplayStatusResolver}, and re-expressing
	 * any of it here is exactly how the two would drift apart. The caller applies them to these
	 * combinations in memory.
	 *
	 * A stored value outside its enum reads as null rather than raising: the probes this
	 * replaces compared strings in SQL, where an unknown value simply matched nothing, and a
	 * corrupt row must not cost the whole batch its projection.
	 *
	 * @param int[] $queueIds
	 * @return array<int, list<array{itemType: ItemType|null, status: ItemStatus|null, errorCode: string|null}>>
	 *   keyed by queue id; a queue with no element at all is absent
	 */
	public function getQueueLevelSignals(array $queueIds, int $userId, int $chunkSize = 500): array
	{
		$queueIds = self::normalizeIds($queueIds);
		if ($userId <= 0 || $queueIds === [])
		{
			return [];
		}

		$signals = [];
		foreach (array_chunk($queueIds, max(1, $chunkSize)) as $chunk)
		{
			$result = DockedUploadItemTable::query()
				->setDistinct()
				->setSelect(['QUEUE_ID', 'ITEM_TYPE', 'STATUS', 'ERROR_CODE'])
				->whereIn('QUEUE_ID', $chunk)
				->where('QUEUE.USER_ID', $userId)
				->exec()
			;

			while ($row = $result->fetch())
			{
				$signals[(int)$row['QUEUE_ID']][] = [
					'itemType' => $row['ITEM_TYPE'] === null ? null : ItemType::tryFrom((string)$row['ITEM_TYPE']),
					'status' => $row['STATUS'] === null ? null : ItemStatus::tryFrom((string)$row['STATUS']),
					'errorCode' => $row['ERROR_CODE'],
				];
			}
		}

		return $signals;
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function normalizeIds(array $ids): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		)));
	}

	/**
	 * Whether the queue holds an element in an active transfer status
	 * ({@see ItemStatus::isActiveTransfer()}) other than the one named.
	 *
	 * The existence probe {@see \Bitrix\Disk\Public\Command\DockedUpload\UpdateItemCommand}
	 * answers "did my transition flip the queue-level transfer flag?" with: LIMIT 1 over
	 * the QUEUE_ID index instead of reading the whole queue (up to
	 * {@see \Bitrix\Disk\Controller\DockedUploader\Queue::MAX_QUEUE_ITEMS} rows) for every
	 * element that merely joins a transfer already running. USER_ID isolation via the
	 * QUEUE reference.
	 */
	public function hasActiveTransferExcept(int $queueId, int $userId, int $exceptItemId): bool
	{
		if ($queueId <= 0 || $userId <= 0)
		{
			return false;
		}

		$row = $this->activeTransferProbe($queueId, $userId)
			->whereNot('ID', $exceptItemId)
			->fetch()
		;

		return is_array($row);
	}

	private function activeTransferProbe(int $queueId, int $userId): Query
	{
		return DockedUploadItemTable::query()
			->setSelect(['ID'])
			->where('QUEUE_ID', $queueId)
			->whereIn('STATUS', array_map(
				static fn(ItemStatus $status): string => $status->value,
				ItemStatus::activeTransferStatuses(),
			))
			->where('QUEUE.USER_ID', $userId)
			->setLimit(1)
		;
	}

	/**
	 * Whether any of the named elements hangs under a folder-item, i.e. whether the
	 * queue projection has an ancestor row to rebuild at all
	 * ({@see \Bitrix\Disk\Internal\Service\DockedUpload\QueueChangeProjector::collectAncestorIds()}
	 * walks PARENT_ITEM_ID upwards from exactly these elements, so a set without a single
	 * parent link yields no ancestors).
	 *
	 * Scoped by QUEUE_ID as well as by id: an element of another queue is not part of this
	 * projection, the same way the in-memory walk finds no parent for it. USER_ID isolation
	 * via the QUEUE reference.
	 *
	 * @param int[] $itemIds
	 */
	public function hasParentedItem(int $queueId, int $userId, array $itemIds): bool
	{
		$itemIds = self::normalizeIds($itemIds);

		if ($queueId <= 0 || $userId <= 0 || $itemIds === [])
		{
			return false;
		}

		$row = DockedUploadItemTable::query()
			->setSelect(['ID'])
			->where('QUEUE_ID', $queueId)
			->whereIn('ID', $itemIds)
			->where('PARENT_ITEM_ID', '>', 0)
			->where('QUEUE.USER_ID', $userId)
			->setLimit(1)
			->fetch()
		;

		return is_array($row);
	}

	/**
	 * Batch variant of getByQueue: fetches items of several queues in a single
	 * query. USER_ID isolation is preserved through the QUEUE reference.
	 * Grouping by queue is left to the caller.
	 *
	 * The order is deterministic - UPDATE_TIME DESC (freshest first), ID DESC as a
	 * stable tie-break for equal UPDATE_TIME - so the restore temp-check ceiling
	 * (QueueRestoreProvider) truncates a well-defined tail: the freshest items are
	 * reconciled first (API-02, P3.T3 truncation contract).
	 *
	 * @param int[] $queueIds
	 */
	public function getByQueues(array $queueIds, int $userId): DockedUploadItemCollection
	{
		if ($queueIds === [])
		{
			return new DockedUploadItemCollection();
		}

		$query = DockedUploadItemTable::query()
			->setSelect(['*'])
			->whereIn('QUEUE_ID', $queueIds)
			->where('QUEUE.USER_ID', $userId)
			->setOrder(['UPDATE_TIME' => 'DESC', 'ID' => 'DESC'])
		;

		return $this->buildCollection($query);
	}

	/**
	 * Re-reads a set of items by primary id, scoped by USER_ID through the QUEUE
	 * reference. Meant to fetch the fresh rows after a batch UPDATE
	 * (updateStatusByIds/updateStatusByQueue) with a single whereIn query instead
	 * of a per-item re-read.
	 *
	 * @param int[] $ids
	 */
	public function getByIds(array $ids, int $userId): DockedUploadItemCollection
	{
		$ids = array_values(array_unique(array_map('intval', $ids)));
		if ($ids === [])
		{
			return new DockedUploadItemCollection();
		}

		$query = DockedUploadItemTable::query()
			->setSelect(['*'])
			->whereIn('ID', $ids)
			->where('QUEUE.USER_ID', $userId)
		;

		return $this->buildCollection($query);
	}

	/**
	 * Which of the named ids belong to the caller, as a set - the batch authorization of
	 * {@see \Bitrix\Disk\Public\Command\DockedUpload\UpdateItemsCommand}. It carries the
	 * same USER_ID predicate as {@see self::getByIds()} but answers with ids alone: the
	 * caller only needs to know which rows it may touch, and hydrating a batch of elements
	 * it then throws away is exactly the cost this read exists to avoid.
	 *
	 * It is not a permission of its own - an id answered here was the caller's at read
	 * time and nothing more. Every row acted upon is still loaded and isolated where it
	 * always was ({@see \Bitrix\Disk\Internal\Service\DockedUpload\ItemMutationApplier::apply()}),
	 * so this read can only REMOVE work, never grant access.
	 *
	 * @param int[] $ids
	 * @return array<int, true> keyed by item id
	 */
	public function getOwnedIds(array $ids, int $userId): array
	{
		$ids = self::normalizeIds($ids);
		if ($userId <= 0 || $ids === [])
		{
			return [];
		}

		$result = DockedUploadItemTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $ids)
			->where('QUEUE.USER_ID', $userId)
			->exec()
		;

		$owned = [];
		while ($row = $result->fetch())
		{
			$owned[(int)$row['ID']] = true;
		}

		return $owned;
	}

	public function getByParentItem(int $parentItemId, int $userId): DockedUploadItemCollection
	{
		$query = DockedUploadItemTable::query()
			->setSelect(['*'])
			->where('PARENT_ITEM_ID', $parentItemId)
			->where('QUEUE.USER_ID', $userId)
		;

		return $this->buildCollection($query);
	}

	/**
	 * Batch variant of getByParentItem: fetches the direct children of several
	 * folder-items in a single query. Used by the level-by-level descendant walk that
	 * binds files nested in sub-folder-items, so a deep folder tree is traversed
	 * without an N+1 per parent. USER_ID isolation via the QUEUE reference.
	 *
	 * @param int[] $parentItemIds
	 */
	public function getByParentItems(array $parentItemIds, int $userId): DockedUploadItemCollection
	{
		$parentItemIds = array_values(array_unique(array_filter(
			array_map('intval', $parentItemIds),
			static fn(int $id): bool => $id > 0,
		)));
		if ($parentItemIds === [])
		{
			return new DockedUploadItemCollection();
		}

		$query = DockedUploadItemTable::query()
			->setSelect(['*'])
			->whereIn('PARENT_ITEM_ID', $parentItemIds)
			->where('QUEUE.USER_ID', $userId)
		;

		return $this->buildCollection($query);
	}

	public function findByTempToken(string $tempToken, int $userId): ?DockedUploadItem
	{
		/** @var EO_DockedUploadItem|null $ormModel */
		$ormModel = DockedUploadItemTable::query()
			->setSelect(['*'])
			->where('TEMP_TOKEN', $tempToken)
			->where('QUEUE.USER_ID', $userId)
			->setLimit(1)
			->fetchObject()
		;

		if (!$ormModel instanceof EO_DockedUploadItem)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormModel);
	}

	public function findByIntentToken(string $intentToken, int $userId): ?DockedUploadItem
	{
		/** @var EO_DockedUploadItem|null $ormModel */
		$ormModel = DockedUploadItemTable::query()
			->setSelect(['*'])
			->where('INTENT_TOKEN', $intentToken)
			->where('QUEUE.USER_ID', $userId)
			->setLimit(1)
			->fetchObject()
		;

		if (!$ormModel instanceof EO_DockedUploadItem)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormModel);
	}

	/**
	 * The hydration path of every collection read here: the rows are streamed one at a time
	 * and each is turned straight into a domain element
	 * ({@see DockedUploadItemMapper::convertFromArray()}), so neither the whole row set nor
	 * a set of ORM objects is ever held alongside the collection. The elements are the same
	 * ones an object fetch would produce - the projection reads of this repository stay
	 * PARTIAL either way (see {@see self::PROJECTION_SELECT}).
	 */
	private function buildCollection(Query $query): DockedUploadItemCollection
	{
		$collection = new DockedUploadItemCollection();

		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$collection->add($this->mapper->convertFromArray($row));
		}

		return $collection;
	}
}
