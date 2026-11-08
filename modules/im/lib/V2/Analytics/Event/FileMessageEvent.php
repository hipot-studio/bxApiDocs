<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics\Event;

use Bitrix\Disk\TypeFile;
use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Main\Loader;

class FileMessageEvent extends ChatEvent
{
	protected const MULTI_TYPE = 'any';
	protected const MEDIA_TYPE = 'media';
	protected const UNKNOWN_TYPE = 'unknown';

	/**
	 * Resolves a single analytics type string for a file collection.
	 *
	 * Extracted as a public static helper so that CopilotAnalytics (tool=ai) can reuse the
	 * same classification logic without instantiating a FileMessageEvent (private logic made
	 * accessible with minimal surface change).
	 *
	 * Returns the same value that setFilesType() would place in $this->type, except when
	 * the disk module is unavailable: then it returns 'unknown', while setFilesType()
	 * leaves the type unset (legacy contract) — callers preserving that contract must
	 * gate on disk availability themselves.
	 */
	public static function resolveFileType(FileCollection $files): string
	{
		if (!Loader::includeModule('disk'))
		{
			return self::UNKNOWN_TYPE;
		}

		$fileMap = [];
		foreach ($files as $file)
		{
			$typeId = $file->getDiskFile()?->getTypeFile() ?? 0;
			$fileMap[$typeId] = (int)$typeId;
		}

		$typeCount = count($fileMap);

		if (
			$typeCount === 2
			&& isset($fileMap[TypeFile::IMAGE])
			&& isset($fileMap[TypeFile::VIDEO])
		)
		{
			return self::MEDIA_TYPE;
		}

		if ($typeCount > 1)
		{
			return self::MULTI_TYPE;
		}

		if ($typeCount === 0)
		{
			return self::UNKNOWN_TYPE;
		}

		$diskFileType = array_shift($fileMap);

		return self::resolveFileTypeById((int)$diskFileType, $files) ?? self::UNKNOWN_TYPE;
	}

	public function setFilesType(FileCollection $files): self
	{
		if (!Loader::includeModule('disk'))
		{
			return $this;
		}

		$this->type = self::resolveFileType($files);

		return $this;
	}

	public function setFileP3(int $count): self
	{
		$this->p3 = 'filesCount_' . $count;

		return $this;
	}

	/**
	 * Maps a single Disk TypeFile id + FileCollection to an analytics type string.
	 *
	 * Extracted as a private static so that the public resolveFileType() can call it
	 * without an instance.
	 */
	private static function resolveFileTypeById(int $diskFileType, FileCollection $files): ?string
	{
		// Module 'disk' availability is already guaranteed by the sole caller resolveFileType().
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

		$type = $diskTypeToAnalyticTypeMap[$diskFileType] ?? null;

		if ($type === 'audio' && self::isVoiceStatic($files))
		{
			$type = 'voice';
		}

		if ($type === 'video' && self::isVideoCircleStatic($files))
		{
			$type = 'videoCircle';
		}

		return $type;
	}

	private static function isVoiceStatic(FileCollection $files): bool
	{
		if (count($files) !== 1)
		{
			return false;
		}

		$file = $files->getIterator()->current();

		return $file instanceof FileItem && $file->isVoiceNote();
	}

	private static function isVideoCircleStatic(FileCollection $files): bool
	{
		if (count($files) !== 1)
		{
			return false;
		}

		$file = $files->getIterator()->current();

		return $file instanceof FileItem && $file->isVideoNote();
	}
}
