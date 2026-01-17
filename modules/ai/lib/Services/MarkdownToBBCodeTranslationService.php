<?php

declare(strict_types=1);

namespace Bitrix\Ai\Services;

class MarkdownToBBCodeTranslationService
{
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
			$bb = "[code]{$code}[/code]";
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
