<?php

namespace Bitrix\UI\Format\BBCode;

use CBXSanitizer;
use CTextParser;

final class Converter
{
	private static ?CTextParser $parser = null;
	private static ?CBXSanitizer $sanitizer = null;

	public static function toHtml(string $bb): string
	{
		if ($bb === '')
		{
			return '';
		}

		$bb = Whitelist::stripForbiddenBbTags($bb);

		return self::sanitizeHtml(self::getParser()->convertText($bb));
	}

	public static function toPlainText(string $bb): string
	{
		if ($bb === '')
		{
			return '';
		}

		foreach (Whitelist::getMediaTags() as $tag)
		{
			$quoted = preg_quote($tag, '#');
			$bb = (string)preg_replace("#\\[{$quoted}\\b[^\\]]*\\].*?\\[/{$quoted}\\s*\\]#isu", ' ', $bb);
		}

		$bb = (string)preg_replace('#\\[/?(?:list|p)\\b[^\\]]*\\]|\\[\\*\\]#iu', ' ', $bb);
		$bb = (string)preg_replace('#\\[/?[a-z][a-z0-9]*\\b[^\\]]*\\]#iu', '', $bb);
		$bb = str_replace(['&#91;', '&#93;'], ['[', ']'], $bb);
		$bb = (string)preg_replace('/[ \\t\\x{00A0}]+/u', ' ', $bb);

		return trim($bb);
	}

	private static function sanitizeHtml(string $html): string
	{
		if ($html === '' || !str_contains($html, '<'))
		{
			return $html;
		}

		return self::getSanitizer()->SanitizeHtml($html);
	}

	private static function getParser(): CTextParser
	{
		if (self::$parser === null)
		{
			$parser = new CTextParser();
			$parser->allow = Whitelist::getParserAllow();

			self::$parser = $parser;
		}

		return self::$parser;
	}

	private static function getSanitizer(): CBXSanitizer
	{
		if (self::$sanitizer === null)
		{
			$sanitizer = new CBXSanitizer();
			$sanitizer->ApplyDoubleEncode(false);
			$sanitizer->DelAllTags();
			$sanitizer->AddTags(Whitelist::getSanitizerTags());

			self::$sanitizer = $sanitizer;
		}

		return self::$sanitizer;
	}
}
