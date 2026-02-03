<?php

declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess\FileInfo;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\QuickAccess\Storage\ScopeStorage;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Uri;

class DiskProvider extends BaseProvider
{
	private int $id;
	private string $name;

	private AttachedObject|BaseObject $object;

	/**
	 * @param BaseObject $file
	 */
	protected function __construct(mixed $file)
	{
		$this->id = (int)$file->getId();
		$this->name = $file->getName();
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

	public function getId(): int
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * Extract file information for quick access
	 *
	 * @return array|null File information or null if extraction failed
	 */
	public function getFileInfo(): ?FileInfoDto
	{
		$fileObject = File::loadById($this->id);
		$fileData = $fileObject->getFile();
		if (
			!is_array($fileData)
			|| empty($fileData)
		)
		{
			return null;
		}

		if (!$this->isMediaFile($fileObject, $fileData))
		{
			return null;
		}

		$previewFileData = [];
		if (TypeFile::isVideo($fileObject))
		{
			$previewFileData = $fileObject->getView()->getPreviewData();
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
	 * @param File $fileObject
	 * @param array $fileData File data
	 * @return bool True if the object is an image or media file, false otherwise
	 */
	private function isMediaFile(File $fileObject, array $fileData): bool
	{
		if (TypeFile::isVideo($fileObject))
		{
			return true;
		}

		if (!TypeFile::isImage($fileObject))
		{
			return false;
		}

		return \CFile::IsImage($fileObject->getName(), $fileData['CONTENT_TYPE']);
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