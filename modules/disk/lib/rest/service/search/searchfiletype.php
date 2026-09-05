<?php

declare(strict_types=1);

namespace Bitrix\Disk\Rest\Service\Search;

use Bitrix\Disk\TypeFile;

enum SearchFileType: string
{
	case Document = 'document';
	case Image = 'image';
	case Video = 'video';
	case Audio = 'audio';
	case Archive = 'archive';
	case Pdf = 'pdf';
	case VectorImage = 'vector_image';
	case Board = 'board';
	case Known = 'known';
	case Unknown = 'unknown';

	public function getTypeFileValue(): int
	{
		return match ($this)
		{
			self::Document => TypeFile::DOCUMENT,
			self::Image => TypeFile::IMAGE,
			self::Video => TypeFile::VIDEO,
			self::Audio => TypeFile::AUDIO,
			self::Archive => TypeFile::ARCHIVE,
			self::Pdf => TypeFile::PDF,
			self::VectorImage => TypeFile::VECTOR_IMAGE,
			self::Board => TypeFile::BOARD,
			self::Known => TypeFile::KNOWN,
			self::Unknown => TypeFile::UNKNOWN,
		};
	}
}
