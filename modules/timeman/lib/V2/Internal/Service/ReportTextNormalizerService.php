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

		[$text, $encodedQuotes] = $this->protectEncodedQuotesInInlineFormattingTags($text);
		$text = $this->decodeHtmlEntities($text);
		$text = $this->stripLegacyTypographyTags($text);

		if (preg_match('/<\s*\/?\s*[a-zA-Z][a-zA-Z0-9-]*\b[^>]*>/u', $text))
		{
			$text = $this->stripPresentationAttributes($text);
			$text = $this->collapseBlockWrappers($text);
			$text = $this->mapInlineFormattingTags($text);
			$text = strtr($text, $encodedQuotes);
			$text = (string)(new \CTextParser())->convertHTMLToBB($text);
			$text = $this->stripLegacyTypographyTags($text);
		}
		else
		{
			$text = strtr($text, $encodedQuotes);
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

	private function protectEncodedQuotesInInlineFormattingTags(string $text): array
	{
		$encodedQuotes = [];
		$placeholderIndex = 0;

		$text = preg_replace_callback(
			'#<\s*(?:strong|b|em|i|u|del|s|strike)\b(?:\s+(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*)?\s*>#iu',
			static function (array $matches) use (&$encodedQuotes, &$placeholderIndex): string
			{
				return preg_replace_callback(
					'/&(?:quot|apos);|&#0*(?:34|39);|&#x0*(?:22|27);/i',
					static function (array $entity) use (&$encodedQuotes, &$placeholderIndex): string
					{
						$placeholder = "\x1E{$placeholderIndex}\x1F";
						$encodedQuotes[$placeholder] = $entity[0];
						$placeholderIndex++;

						return $placeholder;
					},
					$matches[0],
				) ?? $matches[0];
			},
			$text,
		) ?? $text;

		return [$text, $encodedQuotes];
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
		// code spans are shielded from the two rules this fix introduces: their markup has to
		// stay literal, the way a code block is meant to render
		[$text, $codeSpans] = $this->protectCodeSpans($text);
		// resolved early so the rule below can tell an existing line break from a missing one
		$text = $this->safeReplace('/<br\b[^>]*>/i', "\n", $text);
		$text = $this->safeReplace(
			'/<\/(?:p|div)>\s*<(?:p|div)[^>]*>/i',
			"\n\n",
			$text,
		);
		// a block wrapper opened right after content starts a new line. The preceding character
		// is consumed rather than looked behind at: a leading assertion would make the pattern
		// quadratic on input full of unclosed '<div ', while consuming keeps it linear.
		$text = $this->safeReplace('/([^\n])(<(?:p|div)[^>]*>)/i', "$1\n$2", $text);
		$text = strtr($text, $codeSpans);
		$text = $this->safeReplace(
			['/<(?:p|div)[^>]*>/i', '/<\/(?:p|div)>/i', '/<\/?span[^>]*>/i'],
			['', "\n", ''],
			$text,
		);

		return $this->safeReplace('/\n{3,}/', "\n\n", $text);
	}

	private function protectCodeSpans(string $text): array
	{
		$spans = [];
		$index = 0;

		$text = preg_replace_callback(
			'#\[code\].*?\[/code\]|<code\b[^>]*>.*?</code\s*>#isu',
			static function (array $matches) use (&$spans, &$index): string
			{
				$placeholder = "\x1C{$index}\x1D";
				$spans[$placeholder] = $matches[0];
				$index++;

				return $placeholder;
			},
			$text,
		) ?? $text;

		return [$text, $spans];
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
				'#<(?:del|s|strike)(?:\s+(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*)?\s*>#iu',
				'#</(?:del|s|strike)\s*>#iu',
			],
			['[b]', '[/b]', '[i]', '[/i]', '[u]', '[/u]', '[s]', '[/s]'],
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
