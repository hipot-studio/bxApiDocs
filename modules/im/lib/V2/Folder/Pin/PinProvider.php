<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Pin;

use Bitrix\Im\Model\FolderPinTable;
use Bitrix\Im\V2\Folder\Folder;

class PinProvider
{
	public function __construct(
		private readonly PinCache $pinCache,
	)
	{
	}

	/**
	 * Pin set of the folder as the table holds it, with no regard for the composition of the folder.
	 * This is the set a caller asking "is this chat already pinned here" needs: a row may legitimately
	 * sit outside the visible composition ({@see PinService::pinChatPerFolder()}), and it still has to
	 * be found - by the repeated pin, so it stays silent, and by the unpin, so it stays removable.
	 * The limit of a per-folder write counts this same set.
	 *
	 * The set belongs to the owner of the folder ($folder->getUserId()) and not to the caller, so the
	 * folder is expected to be access-checked already: a foreign folder here reads foreign rows.
	 */
	public function getByFolder(Folder $folder): FolderPinState
	{
		$userId = (int)($folder->getUserId() ?? 0);
		$folderId = (int)($folder->getId() ?? 0);
		$parentId = $folder->getParentId();

		if ($userId <= 0 || $folderId <= 0)
		{
			return new FolderPinState();
		}

		$scope = $this->pinCache->getByScope($userId, $parentId);

		if ($scope === null)
		{
			$scope = $this->loadScopeFromDb($userId, $parentId);
			$this->pinCache->setByScope($userId, $parentId, $scope);
		}

		return new FolderPinState($scope->getPinSortMap($folderId));
	}

	private function loadScopeFromDb(int $userId, int $parentId): PinScopeState
	{
		$rows = FolderPinTable::query()
			->setSelect(['FOLDER_ID', 'CHAT_ID', 'PIN_SORT'])
			->where('USER_ID', $userId)
			->where('FOLDER_PARENT_ID', $parentId)
			->fetchAll()
		;

		$pinSortMapByFolder = [];

		foreach ($rows as $row)
		{
			$folderId = (int)$row['FOLDER_ID'];
			$chatId = (int)$row['CHAT_ID'];
			$pinSortMapByFolder[$folderId][$chatId] = (int)($row['PIN_SORT'] ?? 0);
		}

		return new PinScopeState($pinSortMapByFolder);
	}
}
