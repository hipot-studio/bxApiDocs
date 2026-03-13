<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\UI\Sticker;

use Bitrix\Im\V2\Controller\Sticker\StickerUploader;
use Bitrix\Main\Loader;
use Bitrix\UI\FileUploader\Uploader;

Loader::requireModule('ui');

class PendingFileCollection
{
	protected \Bitrix\UI\FileUploader\PendingFileCollection $pendingFileCollection;
	protected array $fileMap;

	public function __construct(array $uuids = [])
	{
		$this->pendingFileCollection = (new Uploader(new StickerUploader()))->getPendingFiles($uuids);
	}

	protected function getPendingFiles(): \Bitrix\UI\FileUploader\PendingFileCollection
	{
		return $this->pendingFileCollection;
	}

	public function getFileMap(): array
	{
		if (isset($this->fileMap))
		{
			return $this->fileMap;
		}

		$result = [];
		foreach ($this->getPendingFiles() as $file)
		{
			if ($file->getFileId() !== null)
			{
				$result[$file->getFileId()] = $file->getGuid();
			}
		}

		$this->fileMap = $result;

		return $this->fileMap;
	}

	public function makePersistent(array $stickers): void
	{
		$pendingFiles = $this->getPendingFiles();

		foreach ($stickers as $sticker)
		{
			$fileId = (int)$sticker['FILE_ID'];
			$pendingFiles->getByFileId($fileId)?->makePersistent();
		}
	}
}
