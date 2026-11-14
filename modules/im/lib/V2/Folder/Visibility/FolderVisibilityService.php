<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Visibility;

use Bitrix\Im\V2\Analytics\FolderAnalytics;
use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Folder\BaseFolder;
use Bitrix\Im\V2\Folder\Cache\FolderCache;
use Bitrix\Im\V2\Folder\Error\FolderError;
use Bitrix\Im\V2\Folder\Folder;
use Bitrix\Im\V2\Folder\FolderCollection;
use Bitrix\Im\V2\Folder\FolderProvider;
use Bitrix\Im\V2\Folder\Pull\FolderUpdate;
use Bitrix\Im\V2\Folder\Source\FolderSourceService;
use Bitrix\Im\V2\Folder\System\AllFolder;

/**
 * The write half of folder visibility (API-01): a folder leaves the navigation by the decision of
 * its owner, and the invariant {@see FolderVisibilityResolver} states is kept while it does -
 * hiding a section never takes its chats away, so a section whose source is switched off in "All"
 * gets that source back before the folder is hidden.
 *
 * One-way on purpose. The return of a folder goes through the save of the "Folder list" screen and
 * nowhere else: a hidden folder is unreachable from the navigation, so the interface has no place
 * to show an immediate "Show" from (PRD AC-008).
 *
 * No transaction, as everywhere in the folder domain. The order of the steps carries the same
 * property instead: every check runs before the first write, and the source comes back before the
 * folder goes, so a request torn between the two leaves a source enabled rather than a folder
 * hidden with its chats out of reach. The checks and the writes run under
 * {@see FolderVisibilityLock}, the lock every save of the composition of "All" takes as well.
 */
class FolderVisibilityService
{
	public function __construct(
		private readonly FolderVisibilityResolver $visibilityResolver,
		private readonly FolderSourceService $folderSourceService,
		private readonly FolderProvider $folderProvider,
		private readonly FolderCache $folderCache,
		private readonly FolderVisibilityLock $visibilityLock,
	) {}

	/**
	 * A thin wrapper around the write path: hiding has several points of return and an observer of
	 * the operation needs every one of them, the refusals included, so the single point of
	 * reporting belongs here and not next to each of them
	 * ({@see \Bitrix\Im\V2\Folder\FolderService::create()}).
	 */
	public function hide(Folder $folder): HideResult
	{
		$result = $this->hideInternal($folder);

		// The owner is taken from the folder and not from the current user, as everywhere in the
		// domain: a pair passed in from outside can disagree, and a disagreeing pair would report
		// the decision of one user under another.
		(new FolderAnalytics((int)($folder->getUserId() ?? 0)))->addHideFolder(
			$result->getReturnedSourceCodes(),
			$result->getError()?->getCode(),
		);

		return $result;
	}

	private function hideInternal(Folder $folder): HideResult
	{
		// Narrowed once for the whole method: the invariant is stated over {@see BaseFolder}, and
		// so is the decision it writes - the interface carries neither.
		if (!$folder instanceof BaseFolder)
		{
			return (new HideResult())->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		$userId = (int)($folder->getUserId() ?? 0);
		if ($userId <= 0)
		{
			return (new HideResult())->addError(new Error(FolderError::FOLDER_ACCESS_DENIED));
		}

		if (!$this->visibilityLock->acquire($userId))
		{
			return (new HideResult())->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		try
		{
			$result = $this->writeHidden($folder, $userId);
		}
		finally
		{
			$this->visibilityLock->release($userId);
		}

		if (!$result->isSuccess())
		{
			return $result;
		}

		// Read strictly after the cache was dropped - an earlier read would answer with the snapshot
		// from before the write. The push and the answer are built from this one collection and share
		// one resolution of the dialog ids.
		$folders = $this->folderProvider->getByUser($userId)->onlyAvailable($userId);
		$dialogIdMap = $folders->buildDialogIdMap();

		$hiddenFolder = $folders[(int)$folder->getId()] ?? $folder;
		(new FolderUpdate($hiddenFolder, $userId, ['dialogIdMap' => $dialogIdMap]))->send();

		return $result
			->setFolders($folders)
			->setDialogIdMap($dialogIdMap)
		;
	}

	private function writeHidden(BaseFolder $folder, int $userId): HideResult
	{
		$result = new HideResult();

		// One read of the list before the first write: it carries the folder the composition of the
		// invariant is stored on, and reading it also seeds that composition for the read below.
		$allFolder = $this->findAllFolder($this->folderProvider->getByUser($userId));
		$storedSourceCodes = $allFolder === null
			? []
			: $this->folderSourceService->getStoredSourceCodes($allFolder)
		;

		if (!$this->visibilityResolver->canHide($folder, $userId))
		{
			return $result->addError(new Error(FolderError::FOLDER_HIDE_NOT_ALLOWED));
		}

		$sourceToReturn = $this->visibilityResolver->resolveSourceToReturn($folder, $userId, $storedSourceCodes);
		$returnedSourceCodes = [];

		if ($sourceToReturn !== null)
		{
			if ($allFolder === null)
			{
				return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
			}

			// The first write of the operation, and it comes first on purpose: a refusal here leaves
			// the folder visible and nothing written at all. The folderUpdate of the general list is
			// sent by the composition service itself - the hide adds no second push for it.
			$enableResult = $this->folderSourceService->enableSources($allFolder, [$sourceToReturn]);
			if (!$enableResult->isSuccess())
			{
				return $result->addErrors($enableResult->getErrors());
			}

			$returnedSourceCodes[] = $sourceToReturn;
		}

		// Straight through the entity: a system folder never reaches
		// {@see \Bitrix\Im\V2\Folder\FolderService::update()}, which turns down everything but a
		// personal folder, and the composition of "All" is written past it for the same reason.
		$saveResult = $folder->setHiddenByUser(true)->save();
		if (!$saveResult->isSuccess())
		{
			return $result->addErrors($saveResult->getErrors());
		}

		$this->folderCache->clearByUser($userId);

		return $result->setReturnedSourceCodes($returnedSourceCodes);
	}

	/**
	 * The "All" of the user, or null when the user has none - the composition the invariant is answered
	 * against is stored on that folder and on no other.
	 */
	private function findAllFolder(FolderCollection $folders): ?AllFolder
	{
		foreach ($folders as $folder)
		{
			if ($folder instanceof AllFolder)
			{
				return $folder;
			}
		}

		return null;
	}
}
