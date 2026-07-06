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

	public function saveSortOrder(int $userId, array $visibleFolderIds): Result
	{
		$result = new Result();

		if ($userId <= 0)
		{
			return $result->addError(new Error(FolderError::FOLDER_ACCESS_DENIED));
		}

		$visibleFolderIds = array_values($visibleFolderIds);
		$folderRows = $this->fetchFolderRows($userId);
		[$currentVisibleFolderIds, $hiddenFolderIds] = $this->splitVisibleAndHiddenIds($folderRows, $userId);

		$requestValidation = $this->validateSortRequest($visibleFolderIds, $currentVisibleFolderIds);
		if (!$requestValidation->isSuccess())
		{
			return $result->addErrors($requestValidation->getErrors());
		}

		// Hidden anchors keep their slots; visible slots are filled from the client order.
		$orderedFolderIds = $this->mergeOrder($folderRows, $hiddenFolderIds, $visibleFolderIds);

		$this->persistSortOrder($orderedFolderIds);

		// Invalidate per-request memo so next getSortedFolderIds() re-reads.
		unset($this->sortedFolderIdsCache[$userId]);

		$this->folderCache->clearByUser($userId);

		(new FolderSort(array_values($visibleFolderIds), $userId))->send();

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

				// Unknown system code or unavailable system folder is treated as hidden.
				if (!$this->isSystemCodeAvailable($code, $userId))
				{
					$hiddenFolderIds[] = $folderId;
					continue;
				}
			}

			$visibleFolderIds[] = $folderId;
		}

		return [$visibleFolderIds, $hiddenFolderIds];
	}

	protected function persistSortOrder(array $orderedFolderIds): void
	{
		if (empty($orderedFolderIds))
		{
			return;
		}

		$idToSort = array_flip($orderedFolderIds);

		FolderTable::updateByFilter(
			['=ID' => array_keys($idToSort)],
			['SORT' => BulkUpdateExpression::caseById('ID', $idToSort)]
		);
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
