<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Sort;

use Bitrix\Im\Model\FolderTable;
use Bitrix\Im\V2\Common\BulkUpdateExpression;
use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Folder\Cache\FolderCache;
use Bitrix\Im\V2\Folder\Error\FolderError;
use Bitrix\Im\V2\Folder\FolderBootstrapper;
use Bitrix\Im\V2\Folder\FolderProvider;
use Bitrix\Im\V2\Folder\PersonalFolder;
use Bitrix\Im\V2\Folder\Pull\FolderSort;
use Bitrix\Im\V2\Folder\System\SystemFolder;
use Bitrix\Im\V2\Folder\System\SystemFolderRegistry;
use Bitrix\Im\V2\Result;

class FolderSortService
{
	private array $sortedFolderIdsCache = [];

	public function __construct(
		private readonly FolderProvider $folderProvider,
		private readonly FolderBootstrapper $folderBootstrapper,
		private readonly FolderCache $folderCache,
		private readonly SystemFolderRegistry $systemFolderRegistry,
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
	 * and is not asked for. Not memoized on purpose - the answer must follow the order stored right
	 * now, and a second per-request cache would need a second invalidation point in saveSortOrder().
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

	public function saveSortOrder(int $userId, array $visibleFolderIds): Result
	{
		$result = new Result();

		if ($userId <= 0)
		{
			return $result->addError(new Error(FolderError::FOLDER_ACCESS_DENIED));
		}

		$requestedFolderIds = array_values($visibleFolderIds);
		$folderRows = $this->fetchFolderRows($userId);
		[$currentVisibleFolderIds, $hiddenFolderIds] = $this->splitVisibleAndHiddenIds($folderRows, $userId);

		// A client may send either the whole folder list or its visible part only: hidden
		// folders of this user carry no order, so their ids are dropped before validation.
		$requestedVisibleFolderIds = $this->dropHiddenFolderIds($requestedFolderIds, $hiddenFolderIds);

		$requestValidation = $this->validateSortRequest($requestedVisibleFolderIds, $currentVisibleFolderIds);
		if (!$requestValidation->isSuccess())
		{
			return $result->addErrors($requestValidation->getErrors());
		}

		// Hidden anchors keep their slots; visible slots are filled from the client order.
		$orderedFolderIds = $this->mergeOrder($folderRows, $hiddenFolderIds, $requestedVisibleFolderIds);

		$this->persistSortOrder($userId, $orderedFolderIds);

		// Invalidate per-request memo so next getSortedFolderIds() re-reads.
		unset($this->sortedFolderIdsCache[$userId]);

		$this->folderCache->clearByUser($userId);

		// The event carries the canonical server order of every folder, not the client echo:
		// each client filters it for itself and drops the ids it does not know.
		(new FolderSort(array_values($orderedFolderIds), $userId))->send();

		return $result;
	}

	protected function fetchFolderRows(int $userId): array
	{
		return FolderTable::query()
			->setSelect(['ID', 'TYPE', 'CODE', 'SORT'])
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

	protected function isSystemCodeVisible(string $code): bool
	{
		$prototype = $this->systemFolderRegistry->findByCode($code);

		return $prototype !== null && $prototype->instantiate()->isVisible();
	}

	/**
	 * @return array{0: int[], 1: int[]} visible folder ids, hidden folder ids
	 */
	private function splitVisibleAndHiddenIds(array $folderRows, int $userId): array
	{
		$visibleFolderIds = [];
		$hiddenFolderIds = [];

		foreach ($folderRows as $row)
		{
			$folderId = (int)$row['ID'];
			$type = (string)($row['TYPE'] ?? PersonalFolder::TYPE);

			if ($type === SystemFolder::TYPE)
			{
				$code = (string)($row['CODE'] ?? '');

				// Hidden anchor: unknown system code, folder unavailable to the user, or folder
				// the tab panel never shows on its own (a member of the composite "All").
				if (!$this->isSystemCodeAvailable($code, $userId) || !$this->isSystemCodeVisible($code))
				{
					$hiddenFolderIds[] = $folderId;
					continue;
				}
			}

			$visibleFolderIds[] = $folderId;
		}

		return [$visibleFolderIds, $hiddenFolderIds];
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
	 * Keeps the request tolerant to the client's idea of the folder list: ids of the user's own
	 * hidden folders are ignored, while ids of somebody else's folders stay for validation.
	 *
	 * @param int[] $requestedFolderIds
	 * @param int[] $hiddenFolderIds
	 * @return int[] list with contiguous keys, as mergeOrder() reads it by position
	 */
	private function dropHiddenFolderIds(array $requestedFolderIds, array $hiddenFolderIds): array
	{
		if (empty($hiddenFolderIds))
		{
			return $requestedFolderIds;
		}

		$hiddenFolderIdSet = array_flip($hiddenFolderIds);
		$keptFolderIds = [];

		foreach ($requestedFolderIds as $folderId)
		{
			if (!isset($hiddenFolderIdSet[(int)$folderId]))
			{
				$keptFolderIds[] = $folderId;
			}
		}

		return $keptFolderIds;
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

		if (count($requestedFolderIds) !== count($currentVisibleFolderIds))
		{
			return $result->addError(new Error(FolderError::FOLDER_SORT_INVALID));
		}

		return $result;
	}

	private function mergeOrder(array $folderRows, array $hiddenFolderIds, array $visibleFolderIds): array
	{
		$hiddenFolderIdSet = array_flip($hiddenFolderIds);
		$visibleIndex = 0;
		$orderedFolderIds = [];
		$sort = 0;

		foreach ($folderRows as $row)
		{
			$folderId = (int)$row['ID'];

			if (isset($hiddenFolderIdSet[$folderId]))
			{
				$orderedFolderIds[$sort] = $folderId;
			}
			else
			{
				$orderedFolderIds[$sort] = (int)$visibleFolderIds[$visibleIndex];
				$visibleIndex++;
			}

			$sort++;
		}

		return $orderedFolderIds;
	}
}
