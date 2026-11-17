<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\File;
use Bitrix\Disk\Public\Features\MarkdownTab;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Version;
use Bitrix\Main\Config\Feature;

/**
 * The single answer to "does a Markdown file open on a page of its own", asked by what decides where a
 * link leads and by what marks the file on the surfaces of Disk: keeping the whole condition here is
 * what keeps a link and the page behind it in agreement.
 *
 * The size of the file is not part of the answer: an oversized file opens the page all the same, and the
 * page tells the reader the document is too large to show. Whether the file has a unified link at all and
 * whether it is opened as an attachment are questions of the caller, as for {@see HtmlViewerPolicy}.
 */
final class MarkdownTabPolicy
{
	private const EXTENSIONS = ['md', 'markdown'];

	/**
	 * The file name alone does not answer it: a Markdown file is stored as TypeFile::KNOWN, computed once
	 * on upload and not recomputed on rename - so a document renamed to .md keeps TypeFile::DOCUMENT and
	 * stays with the editors.
	 */
	public static function isTabFile(File $file): bool
	{
		return (int)$file->getTypeFile() === TypeFile::KNOWN
			&& self::isTabExtension($file->getExtension())
		;
	}

	/**
	 * The extension half of the answer, for a caller holding a name but no file (a revision carries its
	 * own). A portal without the renderer has no page to show the document on, and a portal without the
	 * flag keeps the slider.
	 */
	public static function isTabExtension(?string $extension): bool
	{
		return in_array(mb_strtolower((string)$extension), self::EXTENSIONS, true)
			&& Configuration::isEnabledMarkdownViewer()
			&& Feature::isEnabled(MarkdownTab::class)
		;
	}

	/**
	 * Asks about the very source the page shows: a revision keeps the name it was saved under and has no
	 * stored type of its own, so it is judged by its extension, and renaming the file changes nothing of it.
	 */
	public static function isTabSource(File $file, ?Version $version): bool
	{
		return $version === null
			? self::isTabFile($file)
			: self::isTabExtension($version->getExtension())
		;
	}
}
