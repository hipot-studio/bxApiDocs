<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Avatar\Resource;

use Bitrix\Main\Result;

interface AvatarResourceStorage
{
	public const STATE_PENDING = 'pending';
	public const STATE_ATTACHED = 'attached';
	public const STATE_ORPHANED = 'orphaned';
	public const STATE_DELETING = 'deleting';

	/**
	 * Runs the operation while all requested resources are locked in one transaction.
	 * A failed result must roll the operation back; a successful result commits it.
	 *
	 * @param int[] $resourceIds
	 * @param callable(array<int, array{state: string}>): Result $operation
	 */
	public function lock(array $resourceIds, callable $operation): Result;

	public function changeState(int $resourceId, string $state): Result;

	public function isBound(int $resourceId): bool;

	public function canDelete(int $resourceId, array $resource, bool $discardPending): bool;

	/** @return int[] */
	public function findCleanupResourceIds(int $cursor, int $limit, ?int &$nextCursor = null): array;

	public function markAttempt(int $resourceId): void;

	/** Deletes the persistent file and then its resource row after the deleting state has been committed. */
	public function delete(int $resourceId): Result;
}
