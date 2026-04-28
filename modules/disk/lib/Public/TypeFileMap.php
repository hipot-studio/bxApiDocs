<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public;

use Bitrix\Disk\TypeFile;

enum TypeFileMap: string
{
	case IMAGE = 'image';
	case VIDEO = 'video';
	case DOCUMENT = 'document';
	case ARCHIVE = 'archive';
	case SCRIPT = 'script';
	case UNKNOWN = 'unknown';
	case PDF = 'pdf';
	case AUDIO = 'audio';
	case KNOWN = 'known';
	case VECTOR_IMAGE = 'vector';
	case BOARD = 'board';

	public static function fromTypeFileConstant(int $typeFile): ?self
	{
		return match ($typeFile) {
			TypeFile::IMAGE => self::IMAGE,
			TypeFile::VIDEO => self::VIDEO,
			TypeFile::DOCUMENT => self::DOCUMENT,
			TypeFile::ARCHIVE => self::ARCHIVE,
			TypeFile::SCRIPT => self::SCRIPT,
			TypeFile::UNKNOWN => self::UNKNOWN,
			TypeFile::PDF => self::PDF,
			TypeFile::AUDIO => self::AUDIO,
			TypeFile::KNOWN => self::KNOWN,
			TypeFile::VECTOR_IMAGE => self::VECTOR_IMAGE,
			TypeFile::FLIPCHART => self::BOARD,
			default => null,
		};
	}
}
