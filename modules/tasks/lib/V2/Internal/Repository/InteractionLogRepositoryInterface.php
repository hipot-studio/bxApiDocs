<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Service\Ranking\EventType;
use Bitrix\Tasks\V2\Internal\Service\Ranking\RelationKind;

interface InteractionLogRepositoryInterface
{
	// Per-run ceiling for the age-based purge loop: the agent drains a bounded slice
	// per tick instead of one heavy daily burst.
	public const PURGE_MAX_BATCHES_PER_RUN = 30;

	/**
	 * Inserts one event per task id in a single batched write - the only write entry
	 * point.
	 *
	 * @param int[] $taskIds
	 */
	public function appendBatch(
		int $userId,
		array $taskIds,
		EventType $eventType,
		?RelationKind $relationKind,
		DateTime $createdAt,
	): void;

	/**
	 * Whether the user has any "open" event at or after the threshold, regardless
	 * of task. Global per-user throttle protecting the journal from scripted
	 * task-id enumeration on the open endpoint.
	 */
	public function hasRecentOpenForUser(int $userId, DateTime $threshold): bool;

	/**
	 * Write-path throttle guard: task ids among the given set that already have an
	 * event of this type at or after the threshold. A given $relationKind scopes the
	 * check to that kind, so different kinds on one task do not collapse into one.
	 *
	 * @param int[] $taskIds
	 * @return int[]
	 */
	public function findTaskIdsWithRecentEvent(
		int $userId,
		array $taskIds,
		EventType $eventType,
		?RelationKind $relationKind,
		DateTime $threshold,
	): array;

	/**
	 * Streams the requesting user's events not older than the cutoff, ordered by the
	 * (CREATED_AT, ID) pair descending - the same freshness order the trim uses - and
	 * capped by the events limit.
	 */
	public function findEventsForWindow(int $userId, DateTime $cutoff): iterable;

	/**
	 * Deletes events older than the cutoff across all users, in bounded batches
	 * over the CREATED_AT index (not a single deleteByFilter).
	 */
	public function purgeOlderThan(DateTime $cutoff): void;

	/**
	 * Deletes a user's rows beyond the freshest $cap, in bounded batches. The
	 * (cap+1)-th freshest row by the (CREATED_AT, ID) pair is the boundary: it and
	 * everything below it go, same-second neighbours included.
	 *
	 * Returns true when the $maxBatches ceiling stopped a still-full run and a
	 * follow-up run is needed, false when the tail was exhausted.
	 */
	public function trimUserToCap(int $userId, int $cap, int $batchSize, int $maxBatches): bool;
}
