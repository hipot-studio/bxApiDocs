<?php

declare(strict_types=1);

namespace Bitrix\AI\Services;

use CBXSanitizer;
use Parsedown;

class MarkdownToHtmlTranslationService
{
	private const ASTERISK_PLACEHOLDER = "\x01BXAST\x01";
	private const UNDERSCORE_PLACEHOLDER = "\x01BXUND\x01";
	private const CODE_LANGUAGE_CLASS_REGEX = '/\Alanguage-[A-Za-z0-9_#+.-]{1,32}\z/';

	/**
	 * Replaces mid-word `*` and `_` with placeholders so Parsedown won't treat them as emphasis.
	 * E.g. A*B stays literal, while *italic* is still processed.
	 */
	private function protectNonEmphasis(string $text): string
	{
		// Protect code blocks from modification
		$codeBlocks = [];
		$text = preg_replace_callback('/```(?:[a-zA-Z]+)?\s*\n?[\s\S]*?```|`[^`\n]+?`/', function ($m) use (&$codeBlocks) {
			$placeholder = "\x01CODE" . count($codeBlocks) . "\x01";
			$codeBlocks[$placeholder] = $m[0];
			return $placeholder;
		}, $text);

		// Replace * between word characters with placeholder (A*B, 2*3, etc.)
		$text = preg_replace('/(?<=\w)\*(?=\w)/u', self::ASTERISK_PLACEHOLDER, $text);

		// Replace _ between word characters with placeholder (snake_case, etc.)
		$text = preg_replace('/(?<=\w)_(?=\w)/u', self::UNDERSCORE_PLACEHOLDER, $text);

		// Restore code blocks
		foreach ($codeBlocks as $placeholder => $code)
		{
			$text = str_replace($placeholder, $code, $text);
		}

		return $text;
	}

	private function restorePlaceholders(string $html): string
	{
		$html = str_replace(self::ASTERISK_PLACEHOLDER, '*', $html);
		$html = str_replace(self::UNDERSCORE_PLACEHOLDER, '_', $html);

		return $html;
	}

	public function convert(string $text): string
	{
		$text = $this->protectNonEmphasis($text);
		$html = (string)(new Parsedown())->setBreaksEnabled(true)->text($text);
		$html = $this->restorePlaceholders($html);

		$sanitizer = new class(self::CODE_LANGUAGE_CLASS_REGEX) extends CBXSanitizer
		{
			public function __construct(private string $codeLanguageClassRegex)
			{
				parent::__construct();
			}

			protected function IsValidAttr(mixed &$attribute): bool
			{
				// Validate the source value before the sanitizer's non-browser entity decoding.
				if (
					$attribute[1] === 'class'
					&& preg_match($this->codeLanguageClassRegex, $attribute[3]) !== 1
				)
				{
					return false;
				}

				return parent::IsValidAttr($attribute);
			}
		};
		$sanitizer->setLevel(CBXSanitizer::SECURE_LEVEL_MIDDLE);
		$sanitizer->addTags(['em' => [], 'ol' => ['start']]);
		$sanitizer->allowAttributes([
			'class' => [
				'tag' => static fn(string $tag): bool => $tag === 'code',
				'content' => static fn(string $value): bool =>
					preg_match(self::CODE_LANGUAGE_CLASS_REGEX, $value) === 1,
			],
		]);
		$sanitizer->applyDoubleEncode(false);

		return $sanitizer->sanitizeHtml($html);
	}
}
