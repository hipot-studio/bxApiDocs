<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity\DockedUpload;

use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;

/**
 * In-memory view over a docked folder-item and the files uploaded inside it.
 *
 * This is NOT a persisted table: it is assembled from a folder-item plus every
 * file item nested under it across the whole sub-tree (nested folder-items are
 * grouping rows, excluded) to answer "how many inside, how many ready, is there
 * a blocking error". Statuses that block folder completion are Failed,
 * InterruptedNeedsFile and DuplicateConflict.
 *
 * A folder row reads every counter at once, so they are all folded in one pass over
 * the children instead of a scan per getter.
 */
final class DockedFolderAggregate
{
	private const BLOCKING_STATUSES = [
		ItemStatus::Failed,
		ItemStatus::InterruptedNeedsFile,
		ItemStatus::DuplicateConflict,
	];

	/** @var DockedUploadItem[] */
	private readonly array $childItems;
	private readonly int $readyCount;
	private readonly int $skippedCount;
	private readonly int $readySize;
	private readonly int $rolledSize;
	private readonly ?DockedUploadItem $blockingError;

	/**
	 * @param DockedUploadItem $folderItem the folder-item itself
	 * @param DockedUploadItem[] $childItems every file item nested under the folder
	 *   across the whole sub-tree (nested folder-items excluded)
	 */
	public function __construct(
		private readonly DockedUploadItem $folderItem,
		array $childItems,
	)
	{
		// Reindexed so getChildItems() stays a list whatever keys the caller held.
		$this->childItems = array_values($childItems);

		$readyCount = 0;
		$skippedCount = 0;
		$readySize = 0;
		$rolledSize = 0;
		$blockingError = null;

		foreach ($this->childItems as $item)
		{
			$size = (int)$item->getSize();
			$rolledSize += $size;

			$status = $item->getStatus();
			if ($status === ItemStatus::Committed)
			{
				$readyCount++;
				$readySize += $size;
			}
			elseif ($status === ItemStatus::Canceled)
			{
				$skippedCount++;
			}
			elseif ($blockingError === null && in_array($status, self::BLOCKING_STATUSES, true))
			{
				$blockingError = $item;
			}
		}

		$this->readyCount = $readyCount;
		$this->skippedCount = $skippedCount;
		$this->readySize = $readySize;
		$this->rolledSize = $rolledSize;
		$this->blockingError = $blockingError;
	}

	public function getFolderItem(): DockedUploadItem
	{
		return $this->folderItem;
	}

	/**
	 * @return DockedUploadItem[]
	 */
	public function getChildItems(): array
	{
		return $this->childItems;
	}

	public function getTotalCount(): int
	{
		return count($this->childItems);
	}

	public function getReadyCount(): int
	{
		return $this->readyCount;
	}

	/**
	 * Children that ended without landing in the folder: skipped by the `skip`
	 * conflict policy or cancelled. Both leave the folder short of its file count,
	 * so completion is counted against ready + skipped, and the number itself is
	 * what tells the user why the folder holds fewer files than it queued.
	 */
	public function getSkippedCount(): int
	{
		return $this->skippedCount;
	}

	/**
	 * Committed bytes inside the folder - the numerator of the folder progress.
	 */
	public function getReadySize(): int
	{
		return $this->readySize;
	}

	/**
	 * Every child's bytes, committed or not - the denominator the folder row shows as
	 * its own size, since a folder-item carries no bytes of its own.
	 */
	public function getRolledSize(): int
	{
		return $this->rolledSize;
	}

	/**
	 * First child item carrying a status that blocks folder completion, or null.
	 */
	public function getBlockingError(): ?DockedUploadItem
	{
		return $this->blockingError;
	}

	/**
	 * No child has work left: none blocks and every one of them is terminal
	 * (committed or cancelled). It says the folder is over, not that it succeeded -
	 * whether that end is a success or a cancellation is read off getReadyCount().
	 */
	public function isFinished(): bool
	{
		return $this->blockingError === null
			&& $this->readyCount + $this->skippedCount === $this->getTotalCount();
	}
}
