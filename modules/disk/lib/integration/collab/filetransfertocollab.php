<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\Collab;

use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Storage;
use Bitrix\Disk\SystemUser;
use Bitrix\Main\Localization\Loc;

class FileTransferToCollab
{
	private ?Storage $collabStorage;
	private File $file;
	private int $actorId;

	/**
	 * @param int|null $actorId User attaching the file, whose format policy judges the transfer; the author of the file by default.
	 */
	public function __construct(int $entityId, string $entityType, File $file, ?int $actorId = null)
	{
		$this->collabStorage = (new CollabService())->getCollabStorageByEntity($entityId, $entityType);
		$this->file = $file;
		$this->actorId = $actorId ?? (int)$file->getCreatedBy();
	}

	public function isNeedTransfer(): bool
	{
		return $this->collabStorage
			&& (int)$this->collabStorage->getId() !== (int)$this->file->getStorageId()
		;
	}

	public function transferToFolderForUploadedFilesInCollab(): void
	{
		if ($this->transferToSpecificFolderInCollab(Folder::CODE_FOR_UPLOADED_FILES))
		{
			$this->cleanUpPersonalSpecificFolder(Folder::CODE_FOR_UPLOADED_FILES);
		}
	}

	public function transferToFolderForCreatedFilesInCollab(): void
	{
		if ($this->transferToSpecificFolderInCollab(Folder::CODE_FOR_CREATED_FILES))
		{
			$this->cleanUpPersonalSpecificFolder(Folder::CODE_FOR_CREATED_FILES);
		}
	}

	public function copyToFolderForUploadedFilesInCollab(): ?File
	{
		$collabFile = $this->copyToCollab($this->collabStorage?->getFolderForUploadedFiles());

		if ($collabFile)
		{
			$newName = Loc::getMessage('DISK_FILE_TRANSFER_TO_COLLAB_NEW_FILE_IN_COLLAB', [
				'#NAME#' => $this->file->getName()
			]);
			$collabFile->rename($newName, true);
		}

		return $collabFile;
	}

	/**
	 * A file attached on behalf of no user keeps its author in the collab, and the global rule judges the copy.
	 */
	private function copyToCollab(?Folder $folder): ?File
	{
		$realFile = $this->file->getRealObject();
		if ($realFile === null || $folder === null)
		{
			return null;
		}

		if ($this->actorId > 0)
		{
			return $realFile->copyTo($folder, $this->actorId, true);
		}

		return $realFile->copyToOnBehalfOfNoUser($folder, (int)$this->file->getCreatedBy(), true);
	}

	private function transferToSpecificFolderInCollab(string $code): bool
	{
		$folder = $this->collabStorage?->getSpecificFolderByCode($code);

		if ($folder === null)
		{
			return false;
		}

		return !empty($this->file->moveToAnotherFolder($folder, $this->actorId, true));
	}

	private function cleanUpPersonalSpecificFolder(string $code): void
	{
		$originalStorage = $this->file->getStorage();
		if ($originalStorage !== null)
		{
			$specificFolder = $originalStorage->getSpecificFolderByCode($code);

			if ($specificFolder && !$specificFolder->hasChildren())
			{
				$specificFolder->deleteTree(SystemUser::SYSTEM_USER_ID);
			}
		}
	}
}
