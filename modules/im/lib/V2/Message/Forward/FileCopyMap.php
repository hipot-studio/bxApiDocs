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
