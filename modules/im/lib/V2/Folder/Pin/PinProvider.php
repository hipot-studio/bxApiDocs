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
