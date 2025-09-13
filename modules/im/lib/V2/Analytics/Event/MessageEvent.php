<?php

namespace Bitrix\Im\V2\Analytics\Event;

use Bitrix\Disk\TypeFile;
use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Main\Loader;

class MessageEvent extends ChatEvent
{
	protected const MULTI_TYPE = 'any';
	protected const MEDIA_TYPE = 'media';
	protected const UNKNOWN_TYPE = 'unknown';

	public function setFilesType(FileCollection $files): self
	{
		if (!Loader::includeModule('disk'))
		{
			return $this;
		}

		$fileMap = [];
		foreach ($files as $file)
		{
			$typeId = $file->getDiskFile()?->getTypeFile() ?? 0;
			$fileMap[(int)$typeId] = (int)$typeId;
		}

		$typeCount = count($fileMap);

		if (
			$typeCount === 2
			&& isset($fileMap[TypeFile::IMAGE])
			&& isset($fileMap[TypeFile::VIDEO])
		)
		{
			$this->type = self::MEDIA_TYPE;

			return $this;
		}

		if ($typeCount > 1)
		{
			$this->type = self::MULTI_TYPE;

			return $this;
		}

		$diskFileType = array_shift($fileMap);

		$this->type = $this->getFileType($diskFileType) ?? self::UNKNOWN_TYPE;

		return $this;
	}

	public function setFileP3(int $count): self
	{
		$this->p3 = 'filesCount_' . $count;

		return $this;
	}

	private function getFileType(string $diskFileType): ?string
	{
		if (!Loader::includeModule('disk'))
		{
			return self::UNKNOWN_TYPE;
		}

		$diskTypeToAnalyticTypeMap = [
			TypeFile::IMAGE => 'image',
			TypeFile::VIDEO => 'video',
			TypeFile::DOCUMENT => 'document',
			TypeFile::ARCHIVE => 'archive',
			TypeFile::SCRIPT => 'script',
			TypeFile::UNKNOWN => 'unknown',
			TypeFile::PDF => 'pdf',
			TypeFile::AUDIO => 'audio',
			TypeFile::KNOWN => 'known',
			TypeFile::VECTOR_IMAGE => 'vector-image',
		];

		return $diskTypeToAnalyticTypeMap[$diskFileType] ?? null;
	}
}
