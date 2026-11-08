<?php

declare(strict_types=1);

namespace Bitrix\Landing\Html;

use Bitrix\Main\Web\DOM;

/**
 * Builds a DOM document with a parser of its own.
 *
 * Document::__construct() takes the parser of Parser::getHtmlParser() - one instance per
 * process. With php storing on, that parser cuts every `<? … ?>` into a placeholder comment
 * and keeps the source in its memory until the process ends, so a slice one document brought
 * in is restored into any later document carrying the same comment (mantis 0257122). A parser
 * created here lives exactly as long as the document that owns it.
 */
final class DocumentFactory
{
	/**
	 * For html that comes from a user or from a model: php is not stored at all. The parser
	 * turns it into a comment instead, and the sanitizing that follows drops the comment.
	 */
	public static function createForUserHtml(): DOM\Document
	{
		$parser = new DOM\HtmlParser();
		$parser->storePhpCode = false;

		return self::createWithParser($parser);
	}

	/**
	 * For content already stored in the database: php of the block is kept through
	 * loadHTML()/saveHTML() as before, but only the php of this very document.
	 */
	public static function createForStoredContent(): DOM\Document
	{
		return self::createWithParser(new DOM\HtmlParser());
	}

	private static function createWithParser(DOM\HtmlParser $parser): DOM\Document
	{
		$document = new DOM\Document();
		$document->setParser($parser);

		return $document;
	}
}
