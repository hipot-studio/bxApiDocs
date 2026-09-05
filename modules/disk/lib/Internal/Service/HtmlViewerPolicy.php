<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\File;
use Bitrix\Disk\TypeFile;

/**
 * The single answer to "does the html viewer open this file", asked by everything on both sides of the
 * feature: what decides where a link leads and what serves the content. Keeping the whole condition here
 * is what makes those sides agree — a disagreement shows up as a link leading to the wrong page or as an
 * endpoint handing out a file the viewer would not open. Whether the file has a unified link at all is a
 * separate, type-based question answered by File::supportsUnifiedLink().
 */
final class HtmlViewerPolicy
{
	private const EXTENSIONS = ['html', 'htm'];

	public static function isViewable(File $file): bool
	{
		return Configuration::isEnabledHtmlViewer()
			&& self::isViewableFile($file)
		;
	}

	/**
	 * The file half of the answer, for a caller that reports a disabled viewer on its own. The name alone
	 * does not answer it: an html file is stored as TypeFile::KNOWN, and the stored type is computed once
	 * on upload and not recomputed on rename — so a document renamed to .html keeps TypeFile::DOCUMENT
	 * and stays with the editors instead of being served as text/html.
	 */
	public static function isViewableFile(File $file): bool
	{
		return self::isViewableExtension($file->getExtension())
			&& (int)$file->getTypeFile() === TypeFile::KNOWN
		;
	}

	/**
	 * The extension half of the answer, for a caller holding a name but no file (a version carries its
	 * own). It says nothing about the viewer being enabled — that stays with isViewable().
	 */
	public static function isViewableExtension(?string $extension): bool
	{
		return in_array(mb_strtolower((string)$extension), self::EXTENSIONS, true);
	}
}
