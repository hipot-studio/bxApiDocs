<?php

declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess\FileInfo;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\QuickAccess\Storage\ScopeStorage;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\MimeType;
use Bitrix\Main\Web\Uri;

/**
 * Get file info from b_file by file id
 */
class MainProvider extends BaseProvider
{
	private int $fileId;
	private array $fileData;

	/**
	 * @param int $file - id of file in b_file
	 */
	protected function __construct(mixed $file)
	{
		$this->fileId = $file;
	}

	public static function create(mixed $file): ?static
	{
		if (
			is_int($file)
			&& \CFile::GetByID($file)->Fetch()
		)
		{
			return new static($file);
		}

		return null;
	}

	public function getId(): int
	{
		return $this->fileId;
	}

	public function getName(): string
	{
		return $this->getFileData()['ORIGINAL_NAME'];
	}

	public function getFileInfo(): ?FileInfoDto
	{
		if (!$this->isMediaFile())
		{
			return null;
		}

		$fileData = $this->getFileData();

		return self::createFileInfo($fileData);
	}

	private function isMediaFile(): bool
	{
		return $this->isImage() || $this->isVideo();
	}

	private function isImage(): bool
	{
		return \CFile::IsImage($this->getName(), $this->getFileData()['CONTENT_TYPE']);
	}

	private function isVideo(): bool
	{
		$mime = MimeType::getByFilename($this->getName());

		return str_contains($mime, 'video/');
	}

	private function getFileData(): array
	{
		if (!isset($this->fileData))
		{
			$this->fileData = \CFile::GetFileArray($this->fileId);
		}

		return $this->fileData;
	}
}