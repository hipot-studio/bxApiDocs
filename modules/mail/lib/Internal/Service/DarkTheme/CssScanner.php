<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\DarkTheme;

/**
 * Lexical primitives the recolor needs to read author CSS without changing it.
 *
 * A brace inside a comment does not open a block, a semicolon inside a quoted url does not separate
 * declarations, a comment in front of a property name is not part of the name, and a backslash inside a
 * comment or a string escapes nothing. All of them need the same answer: where does the comment or the string
 * that starts here end.
 */
final class CssScanner
{
	private const COMMENT_START = '/*';
	private const COMMENT_END = '*/';

	private const VARIABLE_FUNCTION = 'var(';

	/**
	 * Nesting of custom property references a value is unwrapped to; deeper is not a value written by hand.
	 *
	 * It is the budget of the whole chain and not of one level of it: a caller that answers a fallback by asking
	 * about that fallback again restarts the count, and the work of a chain of N references then grows as its
	 * square while the tail of it is copied at every level. The detector refuses a body whose CSS nests deeper
	 * than this, so the unwrapping always reaches the end of the chain inside the budget and no caller has to
	 * re-enter it.
	 */
	public const MAX_VARIABLE_DEPTH = 8;

	/**
	 * Whitespace of CSS, and the only definition of it here. The form feed is one of it and the vertical tab is
	 * not - the reverse of what `trim()` reads - so neither answer of this class may be built out of `trim()`.
	 */
	private const WHITESPACE = [
		' ' => true,
		"\t" => true,
		"\n" => true,
		"\r" => true,
		"\f" => true,
	];

	/**
	 * Returns the length of the comment or the string starting at the position, or 1 for any other character.
	 * An unterminated comment runs to the end of the input, an unterminated string only to the end of its line.
	 *
	 * @param string $css
	 * @param int $position
	 * @return int
	 */
	public static function getAtomLength(string $css, int $position): int
	{
		if (substr($css, $position, 2) === self::COMMENT_START)
		{
			$end = strpos($css, self::COMMENT_END, $position + 2);

			return $end === false
				? strlen($css) - $position
				: $end + strlen(self::COMMENT_END) - $position;
		}

		$quote = $css[$position];

		return $quote === '"' || $quote === "'"
			? self::getStringLength($css, $position, $quote)
			: 1;
	}

	/**
	 * Returns the text with its comments dropped, so that a property name can be read from what is left. The
	 * comment itself is never dropped from the message: only the name the rewriter matches is read this way.
	 *
	 * @param string $css
	 * @return string
	 */
	public static function stripComments(string $css): string
	{
		if (!str_contains($css, self::COMMENT_START))
		{
			return $css;
		}

		$stripped = '';
		$length = strlen($css);

		for ($position = 0; $position < $length; $position++)
		{
			$atomLength = self::getAtomLength($css, $position);
			if ($atomLength === 1)
			{
				$stripped .= $css[$position];

				continue;
			}

			if (substr($css, $position, 2) !== self::COMMENT_START)
			{
				$stripped .= substr($css, $position, $atomLength);
			}

			$position += $atomLength - 1;
		}

		return $stripped;
	}

	/**
	 * Returns the text with only the comments written at its edges dropped, whitespace around them included.
	 *
	 * That is what a property name may legitimately carry: a comment written in front of the identifier or behind
	 * it is trivia a browser drops, because it stands beside the identifier and not inside it. A comment between
	 * two parts of the identifier ends the identifier, and the browser throws such a declaration away - so a
	 * comment left in the result of this call is the sign of a name that is not a property at all.
	 *
	 * The edges are found as the two offsets of what is kept, because the atoms in between are kept as they were
	 * written and the answer is the text between those offsets. A list of atoms with its leading ones removed one
	 * by one would carry every atom behind them over on each removal - a name of two hundred thousand spaces in
	 * front of the property then costs the square of its length.
	 *
	 * @param string $css
	 * @return string
	 */
	public static function stripEdgeComments(string $css): string
	{
		if (!str_contains($css, self::COMMENT_START))
		{
			return $css;
		}

		$length = strlen($css);
		$start = null;
		$end = 0;

		for ($position = 0; $position < $length;)
		{
			$atomLength = self::getAtomLength($css, $position);

			if (!self::isTrivia($css, $position, $atomLength))
			{
				$start ??= $position;
				$end = $position + $atomLength;
			}

			$position += $atomLength;
		}

		return $start === null ? '' : substr($css, $start, $end - $start);
	}

	/**
	 * An atom is a comment, a string or a single character, so trivia is either a comment or one whitespace
	 * character - read by the same definition of whitespace the rest of the class uses.
	 */
	private static function isTrivia(string $css, int $position, int $atomLength): bool
	{
		return $atomLength === 1
			? self::isWhitespace($css[$position])
			: substr($css, $position, 2) === self::COMMENT_START
		;
	}

	/**
	 * Splits a value into the components a list is written of, on the whitespace that separates them.
	 *
	 * Whitespace inside a function, a string or a comment belongs to the component and does not separate two of
	 * them. A caller reads a list of a known length - `border-color` takes four components and no more - so a
	 * longer list is not a list it could use, and the answer is null as soon as the limit is passed: one value
	 * holding a hundred thousand components then costs a look at its first four and not a walk to its end.
	 *
	 * @param string $value
	 * @param int $limit Largest list the caller can use.
	 * @return array<int, string>|null Components in the order of the source, or null for a longer list.
	 */
	public static function splitListComponents(string $value, int $limit): ?array
	{
		$components = [];
		$length = strlen($value);
		$depth = 0;
		$start = null;

		for ($position = 0; $position < $length; $position++)
		{
			$atomLength = self::getAtomLength($value, $position);
			if ($atomLength > 1)
			{
				$start ??= $position;
				$position += $atomLength - 1;

				continue;
			}

			$character = $value[$position];

			if ($character === '(')
			{
				$depth++;
			}
			elseif ($character === ')')
			{
				$depth = max($depth - 1, 0);
			}
			elseif ($depth === 0 && self::isWhitespace($character))
			{
				if ($start !== null)
				{
					if (count($components) >= $limit)
					{
						return null;
					}

					$components[] = substr($value, $start, $position - $start);
					$start = null;
				}

				continue;
			}

			$start ??= $position;
		}

		if ($start === null)
		{
			return $components;
		}

		return count($components) >= $limit
			? null
			: [...$components, substr($value, $start)]
		;
	}

	/**
	 * Returns the fallback the author wrote into a value that is one custom property reference, or null when
	 * the value is anything else or names no fallback.
	 *
	 * `var(--brand, #ffffff)` is a value the recolor does not read - the property it names lives in a sheet the
	 * pipeline may never see - and at the same time a paint the author did fix: the fallback is what the view
	 * shows whenever the property is not set, which in a message it usually is not. Only a value that is
	 * nothing but the reference is answered: beside anything else, which layer of the value the fallback paints
	 * is not a question the scanner can settle.
	 *
	 * A fallback that is itself a reference is unwrapped, to the depth an author may reasonably nest.
	 *
	 * @param string $value
	 * @return string|null Fallback as it was written.
	 */
	public static function readVariableFallback(string $value): ?string
	{
		for ($depth = 0; $depth < self::MAX_VARIABLE_DEPTH; $depth++)
		{
			$fallback = self::readSingleVariableFallback($value);
			if ($fallback === null)
			{
				return $depth === 0 ? null : $value;
			}

			$value = $fallback;
		}

		return $value;
	}

	/**
	 * Returns the fallback of a value that is exactly one `var()` reference, or null.
	 */
	private static function readSingleVariableFallback(string $value): ?string
	{
		$value = self::trimWhitespace($value);
		$length = strlen($value);

		if ($length < 5 || strtolower(substr($value, 0, 4)) !== self::VARIABLE_FUNCTION)
		{
			return null;
		}

		$separator = null;
		$depth = 0;

		for ($position = 3; $position < $length; $position++)
		{
			$atomLength = self::getAtomLength($value, $position);
			if ($atomLength > 1)
			{
				$position += $atomLength - 1;

				continue;
			}

			$character = $value[$position];

			if ($character === '(')
			{
				$depth++;
			}
			elseif ($character === ')')
			{
				$depth--;
				if ($depth === 0)
				{
					// The reference has to be the whole value: `var(--a) var(--b)` names no single fallback.
					return $position === $length - 1 && $separator !== null
						? self::trimWhitespace(substr($value, $separator + 1, $position - $separator - 1))
						: null;
				}
			}
			elseif ($character === ',' && $depth === 1 && $separator === null)
			{
				$separator = $position;
			}
		}

		return null;
	}

	/** Drops the CSS whitespace around the text; `trim()` reads a different set of characters. */
	private static function trimWhitespace(string $text): string
	{
		$start = 0;
		$end = strlen($text);

		while ($start < $end && self::isWhitespace($text[$start]))
		{
			$start++;
		}

		while ($end > $start && self::isWhitespace($text[$end - 1]))
		{
			$end--;
		}

		return substr($text, $start, $end - $start);
	}

	private static function isWhitespace(string $character): bool
	{
		return isset(self::WHITESPACE[$character]);
	}

	/**
	 * Tells whether the text holds a backslash the scanner would have to decode to find the block boundaries.
	 *
	 * A backslash inside a comment or a string is content: the scanner skips a comment whole and reads a string
	 * with its escapes, so neither can move a brace. Outside both it starts a CSS escape - `\7B`, `\}` - that
	 * only a decoder can resolve, and the boundaries of the sheet are then not the ones the scanner sees.
	 *
	 * @param string $css
	 * @return bool
	 */
	public static function hasStructuralBackslash(string $css): bool
	{
		if (!str_contains($css, '\\'))
		{
			return false;
		}

		$length = strlen($css);

		for ($position = 0; $position < $length; $position++)
		{
			$atomLength = self::getAtomLength($css, $position);
			if ($atomLength > 1)
			{
				$position += $atomLength - 1;

				continue;
			}

			if ($css[$position] === '\\')
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * A string that is never closed is a bad string, and CSS ends it at the line break: everything written after
	 * that break is structure again, so a rule following an unclosed quote is a rule for the reader as well.
	 * A backslash still escapes the character behind it, a line break among them - that is a continued line and
	 * not the end of the string.
	 */
	private static function getStringLength(string $css, int $position, string $quote): int
	{
		$length = strlen($css);

		for ($index = $position + 1; $index < $length; $index++)
		{
			if ($css[$index] === '\\')
			{
				$index++;

				continue;
			}

			if ($css[$index] === $quote)
			{
				return $index + 1 - $position;
			}

			if ($css[$index] === "\n" || $css[$index] === "\r" || $css[$index] === "\f")
			{
				return $index - $position;
			}
		}

		return $length - $position;
	}
}
