<?php

declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess\FileInfo;

use Bitrix\Main\Web\MimeType;

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

	public function getBFileId(): int
	{
		return $this->fileId;
	}

	public function getFileName(): string
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

	public function getSourceId(): string
	{
		return 'BFile:' . $this->fileId;
	}

	private function isMediaFile(): bool
	{
		return $this->isImage() || $this->isVideo() || $this->isAudio();
	}

	private function isImage(): bool
	{
		return \CFile::IsImage($this->getFileName(), $this->getFileData()['CONTENT_TYPE']);
	}

	private function isVideo(): bool
	{
		return $this->hasMimeCategory('video');
	}

	private function isAudio(): bool
	{
		return $this->hasMimeCategory('audio');
	}

	private function hasMimeCategory(string $category): bool
	{
		$fileData = $this->getFileData();
		$contentType = MimeType::normalize((string)($fileData['CONTENT_TYPE'] ?? ''));

		if (str_starts_with($contentType, $category . '/'))
		{
			return true;
		}

		$mimeByFilename = MimeType::getByFilename($this->getFileName());

		return str_starts_with($mimeByFilename, $category . '/');
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