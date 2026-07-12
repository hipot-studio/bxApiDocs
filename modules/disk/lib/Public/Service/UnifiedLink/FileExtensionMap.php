<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Service\UnifiedLink;

use Bitrix\Disk\Analytics\Enum\DocumentTypeEnum;

enum FileExtensionMap: string
{
	case Image = 'image';
	case Video = 'video';
	case Doc = 'doc';
	case Sheet = 'sheet';
	case Pres = 'pres';
	case Audio = 'audio';
	case VectorImage = 'vectorImage';
	case Board = 'board';

	public static function getByExtension(string $extension): ?FileExtensionMap
	{
		$extension = strtolower($extension);
		$doc = DocumentTypeEnum::getByExtension($extension);

		return match ($doc) {
			DocumentTypeEnum::Doc => self::Doc,
			DocumentTypeEnum::Sheet => self::Sheet,
			DocumentTypeEnum::Pres => self::Pres,
			null => match ($extension) {
				'gif', 'jpg', 'jpeg', 'bmp', 'png', 'webp', 'tif', 'tiff', 'psd' => self::Image,
				'mp4', 'mp4v', 'mpg4', 'webm', 'ogv', '3gp', 'mov', 'flv', 'avi', 'mkv', 'm4v', 'h264', 'wmv' => self::Video,
				'mp3', 'wav', 'ogg' => self::Audio,
				'svg', 'svgz', 'cdr', 'swf', 'eps', 'ps', 'ai', 'sketch' => self::VectorImage,
				'board', 'flp' => self::Board,
				'pdf' => self::Doc,
				default => null,
			},
		};
	}
}
