<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

final class ReportTextNormalizerService
{
	public function normalize(?string $value): string
	{
		$text = $value ?? '';
		if ($text === '')
		{
			return '';
		}

		$text = $this->decodeHtmlEntities($text);
		$text = $this->stripLegacyTypographyTags($text);

		if (preg_match('/<\s*\/?\s*[a-zA-Z][a-zA-Z0-9-]*\b[^>]*>/u', $text))
		{
			$text = $this->stripPresentationAttributes($text);
			$text = $this->collapseBlockWrappers($text);
			$text = $this->mapInlineFormattingTags($text);
			$text = (string)(new \CTextParser())->convertHTMLToBB($text);
			$text = $this->stripLegacyTypographyTags($text);
		}

		return $this->cleanupWhitespace($text);
	}

	public function flattenParagraphsForChat(string $text): string
	{
		if ($text === '')
		{
			return '';
		}

		$text = preg_replace('#\[/p\]\s*\[p\]#iu', "\n", $text) ?? $text;
		$text = preg_replace('#\[/?p\]#iu', '', $text) ?? $text;
		$text = preg_replace('#\n{3,}#', "\n\n", $text) ?? $text;

		return trim($text);
	}

	private function cleanupWhitespace(string $text): string
	{
		$text = $this->safeReplace('/[ \t]+$/mu', '', $text);
		$text = $this->safeReplace('/\n{3,}/', "\n\n", $text);

		return trim($text);
	}

	private function decodeHtmlEntities(string $text): string
	{
		return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	private function stripPresentationAttributes(string $text): string
	{
		return $this->safeReplace(
			[
				'/\s+style\s*=\s*"[^"]*"/i',
				"/\\s+style\\s*=\\s*'[^']*'/i",
				'/\s+class\s*=\s*"[^"]*"/i',
				"/\\s+class\\s*=\\s*'[^']*'/i",
			],
			'',
			$text,
		);
	}

	private function collapseBlockWrappers(string $text): string
	{
		$text = $this->safeReplace(
			'/<\/(?:p|div)>\s*<(?:p|div)[^>]*>/i',
			"\n\n",
			$text,
		);
		$text = $this->safeReplace(
			['/<(?:p|div)[^>]*>/i', '/<\/(?:p|div)>/i', '/<\/?span[^>]*>/i'],
			['', "\n", ''],
			$text,
		);

		return $this->safeReplace('/\n{3,}/', "\n\n", $text);
	}

	private function mapInlineFormattingTags(string $text): string
	{
		return $this->safeReplace(
			[
				'#<(?:strong|b)(?:\s[^>]*)?>#iu',
				'#</(?:strong|b)\s*>#iu',
				'#<(?:em|i)(?:\s[^>]*)?>#iu',
				'#</(?:em|i)\s*>#iu',
				'#<u(?:\s[^>]*)?>#iu',
				'#</u\s*>#iu',
			],
			['[b]', '[/b]', '[i]', '[/i]', '[u]', '[/u]'],
			$text,
		);
	}

	private function stripLegacyTypographyTags(string $text): string
	{
		return $this->safeReplace(
			['/\[(size|color|font)(=[^\]]*)?\]/i', '/\[\/(size|color|font)\]/i'],
			'',
			$text,
		);
	}

	private function safeReplace(string|array $pattern, string|array $replacement, string $text): string
	{
		return preg_replace($pattern, $replacement, $text) ?? $text;
	}
}
