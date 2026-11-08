<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature;

use Bitrix\Main\Config\Ini;

/**
 * Strips external resource references from the contents of <style> blocks.
 *
 * Expects markup already normalized by the core sanitizer: the block pattern needs a well-formed tag pair.
 *
 * The contents are read in one left-to-right pass: every character is visited once and nesting is held in an
 * explicit stack, so the cost grows with the length of the block and not with the depth of its functions.
 *
 * @internal
 */
final class StyleTagSanitizer
{
	private const STYLE_BLOCK_PATTERN = '~(?<open><style\b[^>]*>)(?<css>.*?)(?<close></style\s*>)~is';
	private const INLINE_VALUE_PATTERN = '~^data:~i';
	private const ESCAPE_SEQUENCE_PATTERN = '~\\\\(?:(?<hex>[0-9a-f]{1,6})[ \t\n\r\f]?|(?<char>[\s\S]))~i';
	private const EMPTY_URL_FUNCTION = 'url()';
	private const URL_FUNCTIONS = ['url', 'src'];
	private const IMAGE_SET_FUNCTIONS = ['image-set', '-webkit-image-set'];
	private const WHITESPACE = " \t\n\r\f";

	/** A string of the language ends at a raw newline, so the rest of the block stays visible to the pass. */
	private const NEWLINE = "\n\r\f";

	/** Ordinary declarations stay far below this; deeper arguments are treated as external and dropped. */
	private const MAX_FUNCTION_DEPTH = 32;

	public function sanitize(string $html): string
	{
		if (stripos($html, '<style') === false)
		{
			return $html;
		}

		Ini::adjustPcreBacktrackLimit(strlen($html) * 2);

		return preg_replace_callback(
			self::STYLE_BLOCK_PATTERN,
			fn(array $match): string => $match['open'] . $this->sanitizeCss($match['css']) . $match['close'],
			$html,
		) ?? $html;
	}

	private function sanitizeCss(string $css): string
	{
		$result = '';
		$position = 0;
		$length = strlen($css);

		/** @var array<int, array{imageSet: bool}> $functions */
		$functions = [];
		$depth = 0;

		while ($position < $length)
		{
			$char = $css[$position];

			if ($char === '/' && substr($css, $position, 2) === '/*')
			{
				$result .= $this->readComment($css, $position);

				continue;
			}

			if ($char === '"' || $char === '\'')
			{
				$string = $this->readString($css, $position);
				$insideImageSet = $depth > 0 && $functions[$depth - 1]['imageSet'];
				// Inside an image set a string is an address of its own, wherever in the entry it stands.
				$result .= $insideImageSet && !$this->isInlineValue($this->extractValue($string))
					? self::EMPTY_URL_FUNCTION
					: $string;

				continue;
			}

			if ($char === '@')
			{
				$result .= $this->readAtRule($css, $position, $depth > 0);

				continue;
			}

			if ($this->isIdentifierStart($css, $position))
			{
				$name = $this->readIdentifier($css, $position);
				// A lone backslash starts an identifier but reads as nothing: emit it and move on.
				if ($name === '')
				{
					$result .= $char;
					$position++;

					continue;
				}

				if (($css[$position] ?? '') !== '(')
				{
					$result .= $name;

					continue;
				}

				$position++;
				$decodedName = strtolower($this->decodeEscapes($name));

				if (in_array($decodedName, self::URL_FUNCTIONS, true))
				{
					$result .= $name . '(' . $this->readUrlArgument($css, $position);

					continue;
				}

				if ($depth >= self::MAX_FUNCTION_DEPTH)
				{
					$result .= $name . '(' . $this->readFunctionArguments($css, $position)['tail'];

					continue;
				}

				$functions[$depth] = ['imageSet' => in_array($decodedName, self::IMAGE_SET_FUNCTIONS, true)];
				$depth++;
				$result .= $name . '(';

				continue;
			}

			if ($char === ')' && $depth > 0)
			{
				$depth--;
				array_pop($functions);
				$result .= ')';
				$position++;

				continue;
			}

			$result .= $char;
			$position++;
		}

		return $result;
	}

	private function readUrlArgument(string $css, int &$position): string
	{
		$arguments = $this->readFunctionArguments($css, $position);

		return $this->isInlineValue($this->extractValue($arguments['value']))
			? $arguments['value'] . $arguments['tail']
			: $arguments['tail'];
	}

	private function readComment(string $css, int &$position): string
	{
		$end = strpos($css, '*/', $position + 2);
		$end = $end === false ? strlen($css) : $end + 2;
		$comment = substr($css, $position, $end - $position);
		$position = $end;

		return $comment;
	}

	private function readString(string $css, int &$position): string
	{
		$quote = $css[$position];
		$start = $position;
		$length = strlen($css);
		$position++;

		while ($position < $length)
		{
			$char = $css[$position];
			if ($char === '\\' && $position + 1 < $length)
			{
				$position += 2;

				continue;
			}

			if (strpos(self::NEWLINE, $char) !== false)
			{
				break;
			}

			$position++;
			if ($char === $quote)
			{
				break;
			}
		}

		return substr($css, $start, $position - $start);
	}

	private function readAtRule(string $css, int &$position, bool $insideFunction): string
	{
		$start = $position;
		$position++;
		$name = $this->isIdentifierStart($css, $position) ? $this->readIdentifier($css, $position) : '';

		if (strtolower($this->decodeEscapes($name)) !== 'import')
		{
			return substr($css, $start, $position - $start);
		}

		$this->skipRule($css, $position, $insideFunction);

		return '';
	}

	private function skipRule(string $css, int &$position, bool $insideFunction): void
	{
		$length = strlen($css);
		$parenDepth = 0;

		while ($position < $length)
		{
			$char = $css[$position];

			if ($char === '/' && substr($css, $position, 2) === '/*')
			{
				$this->readComment($css, $position);

				continue;
			}

			if ($char === '"' || $char === '\'')
			{
				$this->readString($css, $position);

				continue;
			}

			if ($char === '}')
			{
				return;
			}

			if ($char === '{')
			{
				$this->skipBlock($css, $position, $insideFunction);

				return;
			}

			if ($char === '(')
			{
				$parenDepth++;
			}
			elseif ($char === ')')
			{
				if ($insideFunction && $parenDepth === 0)
				{
					return;
				}

				$parenDepth--;
			}

			$position++;
			if ($char === ';')
			{
				return;
			}
		}
	}

	private function skipBlock(string $css, int &$position, bool $insideFunction): void
	{
		$length = strlen($css);
		$depth = 0;
		$parenDepth = 0;

		while ($position < $length)
		{
			$char = $css[$position];

			if ($char === '/' && substr($css, $position, 2) === '/*')
			{
				$this->readComment($css, $position);

				continue;
			}

			if ($char === '"' || $char === '\'')
			{
				$this->readString($css, $position);

				continue;
			}

			if ($char === '(')
			{
				$parenDepth++;
			}
			elseif ($char === ')')
			{
				if ($insideFunction && $parenDepth === 0)
				{
					return;
				}

				$parenDepth--;
			}

			$position++;
			if ($char === '{')
			{
				$depth++;

				continue;
			}
			if ($char === '}')
			{
				$depth--;
				if ($depth <= 0)
				{
					return;
				}
			}
		}
	}

	/** @return array{value: string, tail: string} */
	private function readFunctionArguments(string $css, int &$position): array
	{
		$start = $position;
		$length = strlen($css);
		$depth = 1;

		while ($position < $length)
		{
			$char = $css[$position];

			if ($char === '/' && substr($css, $position, 2) === '/*')
			{
				$this->readComment($css, $position);

				continue;
			}

			if ($char === '"' || $char === '\'')
			{
				$this->readString($css, $position);

				continue;
			}

			if ($char === '\\' && $position + 1 < $length)
			{
				$position += 2;

				continue;
			}

			$position++;
			if ($char === '(')
			{
				$depth++;

				continue;
			}
			if ($char === ')')
			{
				$depth--;
				if ($depth === 0)
				{
					return ['value' => substr($css, $start, $position - $start - 1), 'tail' => ')'];
				}
			}
		}

		return ['value' => substr($css, $start), 'tail' => ''];
	}

	private function readIdentifier(string $css, int &$position): string
	{
		$start = $position;
		$length = strlen($css);

		while ($position < $length)
		{
			$char = $css[$position];
			if ($char === '\\' && $position + 1 < $length)
			{
				$position++;
				$hexLength = strspn($css, '0123456789abcdefABCDEF', $position, 6);
				if ($hexLength === 0)
				{
					$position++;

					continue;
				}

				$position += $hexLength;
				if (strspn($css, self::WHITESPACE, $position, 1) === 1)
				{
					$position++;
				}

				continue;
			}

			if (preg_match('~[A-Za-z0-9_\-\x80-\xff]~', $char) !== 1)
			{
				break;
			}

			$position++;
		}

		return substr($css, $start, $position - $start);
	}

	private function isIdentifierStart(string $css, int $position): bool
	{
		$char = $css[$position] ?? '';

		return $char === '\\' || $char === '-' || $char === '_' || preg_match('~[A-Za-z\x80-\xff]~', $char) === 1;
	}

	private function extractValue(string $rawValue): string
	{
		$value = trim($rawValue, self::WHITESPACE);
		$quote = $value === '' ? '' : $value[0];
		if ($quote === '"' || $quote === '\'')
		{
			$position = 0;
			$string = $this->readString($value, $position);
			$closed = strlen($string) > 1 && str_ends_with($string, $quote);
			$value = substr($string, 1, $closed ? -1 : null);
		}

		return trim($this->decodeEscapes($value), self::WHITESPACE);
	}

	private function isInlineValue(string $value): bool
	{
		return preg_match(self::INLINE_VALUE_PATTERN, $value) === 1;
	}

	private function decodeEscapes(string $text): string
	{
		return preg_replace_callback(
			self::ESCAPE_SEQUENCE_PATTERN,
			static function (array $match): string {
				if (($match['hex'] ?? '') !== '')
				{
					$code = (int)hexdec($match['hex']);

					return $code > 0 && $code <= 0x10FFFF ? (string)mb_chr($code, 'UTF-8') : '';
				}

				return $match['char'] === "\n" ? '' : $match['char'];
			},
			$text,
		) ?? $text;
	}
}
