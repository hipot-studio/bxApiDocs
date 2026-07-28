<?php

declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess\FileInfo;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\NotImplementedException;
use RuntimeException;

class DiskProvider extends BaseProvider
{
	private ?int $bFileId = null;
	private File $diskFile;

	/**
	 * @param File $file
	 */
	protected function __construct(mixed $file)
	{
		$this->diskFile = $file;
	}

	public static function create(mixed $file): ?static
	{
		if (
			$file instanceof AttachedObject
			|| $file instanceof BaseObject
		)
		{
			$fileObject = self::extractFileObject($file);
			if (!$fileObject instanceof File)
			{
				return null;
			}

			return new static($fileObject);
		}

		return null;
	}

	public function getBFileId(): int
	{
		if ($this->bFileId === null)
		{
			$fileData = $this->diskFile->getFile();
			if ($fileData === null)
			{
				throw new RuntimeException('Failed to get file data for disk file with id ' . $this->diskFile->getId());
			}

			$this->bFileId = (int)$fileData['ID'];
		}

		return $this->bFileId;
	}

	public function getFileName(): string
	{
		return $this->diskFile->getName();
	}

	/**
	 * Extract file information for quick access
	 *
	 * @return array|null File information or null if extraction failed
	 * @throws NotImplementedException
	 */
	public function getFileInfo(): ?FileInfoDto
	{
		$fileData = $this->diskFile->getFile();
		if (
			!is_array($fileData)
			|| empty($fileData)
		)
		{
			return null;
		}

		if (!$this->isMediaFile($fileData))
		{
			return null;
		}

		$previewFileData = [];
		if (TypeFile::isVideo($this->diskFile))
		{
			$previewFileData = $this->diskFile->getView()->getPreviewData();
		}

		$fileInfo = $this->getInfoForAccelRedirect($fileData);
		if ($fileInfo->id <= 0)
		{
			return null;
		}

		if (!empty($previewFileData) && is_array($previewFileData) && isset($previewFileData['ID']))
		{
			$fileInfo->preview = $this->getInfoForAccelRedirect($previewFileData);
		}

		return $fileInfo;
	}

	public function getSourceId(): string
	{
		return 'DiskFile:' . $this->diskFile->getId();
	}

	private static function extractFileObject(AttachedObject|BaseObject $object): ?BaseObject
	{
		if ($object instanceof AttachedObject)
		{
			if ($object->isSpecificVersion())
			{
				return $object->getVersion()?->getObject();
			}

			return $object->getFile();
		}

		return $object;
	}

	/**
	 * Check if the object is an image or media file like video/audio
	 *
	 * @param array $fileData File data
	 * @return bool True if the object is an image or media file, false otherwise
	 * @throws NotImplementedException
	 */
	private function isMediaFile(array $fileData): bool
	{
		if (TypeFile::isVideo($this->diskFile))
		{
			return true;
		}

		if (TypeFile::isAudio($this->diskFile))
		{
			return true;
		}

		if (!TypeFile::isImage($this->diskFile))
		{
			return false;
		}

		return \CFile::IsImage($this->diskFile->getName(), $fileData['CONTENT_TYPE']);
	}

	/**
	 * Get information for X-Accel-Redirect
	 *
	 * @param array $fileData File data
	 * @return FileInfoDto Information for redirect or null if failed
	 */
	private function getInfoForAccelRedirect(array $fileData): FileInfoDto
	{
		return self::createFileInfo($fileData);
	}
}
