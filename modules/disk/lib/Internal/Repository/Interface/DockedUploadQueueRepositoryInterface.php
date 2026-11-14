<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Interface;

use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadQueue;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadQueueCollection;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Main\Repository\RepositoryInterface;

/**
 * DI-seam for docked upload queue persistence.
 *
 * The repository is the single ORM access point to the queue tables; commands
 * and services must work with entities, not with the ORM Table classes.
 * Every read is scoped by USER_ID (isolation invariant).
 */
interface DockedUploadQueueRepositoryInterface extends RepositoryInterface
{
	public function getById(int $id): ?DockedUploadQueue;

	/**
	 * The named queues of the user in ONE query - the batch form of {@see self::getById()},
	 * for a caller that holds a whole set of rows and needs the queue of each (the queue
	 * status the item DTO is built against). Unlike {@see self::getActiveByUser()} it
	 * filters on nothing but the ids and the USER_ID gate, so a dismissed, completed or
	 * expired queue is returned exactly as getById() returns it.
	 *
	 * @param int[] $ids
	 */
	public function getByIds(array $ids, int $userId): DockedUploadQueueCollection;

	public function save(DockedUploadQueue $queue): void;

	public function delete(int $id): void;

	/**
	 * All queues of the user that are not over yet
	 * ({@see \Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus::restorableCases()}),
	 * i.e. the set to offer for restore.
	 */
	public function getActiveByUser(int $userId): DockedUploadQueueCollection;

	/**
	 * Whether {@see self::getActiveByUser()} would return anything - the cheap probe a
	 * page asks before it pays for the restore itself. Implementations must answer it off
	 * the SAME predicate the selection uses: the two may never disagree.
	 */
	public function hasActiveByUser(int $userId): bool;

	/**
	 * Every queue of the user that is not over yet, EXPIRES_AT included - the set the
	 * widget-level close works on
	 * ({@see \Bitrix\Disk\Public\Command\DockedUpload\DismissCommand}).
	 *
	 * Deliberately wider than {@see self::getActiveByUser()}, by exactly the expired rows,
	 * and it has to be: what a queue may still commit is decided by its STATUS alone
	 * ({@see QueueStatus::acceptsCommit()}), while EXPIRES_AT is written once at creation
	 * and nothing ever moves it - so a tab older than the TTL holds a queue that still
	 * transfers and still fixes files. A close that covered less than that would answer
	 * "closed" over a queue a neighbouring tab then carries through to an object, a version
	 * and a workflow. Restore keeps the narrower predicate: an expired queue is still
	 * nothing to bring back (AC-077).
	 */
	public function getUnfinishedByUser(int $userId): DockedUploadQueueCollection;

	/**
	 * Promotes the queue to active iff it is still pending, as one guarded UPDATE
	 * (race-safe and idempotent): the first transfer started in a queue is what makes
	 * it active. Returns whether THIS call promoted it.
	 */
	public function markActiveIfPending(int $queueId): bool;

	/**
	 * Completes the queue iff no file leaf is left uncommitted/uncanceled, as one
	 * guarded UPDATE (race-safe, idempotent, and its status guard skips a dismissed
	 * queue). Returns whether THIS call flipped it to completed.
	 */
	public function markCompletedIfDrained(int $queueId): bool;

	/**
	 * Restores the queue to active iff it is stopped in place (API-07), as one guarded
	 * UPDATE. Returns whether THIS call restored it.
	 */
	public function markActiveIfStopped(int $queueId): bool;

	/**
	 * Stops the queue in the state a space shortage earned it, iff the queue still accepts
	 * commits ({@see \Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus::acceptsCommit()}),
	 * as one guarded UPDATE: a queue the user dismissed, one that completed meanwhile and one
	 * already stopped all keep their state. Returns whether THIS call stopped it.
	 */
	public function markStoppedByQuota(int $queueId, QueueStatus $status): bool;

	/**
	 * Marks the user's named unfinished queues dismissed - the widget-level close
	 * (SC-015) - in one statement per chunk, leaving the already completed ones alone
	 * (AC-086). Returns how many queues were dismissed.
	 *
	 * @param int[] $queueIds
	 */
	public function markDismissedByIds(array $queueIds, int $userId): int;

	/**
	 * Which of the named queues of the user are dismissed right now - what the close reads
	 * back after {@see self::markDismissedByIds()}, so a queue the status guard left alone
	 * (AC-086) stays out of everything the close does next.
	 *
	 * @param int[] $queueIds
	 * @return int[]
	 */
	public function findDismissedIds(array $queueIds, int $userId): array;
}
