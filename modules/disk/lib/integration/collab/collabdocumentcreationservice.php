<?php
declare(strict_types=1);

namespace Bitrix\Disk\Integration\Collab;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Folder;
use Bitrix\Main\DI\ServiceLocator;

class CollabDocumentCreationService
{
	private CollabService $collabService;

	public function __construct(?CollabService $collabService = null)
	{
		$this->collabService = $collabService ?? ServiceLocator::getInstance()->get(CollabService::class);
	}

	/**
	 * Returns collab's "Created files" folder in case:
	 * 1) collabId is valid.
	 * 2) This collab has a storage with the "Created files" folder.
	 * 3) User is a member of this collab.
	 *
	 * Otherwise, returns null.
	 *
	 * @param int $collabId Collab ID.
	 * @param int $userId User ID.
	 * @return ?Folder Folder, or null when it cannot be resolved.
	 */
	public function getCreatedFilesFolderByCollabId(int $collabId, int $userId): ?Folder
	{
		$collabStorage = Driver::getInstance()->getStorageByGroupId($collabId);
		if (!$collabStorage)
		{
			return null;
		}

		if (!$this->collabService->isCollabStorage($collabStorage))
		{
			return null;
		}

		$collab = $this->collabService->getCollabByStorage($collabStorage);
		if ($collab === null || !in_array($userId, $collab->getUserMemberIds(), true))
		{
			return null;
		}

		return $this->getFolderByFolderId($collabStorage->getFolderForCreatedFiles()?->getId());
	}

	/**
	 * @param int $userId
	 * @return bool
	 */
	public function isCollaberUserById(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}
		return $this->collabService->isCollaberUserById($userId);
	}

	/**
	 * @param mixed $folderId
	 * @return Folder|null
	 */
	public function getFolderByFolderId(mixed $folderId): ?Folder
	{
		if (is_numeric($folderId) && (int)$folderId > 0)
		{
			return Folder::getById((int)$folderId)?->getRealObject();
		}
		return null;
	}

	/**
	 * Returns true if folder is in collab storage, false otherwise.
	 *
	 * @param ?Folder $folder Folder in which, file should be saved.
	 * @return bool
	 */
	public function isFolderInCollabStorage(?Folder $folder): bool
	{
		if ($folder === null)
		{
			return false;
		}

		$storage = $folder->getStorage();
		if ($storage === null)
		{
			return false;
		}

		return $this->collabService->isCollabStorage($storage);
	}
}
