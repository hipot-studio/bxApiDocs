<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Sort;

use Bitrix\Im\Model\FolderTable;
use Bitrix\Im\V2\Analytics\FolderAnalytics;
use Bitrix\Im\V2\Common\BulkUpdateExpression;
use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Folder\BaseFolder;
use Bitrix\Im\V2\Folder\Cache\FolderCache;
use Bitrix\Im\V2\Folder\Error\FolderError;
use Bitrix\Im\V2\Folder\FolderBootstrapper;
use Bitrix\Im\V2\Folder\FolderCollection;
use Bitrix\Im\V2\Folder\FolderProvider;
use Bitrix\Im\V2\Folder\PersonalFolder;
use Bitrix\Im\V2\Folder\Pull\FolderSort;
use Bitrix\Im\V2\Folder\Pull\FolderUpdate;
use Bitrix\Im\V2\Folder\Source\FolderSourceService;
use Bitrix\Im\V2\Folder\System\AllFolder;
use Bitrix\Im\V2\Folder\System\SystemFolder;
use Bitrix\Im\V2\Folder\System\SystemFolderRegistry;
use Bitrix\Im\V2\Folder\Visibility\FolderVisibilityLock;
use Bitrix\Im\V2\Folder\Visibility\FolderVisibilityResolver;
use Bitrix\Im\V2\Result;

/**
 * The "Folder list" screen as a whole (API-02): which folders the navigation shows, in which order,
 * and which of them the owner has taken out of it. The three are one operation because they are one
 * screen - a save that applied the order and refused the hiding would leave the client showing a
 * list nobody asked for.
 *
 * No transaction, as everywhere in the folder domain; the order of the steps carries the same
 * property instead. Every check runs before the first write, so a refusal leaves nothing written at
 * all, and the sections of the folders leaving the navigation come back to "All" ahead of the two
 * writes of state and order (AC-013) - a request torn in the middle leaves a source enabled rather
 * than a folder hidden with its chats out of reach. The whole save runs under
 * {@see FolderVisibilityLock}, the lock every save of the composition of "All" takes as well.
 */
class FolderSortService
{
	private array $sortedFolderIdsCache = [];

	public function __construct(
		private readonly FolderProvider $folderProvider,
		private readonly FolderBootstrapper $folderBootstrapper,
		private readonly FolderCache $folderCache,
		private readonly SystemFolderRegistry $systemFolderRegistry,
		private readonly FolderVisibilityResolver $visibilityResolver,
		private readonly FolderSourceService $folderSourceService,
		private readonly FolderVisibilityLock $visibilityLock,
	) {}

	public function getSortedFolderIds(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		if (isset($this->sortedFolderIdsCache[$userId]))
		{
			return $this->sortedFolderIdsCache[$userId];
		}

		$this->folderBootstrapper->ensureSystemFolders($userId);

		// FolderProvider returns folders ordered by SORT, ID.
		$collection = $this->folderProvider->getByUser($userId)->onlyAvailable($userId);

		$folderIds = [];
		foreach ($collection as $folder)
		{
			$id = $folder->getId();
			if ($id !== null)
			{
				$folderIds[] = $id;
			}
		}

		$this->sortedFolderIdsCache[$userId] = $folderIds;

		return $folderIds;
	}

	/**
	 * The set saveSortOrder() expects from a client: folders the tab panel shows, in server order.
	 * Every other row of the user is a hidden anchor keeping its own slot, so it carries no order
	 * and is not asked for - a folder the user hid themselves included, of a system or a personal
	 * type alike, as long as "All" is there to keep its chats reachable. Not memoized on purpose -
	 * the answer must follow the order stored right now, and a second per-request cache would need
	 * a second invalidation point in saveSortOrder().
	 *
	 * @return int[]
	 */
	public function getSortableFolderIds(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$this->folderBootstrapper->ensureSystemFolders($userId);

		[$sortableFolderIds] = $this->splitVisibleAndHiddenIds($this->fetchFolderRows($userId), $userId);

		return $sortableFolderIds;
	}

	/**
	 * Reordering alone, the request `im.v2.Folder.sort` carries. The same implementation with both
	 * sets empty and not a write path of its own: a second way to store the order would have to
	 * repeat the anchor rule, and the two would drift apart the first time one of them changed.
	 *
	 * @param int[] $visibleFolderIds
	 */
	public function saveSortOrder(int $userId, array $visibleFolderIds): Result
	{
		return $this->saveFolderList($userId, new SaveListFields(array_values($visibleFolderIds)));
	}

	/**
	 * A thin wrapper around the write path: the save has a dozen points of return and an observer of
	 * the operation needs every one of them, the refusals included, so the single point of reporting
	 * belongs here and not next to each of them
	 * ({@see \Bitrix\Im\V2\Folder\FolderService::create()}).
	 */
	public function saveFolderList(int $userId, SaveListFields $fields): SaveListResult
	{
		$result = $this->saveFolderListUnderLock($userId, $fields);

		$this->reportSaveFolderList($userId, $fields, $result);

		return $result;
	}

	/**
	 * One label per decision of the user and not per folder: a save that hides three folders at once
	 * is one press of "Save", and the sections it brought back to "All" ride inside that single label
	 * instead of counting themselves.
	 *
	 * A save that touches no visibility reports nothing at all. That is the whole of
	 * `im.v2.Folder.sort`, which reorders and decides nothing, so it has nothing to report - and the
	 * rule is read off the sets rather than off the caller, because the two requests are one
	 * operation and only the sets tell them apart.
	 */
	private function reportSaveFolderList(int $userId, SaveListFields $fields, SaveListResult $result): void
	{
		if (empty($fields->showFolderIds) && empty($fields->hideFolderIds))
		{
			return;
		}

		$analytics = new FolderAnalytics($userId);
		$errorCode = $result->getError()?->getCode();

		if (!empty($fields->hideFolderIds))
		{
			$analytics->addHideFolder($result->getReturnedSourceCodes(), $errorCode);
		}

		if (!empty($fields->showFolderIds))
		{
			$analytics->addShowFolder($errorCode);
		}
	}

	private function saveFolderListUnderLock(int $userId, SaveListFields $fields): SaveListResult
	{
		if ($userId <= 0)
		{
			return (new SaveListResult())->addError(new Error(FolderError::FOLDER_ACCESS_DENIED));
		}

		if (!$this->visibilityLock->acquire($userId))
		{
			return (new SaveListResult())->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		try
		{
			return $this->saveFolderListInternal($userId, $fields);
		}
		finally
		{
			$this->visibilityLock->release($userId);
		}
	}

	/**
	 * The save of the "Folder list" screen (ALG-02).
	 *
	 * The order is the set of the visible folders as the client knew it, and the match against the
	 * server set has to be exact: it is the only way a screen built before somebody else's change
	 * can be told apart from a valid one, and it earns a refusal code of its own. What comes back
	 * and what goes is said by the two sets and by nothing else - the presence of a folder in the
	 * order means a position and never a decision.
	 */
	private function saveFolderListInternal(int $userId, SaveListFields $fields): SaveListResult
	{
		$result = new SaveListResult();

		$showFolderIds = $fields->showFolderIds;
		$hideFolderIds = $fields->hideFolderIds;

		$folderRows = $this->fetchFolderRows($userId);
		$ownFolderIds = array_map(static fn (array $row): int => (int)$row['ID'], $folderRows);

		// The rows are read by owner, so belonging to them is the ownership check itself. The order
		// is not checked here: a foreign id in it is a bad request and keeps its own code below.
		if (
			!$this->isSubsetOf($showFolderIds, $ownFolderIds)
			|| !$this->isSubsetOf($hideFolderIds, $ownFolderIds)
		)
		{
			return $result->addError(new Error(FolderError::FOLDER_ACCESS_DENIED));
		}

		[$visibleFolderIds, $hiddenFolderIds, $hiddenByUserFolderIds] = $this->splitVisibleAndHiddenIds(
			$folderRows,
			$userId,
		);

		// A folder comes back only from the decision of its owner: the anchors the system holds are
		// not in the navigation to be returned to it, and returning one would give it a slot in the
		// order of a list it is not shown in.
		if (
			!empty($this->intersectIds($showFolderIds, $hideFolderIds))
			|| !$this->isSubsetOf($showFolderIds, $hiddenByUserFolderIds)
			|| !$this->isSubsetOf($hideFolderIds, $visibleFolderIds)
		)
		{
			return $result->addError(new Error(FolderError::FOLDER_SORT_INVALID));
		}

		$allFolder = null;
		$sourceCodesToReturn = [];

		if (!empty($hideFolderIds))
		{
			// One read of the list before the first write: it carries both the folders the invariant
			// is stated over and the folder its composition is stored on.
			$currentFolders = $this->folderProvider->getByUser($userId);
			$allFolder = $this->findAllFolder($currentFolders);
			$storedSourceCodes = $allFolder === null
				? []
				: $this->folderSourceService->getStoredSourceCodes($allFolder)
			;

			$hidingCheck = $this->resolveSourceCodesToReturn(
				$currentFolders,
				$hideFolderIds,
				$userId,
				$storedSourceCodes,
			);
			if (!$hidingCheck->isSuccess())
			{
				return $result->addErrors($hidingCheck->getErrors());
			}

			$sourceCodesToReturn = $hidingCheck->getResult() ?? [];
		}

		// A folder coming back with a position of its own leaves the anchors and takes the slot it
		// was dropped at; one coming back without a position stays an anchor and keeps its own slot.
		$positionedShowIds = $this->intersectIds($fields->folderIds, $showFolderIds);
		$anchorIds = $this->subtractIds($hiddenFolderIds, $positionedShowIds);
		$sequence = $this->dropHiddenFolderIds($fields->folderIds, $anchorIds);

		$requestValidation = $this->validateSortRequest(
			$this->subtractIds($sequence, $positionedShowIds),
			$visibleFolderIds,
		);
		if (!$requestValidation->isSuccess())
		{
			return $result->addErrors($requestValidation->getErrors());
		}

		// The folders leaving the navigation are still ordinary members of the sequence here: they
		// take their new slots with the rest and only become anchors once the state is written.
		$orderedFolderIds = $this->mergeOrder($folderRows, $anchorIds, $sequence);

		if (!empty($sourceCodesToReturn))
		{
			if ($allFolder === null)
			{
				return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
			}

			// The first write of the operation, one call for the whole package: the composition
			// service takes its lock once and merges the set as a whole, so a refusal here ends the
			// operation with the folders still visible and their order untouched. The folderUpdate
			// of the general list is sent by that service - the save adds no second push for it.
			$enableResult = $this->folderSourceService->enableSources($allFolder, $sourceCodesToReturn);
			if (!$enableResult->isSuccess())
			{
				return $result->addErrors($enableResult->getErrors());
			}
		}

		$this->persistVisibility($userId, $showFolderIds, $hideFolderIds);
		$this->persistSortOrder($userId, $orderedFolderIds);

		// Invalidate per-request memo so next getSortedFolderIds() re-reads.
		unset($this->sortedFolderIdsCache[$userId]);

		$this->folderCache->clearByUser($userId);

		// Read strictly after the cache was dropped - an earlier read would answer with the snapshot
		// from before the write. The answer and the events are built from this one collection.
		$folders = $this->folderProvider->getByUser($userId)->onlyAvailable($userId);

		$dialogIdMap = $this->sendFolderUpdates($folders, array_merge($showFolderIds, $hideFolderIds), $userId);

		// The event carries the canonical server order of every folder, not the client echo:
		// each client filters it for itself and drops the ids it does not know.
		(new FolderSort(array_values($orderedFolderIds), $userId))->send();

		return $result
			->setReturnedSourceCodes($sourceCodesToReturn)
			->setFolders($folders)
			->setDialogIdMap($dialogIdMap)
		;
	}

	protected function fetchFolderRows(int $userId): array
	{
		return FolderTable::query()
			->setSelect(['ID', 'TYPE', 'CODE', 'SORT', 'HIDDEN_BY_USER'])
			->where('USER_ID', $userId)
			->where('PARENT_CHAT_ID', 0)
			->setOrder(['SORT' => 'ASC', 'ID' => 'ASC'])
			->fetchAll()
		;
	}

	protected function isSystemCodeAvailable(string $code, int $userId): bool
	{
		$prototype = $this->systemFolderRegistry->findByCode($code);

		return $prototype !== null && $prototype->instantiate()->isAvailable($userId);
	}

	protected function isSystemCodeHiddenBySystem(string $code): bool
	{
		$prototype = $this->systemFolderRegistry->findByCode($code);

		return $prototype !== null && $prototype->instantiate()->isHiddenBySystem();
	}

	/**
	 * @return array{0: int[], 1: int[], 2: int[]} visible folder ids, hidden folder ids, and the
	 *         subset of the hidden ones the owner hid themselves - the only ones a client may ask
	 *         back into the navigation
	 */
	private function splitVisibleAndHiddenIds(array $folderRows, int $userId): array
	{
		$visibleFolderIds = [];
		$hiddenFolderIds = [];
		$hiddenByUserFolderIds = [];
		$allFolderAvailable = $this->isSystemCodeAvailable(AllFolder::CODE, $userId);

		foreach ($folderRows as $row)
		{
			$folderId = (int)$row['ID'];

			// Hidden anchor by the user's own decision, whatever the type of the folder: a personal
			// folder leaves the sortable set the same way a system one does. Without "All" the
			// decision stays in the row unpublished, and the row keeps its place in the set.
			if ($allFolderAvailable && ($row['HIDDEN_BY_USER'] ?? 'N') === 'Y')
			{
				$hiddenFolderIds[] = $folderId;
				$hiddenByUserFolderIds[] = $folderId;

				continue;
			}

			$type = (string)($row['TYPE'] ?? PersonalFolder::TYPE);

			if ($type === SystemFolder::TYPE)
			{
				$code = (string)($row['CODE'] ?? '');

				// Hidden anchor: unknown system code, folder unavailable to the user, or folder
				// the system keeps out of the tab panel (a member of the composite "All").
				if (!$this->isSystemCodeAvailable($code, $userId) || $this->isSystemCodeHiddenBySystem($code))
				{
					$hiddenFolderIds[] = $folderId;
					continue;
				}
			}

			$visibleFolderIds[] = $folderId;
		}

		return [$visibleFolderIds, $hiddenFolderIds, $hiddenByUserFolderIds];
	}

	/**
	 * The owner is part of the filter, not only of the id set: ownership of the requested ids is
	 * established one step earlier, and the write should not depend on how strict that step is.
	 *
	 * @param int[] $orderedFolderIds sort -> folder id
	 */
	protected function persistSortOrder(int $userId, array $orderedFolderIds): void
	{
		if (empty($orderedFolderIds))
		{
			return;
		}

		$idToSort = array_flip($orderedFolderIds);

		FolderTable::updateByFilter(
			['=ID' => array_keys($idToSort), '=USER_ID' => $userId],
			['SORT' => BulkUpdateExpression::caseById('ID', $idToSort)]
		);
	}

	/**
	 * The decision of the owner over the whole package, one statement per value: the column holds a
	 * flag and not a value of its own per row, so the CASE expression the order is written with buys
	 * nothing here. The owner is part of the filter for the reason {@see self::persistSortOrder}
	 * names.
	 *
	 * @param int[] $showFolderIds
	 * @param int[] $hideFolderIds
	 */
	protected function persistVisibility(int $userId, array $showFolderIds, array $hideFolderIds): void
	{
		foreach (['N' => $showFolderIds, 'Y' => $hideFolderIds] as $hiddenByUser => $folderIds)
		{
			if (empty($folderIds))
			{
				continue;
			}

			FolderTable::updateByFilter(
				['=ID' => array_values($folderIds), '=USER_ID' => $userId],
				['HIDDEN_BY_USER' => $hiddenByUser]
			);
		}
	}

	/**
	 * The anchor step of the order (ALG-02), a plain subtraction under the name of its meaning:
	 * an anchor carries no order and holds its own slot, so a client that sent its id anyway is
	 * simply taken at the part of its word that has one. Ids of somebody else's folders are not
	 * anchors and stay in for validation.
	 *
	 * @param int[] $requestedFolderIds
	 * @param int[] $anchorIds
	 * @return int[] list with contiguous keys, as mergeOrder() reads it by position
	 */
	private function dropHiddenFolderIds(array $requestedFolderIds, array $anchorIds): array
	{
		return $this->subtractIds($requestedFolderIds, $anchorIds);
	}

	private function validateSortRequest(array $requestedFolderIds, array $currentVisibleFolderIds): Result
	{
		$result = new Result();

		$currentVisibleFolderIdSet = array_flip($currentVisibleFolderIds);
		$seenFolderIds = [];

		foreach ($requestedFolderIds as $folderId)
		{
			$folderId = (int)$folderId;

			if (!isset($currentVisibleFolderIdSet[$folderId]))
			{
				return $result->addError(new Error(FolderError::FOLDER_SORT_INVALID));
			}

			if (isset($seenFolderIds[$folderId]))
			{
				return $result->addError(new Error(FolderError::FOLDER_SORT_INVALID));
			}

			$seenFolderIds[$folderId] = true;
		}

		// Every id is known and unique, yet the set does not match: the screen the order was built
		// on is out of date. Its own code tells the client to rebuild the list instead of retrying.
		if (count($requestedFolderIds) !== count($currentVisibleFolderIds))
		{
			return $result->addError(new Error(FolderError::FOLDER_SORT_STALE_SCREEN));
		}

		return $result;
	}

	/**
	 * Anchors keep the slots they hold; every other slot is filled from the sequence, in its order.
	 * The two cardinalities have to add up, and they do by the checks that ran before this point.
	 *
	 * @param int[] $anchorIds
	 * @param int[] $sequence
	 * @return array<int, int> sort -> folder id, renumbered from zero
	 */
	private function mergeOrder(array $folderRows, array $anchorIds, array $sequence): array
	{
		$anchorIdSet = array_flip($anchorIds);
		$sequenceIndex = 0;
		$orderedFolderIds = [];
		$sort = 0;

		foreach ($folderRows as $row)
		{
			$folderId = (int)$row['ID'];

			if (isset($anchorIdSet[$folderId]))
			{
				$orderedFolderIds[$sort] = $folderId;
			}
			else
			{
				$orderedFolderIds[$sort] = (int)$sequence[$sequenceIndex];
				$sequenceIndex++;
			}

			$sort++;
		}

		return $orderedFolderIds;
	}

	/**
	 * The permission to hide every folder of the package and the sources their sections need back,
	 * answered in one walk: both questions read the same stored composition, and asking them apart
	 * would read it twice.
	 *
	 * The package is refused as a whole - a save that hid the folders it could and kept the rest on
	 * screen would leave the client with a list it never asked for.
	 *
	 * @param int[] $hideFolderIds
	 * @param string[] $storedSourceCodes composition stored for the user's "All"
	 * @return Result<string[]> stored codes to switch back on, one per section however many folders
	 *         of that section the package hides
	 */
	private function resolveSourceCodesToReturn(
		FolderCollection $folders,
		array $hideFolderIds,
		int $userId,
		array $storedSourceCodes,
	): Result
	{
		$result = new Result();

		/** @var array<int, BaseFolder> $foldersById */
		$foldersById = [];
		foreach ($folders as $folder)
		{
			$folderId = $folder->getId();
			if ($folderId !== null && $folder instanceof BaseFolder)
			{
				$foldersById[$folderId] = $folder;
			}
		}

		$sourceCodesToReturn = [];
		foreach ($hideFolderIds as $folderId)
		{
			// A row the list does not hand back as a folder is refused rather than hidden: the
			// invariant cannot be answered for it, and hiding it would be a promise nobody checked.
			$folder = $foldersById[(int)$folderId] ?? null;
			if ($folder === null || !$this->visibilityResolver->canHide($folder, $userId))
			{
				return $result->addError(new Error(FolderError::FOLDER_HIDE_NOT_ALLOWED));
			}

			$sourceCode = $this->visibilityResolver->resolveSourceToReturn($folder, $userId, $storedSourceCodes);
			if ($sourceCode !== null)
			{
				$sourceCodesToReturn[$sourceCode] = $sourceCode;
			}
		}

		return $result->setResult(array_values($sourceCodesToReturn));
	}

	/**
	 * The "All" of the user, or null when the user has none - the composition the invariant is
	 * answered against is stored on that folder and on no other.
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

	/**
	 * One event per folder that changed visibility, taken from the collection the answer is built
	 * from: the object of the request predates the write and would push the state from before it.
	 * The dialog ids are resolved once for the whole collection and not once per pushed folder.
	 *
	 * @param int[] $changedFolderIds
	 * @return array<int, string>|null the resolution the events were built with, for the answer to
	 *         reuse; null when nothing was pushed or the collection had no chat to resolve
	 */
	private function sendFolderUpdates(FolderCollection $folders, array $changedFolderIds, int $userId): ?array
	{
		if (empty($changedFolderIds))
		{
			return null;
		}

		$restOption = ['dialogIdMap' => $folders->buildDialogIdMap()];
		$changedFolderIdSet = array_flip($changedFolderIds);

		foreach ($folders as $folder)
		{
			$folderId = $folder->getId();
			if ($folderId !== null && isset($changedFolderIdSet[$folderId]))
			{
				(new FolderUpdate($folder, $userId, $restOption))->send();
			}
		}

		return $restOption['dialogIdMap'];
	}

	/**
	 * @param int[] $folderIds
	 * @param int[] $supersetFolderIds
	 */
	private function isSubsetOf(array $folderIds, array $supersetFolderIds): bool
	{
		if (empty($folderIds))
		{
			return true;
		}

		$supersetFolderIdSet = array_flip($supersetFolderIds);

		foreach ($folderIds as $folderId)
		{
			if (!isset($supersetFolderIdSet[(int)$folderId]))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @param int[] $folderIds
	 * @param int[] $keptFolderIds
	 * @return int[] members of $folderIds that are also in $keptFolderIds, in the order of the first
	 */
	private function intersectIds(array $folderIds, array $keptFolderIds): array
	{
		if (empty($keptFolderIds))
		{
			return [];
		}

		$keptFolderIdSet = array_flip($keptFolderIds);
		$intersection = [];

		foreach ($folderIds as $folderId)
		{
			if (isset($keptFolderIdSet[(int)$folderId]))
			{
				$intersection[] = (int)$folderId;
			}
		}

		return $intersection;
	}

	/**
	 * @param int[] $folderIds
	 * @param int[] $removedFolderIds
	 * @return int[] list with contiguous keys, in the order of $folderIds
	 */
	private function subtractIds(array $folderIds, array $removedFolderIds): array
	{
		$removedFolderIdSet = array_flip($removedFolderIds);
		$remaining = [];

		foreach ($folderIds as $folderId)
		{
			if (!isset($removedFolderIdSet[(int)$folderId]))
			{
				$remaining[] = (int)$folderId;
			}
		}

		return $remaining;
	}
}
