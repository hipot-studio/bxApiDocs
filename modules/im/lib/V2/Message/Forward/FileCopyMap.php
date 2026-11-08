<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Forward;

use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Entity\File\FileItem;

class FileCopyMap
{
	private array $fileMap = [];
	private array $oldFileIds = [];
	private FileCollection $fileCollection;

	public function __construct()
	{
		$this->fileCollection = new FileCollection();
	}

	public function addFileIdMapping(FileItem $copyFile, FileItem $oldFile): self
	{
		$copyFileId = $copyFile->getId();
		$oldFileId = $oldFile->getId();

		$this->fileMap[$copyFileId] = $oldFileId;
		$this->oldFileIds[$oldFileId] = $oldFileId;
		$this->fileCollection->offsetSet($copyFileId, $copyFile);
		$this->fileCollection->offsetSet($oldFileId ,$oldFile);

		return $this;
	}

	/**
	 * A copy taken back from the target chat is a copy of nothing any more. The source file stays in the
	 * map: it is shared with the rest of the batch, and its params are read by the file id alone, while
	 * the copies to write them onto are taken from the mapping.
	 */
	public function removeFileIdMapping(FileItem $copyFile): self
	{
		$copyFileId = $copyFile->getId();

		unset($this->fileMap[$copyFileId]);
		$this->fileCollection->offsetUnset($copyFileId);

		return $this;
	}

	public function getFileMap(): array
	{
		return $this->fileMap;
	}

	public function getOldFileIds(): array
	{
		return $this->oldFileIds;
	}

	public function getFiles(): FileCollection
	{
		return $this->fileCollection;
	}
}
