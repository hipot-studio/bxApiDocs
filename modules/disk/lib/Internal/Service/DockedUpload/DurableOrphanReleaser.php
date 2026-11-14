<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;
use Throwable;

/**
 * Deletes the durable crash-window artifact left behind by an element that ends canceled.
 *
 * A crash between the domain write of the commit (Folder::addFile / File::addVersion,
 * already durable and its events already dispatched) and the atomic update that binds
 * DISK_OBJECT_ID leaves a live disk object - or a live version - carrying the item's
 * INTENT_TOKEN while the row is still non-committed. Nothing else collects it: a canceled
 * row is out of the durable reconciliation by construction
 * ({@see \Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus::isDurableReconcilable()}), so
 * neither the restore ({@see \Bitrix\Disk\Public\Provider\DockedUpload\QueueRestoreProvider})
 * nor the TTL agent ({@see \Bitrix\Disk\Infrastructure\Agent\DockedUpload\QueueCleanupAgent})
 * would ever heal it, and the object would stay in the user's folder for good.
 *
 * Every path that cancels an element therefore ends here - the per-queue and per-item
 * cancel ({@see \Bitrix\Disk\Public\Command\DockedUpload\CancelCommand}) and the close of
 * the whole widget ({@see \Bitrix\Disk\Public\Command\DockedUpload\DismissCommand}) alike.
 * They used to carry their own copy of this, and the copy the close never got is what let
 * a dismissed element keep its artifact.
 *
 * Best-effort throughout, and always outside the queue transaction: an orphaned artifact
 * is a resource leak, not corruption, and a storage hiccup must never roll a cancel back.
 */
class DurableOrphanReleaser
{
	public function __construct(
		private readonly CommitReconciler $reconciler = new CommitReconciler(),
	)
	{
	}

	/**
	 * @param DockedUploadItem[] $canceledTargets the elements the caller has just moved
	 *   out of their transport state; already-terminal ones are the caller's to filter
	 */
	public function release(array $canceledTargets, DockedUploadItemRepository $itemRepository, int $userId): void
	{
		// First pass - collect the crash-window candidates (carried an INTENT_TOKEN but
		// never bound a DISK_OBJECT_ID) together with their intent token.
		$candidates = [];
		foreach ($canceledTargets as $item)
		{
			$intentToken = (string)$item->getIntentToken();
			if ($intentToken === '' || (int)$item->getDiskObjectId() > 0)
			{
				continue;
			}

			$candidates[] = ['item' => $item, 'intentToken' => $intentToken];
		}

		if ($candidates === [])
		{
			return;
		}

		// Re-read every candidate after the cancel transaction with a single batch
		// query instead of a getById per candidate.
		$candidateIds = array_map(static fn(array $candidate): int => (int)$candidate['item']->getId(), $candidates);
		$freshById = [];
		foreach ($itemRepository->getByIds($candidateIds, $userId) as $freshItem)
		{
			$freshById[(int)$freshItem->getId()] = $freshItem;
		}

		// Second pass - drop the artifact only when the item actually ended up canceled
		// with no bound object; a concurrent commit that won the row keeps its artifact.
		foreach ($candidates as $candidate)
		{
			$item = $candidate['item'];
			$fresh = $freshById[(int)$item->getId()] ?? null;
			if (
				$fresh === null
				|| $fresh->getStatus() !== ItemStatus::Canceled
				|| (int)$fresh->getDiskObjectId() > 0
			)
			{
				continue;
			}

			$this->deleteDurableArtifact($item, $candidate['intentToken'], $userId);
		}
	}

	private function deleteDurableArtifact(DockedUploadItem $item, string $intentToken, int $userId): void
	{
		try
		{
			$targetFileId = $item->getTargetFileId();
			if ($targetFileId !== null && $targetFileId > 0)
			{
				// Version branch: drop only the version created by this intent token; the
				// pre-existing file (and its earlier versions) is never touched.
				$this->reconciler->findVersionByIntentToken($intentToken)?->delete($userId);

				return;
			}

			// New-file branch: the whole object is the orphan.
			$this->reconciler->findFileByIntentToken($intentToken)?->delete($userId);
		}
		catch (Throwable)
		{
			// Best-effort; an orphaned artifact is a resource leak, not corruption.
		}
	}
}
