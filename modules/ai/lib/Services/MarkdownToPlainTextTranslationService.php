<?php

declare(strict_types=1);

namespace Bitrix\AI\Services;

class MarkdownToPlainTextTranslationService
{
	public function convert(string $text): string
	{
		// Remove code blocks (```code```)
		$text = preg_replace('/```(?:[a-zA-Z]+)?\s*\n?(.*?)```/s', '$1', $text);

		// Remove inline code (`code`)
		$text = preg_replace('/`([^`\n]+?)`/', '$1', $text);

		// Remove images (![alt](url))
		$text = preg_replace('/!\[.*?]\(.*?\)/', '', $text);

		// Convert links to text only ([text](url) -> text)
		$text = preg_replace('/\[(.*?)]\(.*?\)/', '$1', $text);

		// Remove strikethrough (~~text~~)
		$text = preg_replace('/~~(.+?)~~/', '$1', $text);

		// Remove bold+italic (***text*** or ___text___)
		$b = '\\s.,;:!?\\-()\\[\\]';
		$before = '(^|[' . $b . '])';
		$after = '(?=$|[' . $b . '])';

		$text = preg_replace('/' . $before . '\\*\\*\\*(?!\\s)([^*]+?)(?<!\\s)\\*\\*\\*' . $after . '/um', '$1$2', $text);
		$text = preg_replace('/' . $before . '___(?!\\s)([^_]+?)(?<!\\s)___' . $after . '/um', '$1$2', $text);

		// Remove bold (**text** or __text__)
		$text = preg_replace('/' . $before . '\\*\\*(?!\\s)([^*]+?)(?<!\\s)\\*\\*' . $after . '/um', '$1$2', $text);
		$text = preg_replace('/' . $before . '__(?!\\s)([^_]+?)(?<!\\s)__' . $after . '/um', '$1$2', $text);

		// Remove italic (*text* or _text_)
		$text = preg_replace('/' . $before . '\\*(?!\\s)([^*]+?)(?<!\\s)\\*' . $after . '/um', '$1$2', $text);
		$text = preg_replace('/' . $before . '_(?!\\s)([^_]+?)(?<!\\s)_' . $after . '/um', '$1$2', $text);

		// Remove headings (# text -> text)
		$text = preg_replace('/^#{1,6}\s+(.*)$/m', '$1', $text);

		// Remove horizontal rules (---)
		$text = preg_replace('/^\s*---\s*$/m', '', $text);

		// Process blockquotes (> text -> text)
		$text = preg_replace('/^>\s*(.*)$/m', '$1', $text);

		// Process lists: convert markdown list markers to visual bullets
		$lines = explode("\n", $text);
		$result = [];

		foreach ($lines as $line)
		{
			// Unordered lists: - item or * item -> • item
			if (preg_match('/^([ \t]*)[-*]\s+(.+)/', $line, $m))
			{
				$result[] = $m[1] . '• ' . $m[2];
			}
			// Ordered lists: 1. item or 1) item -> 1. item
			elseif (preg_match('/^([ \t]*)(\d+)[.)]\s+(.+)/', $line, $m))
			{
				$result[] = $m[1] . $m[2] . '. ' . $m[3];
			}
			else
			{
				$result[] = $line;
			}
		}

		$text = implode("\n", $result);

		// Remove escape characters (\)
		$text = preg_replace('/\\\\([\\\\`*_{}[\]()#+\-.!|])/', '$1', $text);

		// Clean up multiple blank lines
		$text = preg_replace('/\n{3,}/', "\n\n", $text);

		// Trim leading/trailing whitespace
		$text = trim($text);

		return $text;
	}
}

