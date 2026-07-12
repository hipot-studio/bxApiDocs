<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Service\UnifiedLink;

enum UnifiedLinkPrefix: string
{
	case Picture = '/picture/';
	case Media = '/media/';
	case Doc = '/doc/';
	case Sheet = '/sheet/';
	case Pres = '/pres/';
	case Audio = '/audio/';
	case Board = '/board/';
	case Default = '/file/';

	public static function getByFileExtensionMap(?FileExtensionMap $map): static
	{
		return match ($map) {
			FileExtensionMap::Image, FileExtensionMap::VectorImage => self::Picture,
			FileExtensionMap::Video => self::Media,
			FileExtensionMap::Board => self::Board,
			FileExtensionMap::Sheet => self::Sheet,
			FileExtensionMap::Doc => self::Doc,
			FileExtensionMap::Pres => self::Pres,
			FileExtensionMap::Audio => self::Audio,
			default => self::Default,
		};
	}
}
