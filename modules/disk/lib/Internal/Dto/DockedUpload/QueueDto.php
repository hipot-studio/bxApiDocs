<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Dto\DockedUpload;

use Bitrix\Disk\Internal\Entity\DockedUpload\DockedFolderAggregate;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItemCollection;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadQueue;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Internal\Service\DockedUpload\DisplayStatusResolver;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueTargetNameResolver;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueTargetNames;
use JsonSerializable;

/**
 * Serializable payload of a whole docked upload queue for restore + pull (DTO-01).
 *
 * items[] is flat and holds every element of the queue - top-level ones and the
 * nested files / folder-items alike - so each one is addressable by its own itemId
 * and linked to its owner through {@see ItemDto::$parentItemId}. A folder-item
 * still carries the rolled-up aggregate of its sub-tree, so it renders as a single
 * row without the client walking its children.
 */
final class QueueDto implements JsonSerializable
{
	/**
	 * @param string[] $availableActions
	 * @param bool $restorable whether restore would bring this queue back after a page
	 *   reload (API-02, AC-077). The client gates the close confirmation (AC-080) on it
	 *   instead of guessing from the item statuses: a queue whose only element failed
	 *   unrepeatably offers no action at all, yet restore still returns it, so guessing
	 *   dropped the user's unfinished work silently. Computed by
	 *   {@see DockedUploadQueue::isRestorable()} - the same predicate the restore filter
	 *   selects rows with.
	 * @param ItemDto[] $items
	 * @param string|null $storageName human-readable title of the storage this queue
	 *   uploads into ("My Drive", the group name, ...), as the product names it -
	 *   {@see \Bitrix\Disk\ProxyType\Base::getTitleForCurrentUser()}. Null when the
	 *   destination no longer resolves (create does not verify targets) or when the caller
	 *   built the DTO without a {@see QueueTargetNameResolver}; the client then shows no
	 *   destination line rather than an empty one.
	 * @param string|null $targetFolderName name of the folder the queue lands in, null for
	 *   the ROOT of the storage - the root folder is the storage itself and carries no name
	 *   a user would recognise, so the client shows the storage alone there.
	 * @param string|null $storageImageSrc picture of the storage the widget draws as the
	 *   avatar of the section - {@see \Bitrix\Disk\ProxyType\Base::getEntityImageSrc()}.
	 *   Published under the same access check as the names, null whenever they are.
	 * @param string|null $storageKind kind of the storage that shapes the avatar - one of
	 *   the QueueTargetNames::STORAGE_KIND_* values, null whenever the names are.
	 */
	public function __construct(
		public readonly string $queueId,
		public readonly string $status,
		public readonly string $displayStatus,
		public readonly array $availableActions,
		public readonly bool $restorable,
		public readonly ?string $infoHelperCode,
		public readonly ?string $quotaScenario,
		public readonly int $totalSize,
		public readonly int $uploadedSize,
		public readonly array $items,
		public readonly ?string $storageName = null,
		public readonly ?string $targetFolderName = null,
		public readonly ?string $storageImageSrc = null,
		public readonly ?string $storageKind = null,
	)
	{
	}

	/**
	 * @param array<int, string> $clientIdByItemId server itemId => request clientId,
	 *   set only for the create response so its items echo the client key; empty on
	 *   the restore / pull paths where the echo is null (DTO-01).
	 * @param QueueTargetNameResolver|null $targetNameResolver resolves the destination names
	 *   ONCE for the whole queue - the single point they are read at, so no path spells the
	 *   storage title twice and none of them asks per element. It answers for the OWNER of
	 *   the queue, read off the queue row itself: the names go to that user and to no one
	 *   else, and the resolver publishes them only if their rights allow it. Passed by every caller whose
	 *   answer carries the whole DTO (create, restore, the queue-wide mutations); omitted by
	 *   {@see \Bitrix\Disk\Internal\Service\DockedUpload\QueueChangeProjector}, which reads
	 *   only the folder-items and the queue-level verdicts off this DTO and runs on EVERY
	 *   element mutation - resolving names there would cost two lookups per committed file
	 *   for a payload that never carries them.
	 * @param array<int, true>|null $onlyItemIds when given, `items` carries a DTO for these
	 *   element ids ALONE; every queue-level verdict is still folded over the whole
	 *   collection, so the narrowing changes the payload and nothing else. Only
	 *   {@see \Bitrix\Disk\Internal\Service\DockedUpload\QueueChangeProjector} passes it: it
	 *   keeps the ancestors of the changed elements and discards the rest, and building the
	 *   discarded ones is far from free - every folder DTO walks its whole sub-tree
	 *   ({@see self::collectFileDescendants()}), so a foldered queue paid O(N x depth) on
	 *   each committed file to answer with a handful of rows.
	 */
	public static function fromEntities(
		DockedUploadQueue $queue,
		DockedUploadItemCollection $items,
		DisplayStatusResolver $resolver,
		array $clientIdByItemId = [],
		?QueueTargetNameResolver $targetNameResolver = null,
		?array $onlyItemIds = null,
	): self
	{
		$queueStatus = $queue->getStatus() ?? QueueStatus::Active;

		/** @var array<int, DockedUploadItem[]> $childrenByParent */
		$childrenByParent = [];
		foreach ($items as $item)
		{
			$parentId = $item->getParentItemId();
			if ($parentId !== null)
			{
				$childrenByParent[$parentId][] = $item;
			}
		}

		$itemDtos = [];
		$totalSize = 0;
		$uploadedSize = 0;
		$hasActiveTransfer = false;
		$hasRetriableFailed = false;
		$hasActionable = false;
		$hasItemAwaitingDecision = false;
		/** @var array<int, string|null> $failedErrorCodes */
		$failedErrorCodes = [];

		foreach ($items as $item)
		{
			$isLeaf = !$item->isFolder();

			// totalSize counts leaves only: a folder-item's bytes are already
			// represented by its children, so summing both would double-count it. A
			// childless folder-item (children absent from this batch) has no leaf to
			// stand in for it, so it falls back to its own size.
			if ($isLeaf || ($childrenByParent[(int)$item->getId()] ?? []) === [])
			{
				$totalSize += (int)$item->getSize();
			}

			$status = $item->getStatus();
			if ($isLeaf && $status === ItemStatus::Committed)
			{
				// uploadedSize is the committed-byte numerator of the queue progress;
				// only leaves carry real bytes (a folder-item is a grouping row).
				$uploadedSize += (int)$item->getSize();
			}
			if ($status !== null && $status->isActiveTransfer())
			{
				$hasActiveTransfer = true;
			}
			if ($status === ItemStatus::Failed)
			{
				// retryFailed is offered only when the scope:failed batch would actually
				// requeue something, so the queue gate applies the same isRetriable() rule
				// RetryCommand does. The quota scenario is detected from EVERY failure -
				// its culprit is unrepeatable by definition, yet still drives the banner.
				$hasRetriableFailed = $hasRetriableFailed || $resolver->isRetriable($item->getErrorCode());
				$failedErrorCodes[] = $item->getErrorCode();
			}
			// Only leaves carry work. A folder-item's own status does move - a whole-queue
			// cancel writes canceled on it too - but it never commits, so a queue whose
			// files are all stored would keep offering cancelAll forever on a grouping row
			// still sitting in pending. Its real state is the aggregate over its leaves.
			if ($isLeaf && $status !== null && $status !== ItemStatus::Committed && $status !== ItemStatus::Canceled)
			{
				$hasActionable = true;
			}
			// Leaves only, for the same reason: a folder-item mirrors the verdict of its
			// sub-tree, so counting it too would let one blocked file say "waiting on you"
			// twice - and a grouping row never waits on a decision of its own.
			if ($isLeaf && $status !== null && $resolver->awaitsDecision($status))
			{
				$hasItemAwaitingDecision = true;
			}
		}

		foreach ($items as $item)
		{
			$itemId = (int)$item->getId();
			if ($onlyItemIds !== null && !isset($onlyItemIds[$itemId]))
			{
				continue;
			}

			$clientId = $clientIdByItemId[$itemId] ?? null;
			if ($item->isFolder())
			{
				$aggregate = new DockedFolderAggregate(
					$item,
					self::collectFileDescendants($itemId, $childrenByParent),
				);
				$itemDtos[] = ItemDto::fromFolderItem($item, $aggregate, $queueStatus, $resolver, $clientId);
			}
			else
			{
				$itemDtos[] = ItemDto::fromFileItem($item, $queueStatus, $resolver, $clientId);
			}
		}

		$quotaPayload = self::buildQuotaPayload($failedErrorCodes, $resolver);
		$targetNames = $targetNameResolver?->resolve($items, (int)$queue->getUserId()) ?? new QueueTargetNames();

		return new self(
			queueId: (string)$queue->getId(),
			status: $queueStatus->value,
			displayStatus: $resolver->resolveQueueDisplayStatus(
				$queueStatus,
				$hasActiveTransfer,
				$hasItemAwaitingDecision,
			),
			availableActions: $resolver->resolveQueueActions(
				$queueStatus,
				$hasActiveTransfer,
				$hasRetriableFailed,
				$hasActionable,
			),
			restorable: $queue->isRestorable(),
			infoHelperCode: $quotaPayload['infoHelperCode'],
			quotaScenario: $quotaPayload['quotaScenario'],
			totalSize: (int)$totalSize,
			uploadedSize: (int)$uploadedSize,
			items: $itemDtos,
			storageName: $targetNames->storageName,
			targetFolderName: $targetNames->targetFolderName,
			storageImageSrc: $targetNames->storageImageSrc,
			storageKind: $targetNames->storageKind,
		);
	}

	/**
	 * Every file (leaf) item nested under the folder-item across the whole sub-tree,
	 * not just its direct children. Nested folder-items are grouping rows that never
	 * commit and carry no bytes, so they are walked through but never returned - the
	 * aggregate must roll up the deep leaves (count / ready / size / blocking error).
	 * A seen-set guards against a cyclic PARENT_ITEM_ID chain, mirroring
	 * {@see \Bitrix\Disk\Internal\Service\DockedUpload\FolderTreeBuilder::collectFileDescendants()}.
	 *
	 * @param array<int, DockedUploadItem[]> $childrenByParent parentItemId => direct children
	 * @return DockedUploadItem[]
	 */
	private static function collectFileDescendants(int $folderItemId, array $childrenByParent): array
	{
		$fileItems = [];
		$level = [$folderItemId];
		$seenFolders = [$folderItemId => true];

		while ($level !== [])
		{
			$nextLevel = [];
			foreach ($level as $parentId)
			{
				foreach ($childrenByParent[$parentId] ?? [] as $child)
				{
					if (!$child->isFolder())
					{
						$fileItems[] = $child;

						continue;
					}

					$childId = (int)$child->getId();
					if (!isset($seenFolders[$childId]))
					{
						$seenFolders[$childId] = true;
						$nextLevel[] = $childId;
					}
				}
			}

			$level = $nextLevel;
		}

		return $fileItems;
	}

	/**
	 * The queue-level quota classification of the failed elements: the scenario that picks
	 * the banner CTA and the info-helper slider that CTA opens.
	 *
	 * Shared with the flat-queue path of
	 * {@see \Bitrix\Disk\Internal\Service\DockedUpload\QueueChangeProjector}, which probes
	 * the codes instead of reading the elements: the precedence and the pairing stay
	 * computed here, in one place, whichever way the codes were collected.
	 *
	 * @param iterable<string|null> $failedErrorCodes ERROR_CODE of every failed element of
	 *   the queue, in any order and with the duplicates left in - the classification is a
	 *   membership question, not a count
	 * @return array{quotaScenario: string|null, infoHelperCode: string|null}
	 */
	public static function buildQuotaPayload(iterable $failedErrorCodes, DisplayStatusResolver $resolver): array
	{
		$quotaScenario = null;
		foreach ($failedErrorCodes as $errorCode)
		{
			$quotaScenario = self::detectQuotaScenario($errorCode, $quotaScenario);
		}

		return [
			'quotaScenario' => $quotaScenario,
			'infoHelperCode' => $quotaScenario === DisplayStatusResolver::ERROR_QUOTA_CLOUD
				? $resolver->resolveQuotaInfoHelperCode()
				: null,
		];
	}

	/**
	 * Keeps the first quota culprit's scenario (cloud wins over box for the CTA).
	 */
	private static function detectQuotaScenario(?string $code, ?string $current): ?string
	{
		if ($code === DisplayStatusResolver::ERROR_QUOTA_CLOUD)
		{
			return DisplayStatusResolver::ERROR_QUOTA_CLOUD;
		}
		if ($code === DisplayStatusResolver::ERROR_QUOTA_BOX && $current === null)
		{
			return DisplayStatusResolver::ERROR_QUOTA_BOX;
		}

		return $current;
	}

	/**
	 * The queue-level quota classification alone, so the item-level answers and the pull
	 * event can carry it without shipping the whole queue
	 * ({@see \Bitrix\Disk\Internal\Service\DockedUpload\QueueChangeProjector}). Reading it
	 * off the DTO keeps {@see self::buildQuotaPayload()} the single place it is computed.
	 *
	 * The pair always travels together: quotaScenario picks the CTA of the banner and
	 * infoHelperCode is the slider that CTA opens, so half of it would offer the user a
	 * button with nothing behind it.
	 *
	 * @return array{quotaScenario: string|null, infoHelperCode: string|null}
	 */
	public function quotaPayload(): array
	{
		return [
			'quotaScenario' => $this->quotaScenario,
			'infoHelperCode' => $this->infoHelperCode,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	public function toArray(): array
	{
		return [
			'queueId' => $this->queueId,
			'status' => $this->status,
			'displayStatus' => $this->displayStatus,
			'availableActions' => $this->availableActions,
			'restorable' => $this->restorable,
			'infoHelperCode' => $this->infoHelperCode,
			'quotaScenario' => $this->quotaScenario,
			'totalSize' => $this->totalSize,
			'uploadedSize' => $this->uploadedSize,
			'items' => array_map(static fn(ItemDto $item) => $item->toArray(), $this->items),
			'storageName' => $this->storageName,
			'targetFolderName' => $this->targetFolderName,
			'storageImageSrc' => $this->storageImageSrc,
			'storageKind' => $this->storageKind,
		];
	}
}
