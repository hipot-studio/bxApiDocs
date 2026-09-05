<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Service\UnifiedLink;

use Bitrix\Disk\Configuration;

enum UnifiedLinkPrefix: string
{
	case Picture = '/picture/';
	case Media = '/media/';
	case Doc = '/doc/';
	case Sheet = '/sheet/';
	case Pres = '/pres/';
	case Audio = '/audio/';
	case Board = '/board/';
	case Html = '/html/';
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
			FileExtensionMap::Html => self::forHtml(),
			default => self::Default,
		};
	}

	/**
	 * The routes for these prefixes live in the intranet module, and /html/ is the newest of them: a
	 * portal that updated disk first has no such route yet and would answer 404 to every html link.
	 * So the prefix follows the option the viewer is rolled out with, and while the viewer is off the
	 * link stays on /file/, exactly where it was before the viewer existed.
	 */
	private static function forHtml(): static
	{
		return Configuration::isEnabledHtmlViewer() ? self::Html : self::Default;
	}
}
