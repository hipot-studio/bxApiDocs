<?php

namespace Bitrix\Im\V2\Integration\HumanResources\Sync;

use Bitrix\Im\V2\Integration\HumanResources\Sync\Result\IterationResult;
use Bitrix\Im\V2\Integration\HumanResources\Sync\Result\QueueItemResult;
use Bitrix\Im\V2\Result;

interface SyncProcessor
{
	/** @return array<int, Item\SyncInfo> one page of candidates past $afterId, oldest first, keyed by queue row id */
	public function dequeueCandidates(int $afterId = 0): array;
	public function getQueueCursor(): int;
	public function setQueueCursor(int $afterId): void;
	public function getOrCreateWithLock(Item\SyncInfo $syncInfo): QueueItemResult;
	public function tryGetWithLock(Item\SyncInfo $syncInfo): ?Item\QueueItem;
	public function tryLockRun(Item\SyncInfo $syncInfo): bool;
	public function logRecoveryOf(Item\SyncInfo $syncInfo): void;
	public function unlockRun(Item\SyncInfo $syncInfo): bool;
	public function makeIteration(Item\QueueItem $item): IterationResult;
	public function finalizeSync(Item\QueueItem $item): Result;
	public function hasItemsInQueue(): bool;
}