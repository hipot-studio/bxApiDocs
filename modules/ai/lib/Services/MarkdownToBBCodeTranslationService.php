<?php

declare(strict_types=1);

namespace Bitrix\Ai\Services;

class MarkdownToBBCodeTranslationService
{
	private const UNICODE_ESCAPES_MAP = [
		'\\\\' => '\\',
		'\\n' => "\n",
		'\\r' => "\r",
		'\\t' => "\t",
		'\\"' => '"',
		"\\'" => "'",
	];

	/**
	 * Whitespace characters to normalize to regular space
	 * Based on HTML attribute whitespace specification plus additional problematic characters:
	 */
	private const WHITESPACE_PATTERN = '/[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{180E}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]/u';

	/**
	 * Zero-width and directional characters to remove entirely
	 * These are invisible characters that can break parsing:
	 */
	private const ZERO_WIDTH_PATTERN = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u';

	/**
	 * Pattern to match leading whitespace (spaces and tabs) at the start of a line
	 */
	private const LEADING_WHITESPACE_PATTERN = '/^([ \t]*)(.*?)$/u';

	/**
	 * Pattern to match multiple consecutive spaces or tabs (2 or more)
	 * Used to collapse multiple whitespace to single space
	 */
	private const MULTIPLE_WHITESPACE_PATTERN = '/[ \t]{2,}/u';

	/**
	 * @var array<string, array{0:string,1:string}>
	 */
	private array $codeBlocks = [];

	/**
	 * @var array<string, string>
	 */
	private array $htmlTags = [];

	/**
	 * @var array<string, string>
	 */
	private array $images = [];

	private string $text = '';

	public function convert(string $text): string
	{
		$this->text = $text;
		$this->extractCodeBlocks();
		$this->processUnicodeEscapes();
		$this->normalizeWhitespace();
		$this->extractHtmlTags();
		$this->extractAndProcessImages();
		$this->processHeadings();
		$this->processHorizontalRule();
		$this->processLinks();
		$this->processMarkdownEmphasis();
		$this->processStrikethrough();
		$this->processLists();
		$this->processBlockQuotes();
		$this->restoreHtmlTags();
		$this->restoreCodeBlocks();
		$this->restoreImages();

		return $this->text;
	}

	private function processUnicodeEscapes(): void
	{
		$placeholder = "\x00BACKSLASH\x00";
		$this->text = str_replace('\\\\', $placeholder, $this->text);

		foreach (self::UNICODE_ESCAPES_MAP as $escape => $actual)
		{
			if ($escape === '\\\\')
			{
				continue;
			}
			$this->text = str_replace($escape, $actual, $this->text);
		}

		$this->text = str_replace($placeholder, '\\', $this->text);
	}

	private function normalizeWhitespace(): void
	{
		// First, remove zero-width and directional characters entirely
		$this->text = preg_replace(self::ZERO_WIDTH_PATTERN, '', $this->text);

		// Then normalize all various whitespace characters to regular space
		// but preserve newlines (\n), carriage returns (\r), and tabs (\t)
		$this->text = preg_replace_callback(
			self::WHITESPACE_PATTERN,
			static function (array $matches): string {
				$char = $matches[0];

				// Preserve line breaks (LF, CR) and tabs using direct comparison
				if ($char === "\t" || $char === "\n" || $char === "\r")
				{
					return $char;
				}

				// Replace all other whitespace with regular space
				return ' ';
			},
			$this->text
		);

		// Normalize line endings to \n for consistent processing
		// Handle Windows (\r\n), old Mac (\r), and Unix (\n) line endings
		$this->text = str_replace(["\r\n", "\r"], "\n", $this->text);

		// Clean up multiple consecutive spaces within lines (but preserve leading spaces)
		// Process line by line to avoid collapsing important indentation
		$lines = explode("\n", $this->text);
		$processedLines = [];

		foreach ($lines as $line)
		{
			// Match leading whitespace and the rest of the line separately
			preg_match(self::LEADING_WHITESPACE_PATTERN, $line, $matches);
			$leadingSpaces = $matches[1];
			$restOfLine = $matches[2];

			// Collapse multiple spaces/tabs in the rest of the line (not leading)
			$restOfLine = preg_replace(self::MULTIPLE_WHITESPACE_PATTERN, ' ', $restOfLine);

			$processedLines[] = $leadingSpaces . $restOfLine;
		}

		$this->text = implode("\n", $processedLines);
	}

	private function extractCodeBlocks(): void
	{
		$this->codeBlocks = [];

		$this->text = preg_replace_callback(
			'/```(?:[a-zA-Z]+)?\s*\n?(.*?)```/s',
			function (array $matches): string {
				$code = trim($matches[1]);
				$hash = md5($code);
				$this->codeBlocks[$hash] = ['code_block', $code];

				return "@@CODEBLOCK{$hash}@@";
			},
			$this->text,
		);

		$this->text = preg_replace_callback(
			'/`([^`\n]+?)`/',
			function (array $matches): string {
				$code = trim($matches[1]);
				$hash = md5($code);
				$this->codeBlocks[$hash] = ['inline_code', $code];

				return "@@INLINE{$hash}@@";
			},
			$this->text,
		);
	}

	private function restoreCodeBlocks(): void
	{
		foreach ($this->codeBlocks as $hash => [$kind, $code])
		{
			$tag = $kind === 'code_block' ? 'CODEBLOCK' : 'INLINE';
			$ph = "@@{$tag}{$hash}@@";
			$bb = $kind === 'code_block'
				? "[code]{$code}[/code]"
				: "[i]`{$code}`[/i]";
			$this->text = str_replace($ph, $bb, $this->text);
		}
	}

	private function extractHtmlTags(): void
	{
		$this->htmlTags = [];
		$this->text = preg_replace_callback(
			'/<[^>]+>/s',
			function (array $matches): string {
				$html = $matches[0];
				$hash = md5($html);
				$this->htmlTags[$hash] = $html;

				return "@@HTMLTAG{$hash}@@";
			},
			$this->text,
		);
	}

	private function restoreHtmlTags(): void
	{
		foreach ($this->htmlTags as $hash => $html)
		{
			$ph = "@@HTMLTAG{$hash}@@";
			$this->text = str_replace($ph, $html, $this->text);
		}
	}

	private function extractAndProcessImages(): void
	{
		$this->images = [];
		$this->text = preg_replace_callback(
			'/!\[(.*?)]\((.*?)\)/',
			function (array $m): string {
				$alt = trim($m[1]);
				$url = $this->sanitizeUrl($m[2]);

				if ($url === '')
				{
					return '';
				}

				$size = 'medium';
				if (preg_match('/\bsize=(small|medium|large)\b/i', $alt, $sm))
				{
					$size = strtolower($sm[1]);
				}

				$imageContent = "[img size={$size}]{$url} [/img]";
				$hash = md5($imageContent);
				$this->images[$hash] = $imageContent;

				return "@@IMAGE{$hash}@@";
			},
			$this->text,
		);
	}

	private function restoreImages(): void
	{
		foreach ($this->images as $hash => $imageContent)
		{
			$ph = "@@IMAGE{$hash}@@";
			$this->text = str_replace($ph, $imageContent, $this->text);
		}
	}

	private function processLists(): void
	{
		$lines = explode("\n", $this->text);
		$out = [];

		foreach ($lines as $line)
		{
			if (preg_match('/^([ \t]*)[-*] (.+)/', $line, $m))
			{
				$out[] = $m[1] . '- ' . $m[2];
			}
			elseif (preg_match('/^([ \t]*)(\d+)[.)] (.+)/', $line, $m))
			{
				$out[] = $m[1] . $m[2] . '. ' . $m[3];
			}
			else
			{
				$out[] = $line;
			}
		}

		$this->text = implode("\n", $out);
	}

	protected function processHeadings(): void
	{
		for ($i = 6; $i >= 1; $i--)
		{
			$hashes = str_repeat('#', $i);
			$pattern = '/^' . preg_quote($hashes, '/') . ' (.*)$/m';
			$this->text = preg_replace($pattern, "[b]$1[/b]", $this->text);
		}
	}

	private function processMarkdownEmphasis(): void
	{
		// Step 1: Handle italic with bold inside (\*italic \*\*bold\*\*\*)
		$this->text = preg_replace_callback('/(^|[\s.,;:!?\-()\[\]])\*([^*]*)\*\*([^*]*?)\*\*\*/um', function ($matches) {
			return $matches[1] . '[i]' . $matches[2] . '[b]' . $matches[3] . '[/b][/i]';
		}, $this->text);

		// Step 2: Handle bold with italic inside (\*\*bold \*italic\*\*\*)
		$this->text = preg_replace_callback('/(^|[\s.,;:!?\-()\[\]])\*\*([^*]*)\*([^*]*?)\*\*\*/um', function ($matches) {
			return $matches[1] . '[b]' . $matches[2] . '[i]' . $matches[3] . '[/i][/b]';
		}, $this->text);

		// Step 3: Handle bold+italic (\*\*\*text\*\*\*)
		$this->text = preg_replace('/(^|[\s.,;:!?\-()\[\]])\*\*\*([^*]+?)\*\*\*/um', '$1[b][i]$2[/i][/b]', $this->text);

		// Step 4: Handle remaining bold (\*\*text\*\*)
		$this->text = preg_replace('/(^|[\s.,;:!?\-()\[\]])\*\*([^*]+?)\*\*/um', '$1[b]$2[/b]', $this->text);

		// Step 5: Handle remaining italic (\*text\*)
		$this->text = preg_replace('/(^|[\s.,;:!?\-()\[\]])\*([^*]+?)\*/um', '$1[i]$2[/i]', $this->text);

		// Step 6: Handle underlined italic with bold inside (_italic __bold___)
		$this->text = preg_replace_callback('/(^|[\s.,;:!?\-()\[\]])_([^_]*)__([^_]*?)___/um', function ($matches) {
			return $matches[1] . '[i]' . $matches[2] . '[b]' . $matches[3] . '[/b][/i]';
		}, $this->text);

		// Step 7: Handle underlined bold with italic inside (__bold _italic___)
		$this->text = preg_replace_callback('/(^|[\s.,;:!?\-()\[\]])__([^_]*)_([^_]*?)___/um', function ($matches) {
			return $matches[1] . '[b]' . $matches[2] . '[i]' . $matches[3] . '[/i][/b]';
		}, $this->text);

		// Step 8: Handle underlined bold+italic (___text___)
		$this->text = preg_replace('/(^|[\s.,;:!?\-()\[\]])___([^_]+?)___/um', '$1[b][i]$2[/i][/b]', $this->text);

		// Step 9: Handle underlined remaining bold (__text__)
		$this->text = preg_replace('/(^|[\s.,;:!?\-()\[\]])__([^_]+?)__/um', '$1[b]$2[/b]', $this->text);

		// Step 10: Handle underlined remaining italic (_text_)
		$this->text = preg_replace('/(^|[\s.,;:!?\-()\[\]])_([^_]+?)_/um', '$1[i]$2[/i]', $this->text);
	}

	protected function processStrikethrough(): void
	{
		$this->text = preg_replace('/~~(.+?)~~/', '[s]$1[/s]', $this->text);
	}

	protected function processLinks(): void
	{
		$this->text = preg_replace_callback(
			'/\[(.*?)]\((.*?)\)/',
			function (array $m): string {
				$text = trim($m[1]);
				$url = trim($m[2]);
				$url = $this->sanitizeUrl($url);

				if ($url === '')
				{
					return $text; // If URL is invalid, return just the text
				}

				return "[url={$url}]{$text}[/url]";
			},
			$this->text,
		);
	}

	private function processHorizontalRule(): void
	{
		$this->text = preg_replace('/^\s*---\s*$/m', "\n", $this->text);
	}

	private function processBlockQuotes(): void
	{
		$lines = explode("\n", $this->text);
		$result = [];
		$inQuote = false;
		$quoteBuffer = [];
		$separator = "------------------------------------------------------";

		foreach ($lines as $line)
		{
			if (preg_match('/^>\s*(.+)$/', $line, $matches))
			{
				if (!$inQuote)
				{
					$inQuote = true;
					$result[] = $separator;
				}
				$quoteBuffer[] = $matches[1];
			}
			else
			{
				if ($inQuote)
				{
					$result = array_merge($result, $quoteBuffer);
					$result[] = $separator;
					$quoteBuffer = [];
					$inQuote = false;
				}
				$result[] = $line;
			}
		}

		if ($inQuote)
		{
			$result = array_merge($result, $quoteBuffer);
			$result[] = $separator;
		}

		$this->text = implode("\n", $result);
	}

	private function sanitizeUrl(string $url): string
	{
		// Ensure this is a valid URL
		if (filter_var($url, FILTER_VALIDATE_URL) !== false)
		{
			return $url;
		}

		return '';
	}
}
