<?php

namespace Bitrix\DiskMobile\Controller;

use Bitrix\Disk\Document\Flipchart\BoardService;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\User;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;

class Document extends Base
{
	public function createBoardAction(CurrentUser $currentUser, int $folderId = 0): ?array
	{
		if ($folderId > 0)
		{
			$folder = Folder::loadById($folderId);
			if (!$folder)
			{
				$this->addError(new Error('Folder not found.', 'FOLDER_NOT_FOUND'));

				return null;
			}

			$storage = $folder->getStorage();
			if (!$storage)
			{
				$this->addError(new Error('Could not find storage for folder.', 'STORAGE_NOT_FOUND'));

				return null;
			}

			$securityContext = $storage->getSecurityContext((int)$currentUser->getId());
			if (!$folder->canAdd($securityContext))
			{
				$this->addError(new Error('Could not create board. Access denied.', 'ACCESS_DENIED'));

				return null;
			}
		}
		else
		{
			$userStorage = Driver::getInstance()->getStorageByUserId((int)$currentUser->getId());
			if (!$userStorage)
			{
				$this->addError(new Error('Personal storage not found.', 'STORAGE_NOT_FOUND'));

				return null;
			}

			$folder = $userStorage->getFolderForCreatedFiles();
		}

		$res = BoardService::createNewDocument(User::loadById($currentUser->getId()), $folder);

		if (!$res->isSuccess())
		{
			$this->addError($res->getError());

			return null;
		}

		/** @var File $file */
		$file = $res->getData()['file'] ?? null;

		if (!$file instanceof File)
		{
			$this->addError(new Error('Board document was not created.', 'BOARD_CREATE_FAILED'));

			return null;
		}

		return [
			'id' => $file->getId(),
			'name' => $file->getName(),
		];
	}
}
