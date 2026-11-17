<?php

declare(strict_types=1);

namespace Bitrix\Landing\Security;

use Bitrix\Landing\Sanitizer;

/**
 * Substitutes markers into block content.
 */
final class ContentMarkers
{
	private const PLAIN_BUFFER_HANDLER = 'default output handler';

	/** Whitespace of the html tokenizer. Unlike \s of pcre it holds no vertical tab, which is part of a value. */
	private const HTML_SPACE = " \t\n\f\r";

	/**
	 * What a browser reads as an assignment to an attribute whose value it runs or treats as an address.
	 * Matched anywhere, even where a parser would see plain text: reading more than a browser does costs a
	 * marker left as text, while reading less, as a tag parser that disagrees with a browser may, costs a script.
	 * The match ends where the value starts, so an assignment that seems to lie inside a value is still looked at.
	 */
	private const SENSITIVE_ATTRIBUTE_PATTERN = '/(?<![\w:.-])('
		. 'on[\w-]*+|srcdoc|style'
		. '|data|poster|background|cite|ping|manifest|codebase|longdesc|archive|classid|code|usemap|profile'
		. '|to|from|by|values'
		. '|[\w:.-]*?(?:href|src|srcset|url|action)'
		. ')[' . self::HTML_SPACE . ']*+=[' . self::HTML_SPACE . ']*+/i';

	/**
	 * Attributes of the pattern above where no value of a marker is fine, not even a whole one. The pseudo url
	 * holds json, which reads as an address without a scheme whatever the href inside it is.
	 */
	private const NEVER_REPLACED_ATTRIBUTE_PATTERN = '/^(?:on|(?:srcdoc|style|data-pseudo-url)$)/i';

	/** Stands in for an empty value: with nothing in place of a marker the text around it would join up. */
	private const EMPTY_VALUE = '&#8203;';

	/**
	 * Replaces markers with their values, like str_replace() over array_keys() and array_values(),
	 * and prints a less-than sign standing right before a marker as an entity.
	 *
	 * Values are put in as they are and may hold markup, their source is responsible for them.
	 *
	 * @param string $content Content of the block.
	 * @param array $replace Marker => ready value.
	 * @return string
	 */
	public static function replace(string $content, array $replace): string
	{
		foreach ($replace as $marker => $value)
		{
			$marker = (string)$marker;
			if ($marker === '')
			{
				continue;
			}

			// One marker at a time, guarded by ContentMarkersTest.
			$content = str_replace('<' . $marker, '&lt;' . $marker, $content);
			$content = str_replace($marker, (string)$value, $content);
		}

		return $content;
	}

	/**
	 * Replaces markers with values that come from outside the content, in the ready output of a block.
	 *
	 * Sanitizer::encodeContentMarkerValue() keeps a value from completing a name of a tag or an attribute.
	 * What it cannot do is seen only here, together with the text around the marker: in an attribute a browser
	 * runs or reads as an address the marker stays text, unless it is the whole quoted address and that is allowed.
	 *
	 * @param string $output Output of the block.
	 * @param array $replace Marker => encoded value.
	 * @return string
	 */
	public static function replaceInOutput(string $output, array $replace): string
	{
		foreach ($replace as $marker => $value)
		{
			$marker = (string)$marker;
			if ($marker === '')
			{
				continue;
			}

			$output = str_replace('<' . $marker, '&lt;' . $marker, $output);
			$output = self::replaceKeepingSensitiveAttributes($output, $marker, (string)$value);
		}

		return $output;
	}

	private static function replaceKeepingSensitiveAttributes(string $output, string $marker, string $value): string
	{
		$kept = self::findMarkersToKeep($output, $marker, $value);
		if ($kept === null)
		{
			return $output;
		}

		$value = $value === '' ? self::EMPTY_VALUE : $value;
		$result = '';
		$position = 0;
		while (($found = strpos($output, $marker, $position)) !== false)
		{
			$result .= substr($output, $position, $found - $position);
			$result .= isset($kept[$found]) ? $marker : $value;
			$position = $found + strlen($marker);
		}

		return $result . substr($output, $position);
	}

	/**
	 * One pass over the output: assignments come in the order of their values, so the ends of the values and the
	 * markers are read from where the previous look stopped, and the cost does not grow with their overlap.
	 *
	 * @return array|null Offsets of the markers to leave as text as keys, null if the output could not be read.
	 */
	private static function findMarkersToKeep(string $output, string $marker, string $value): ?array
	{
		$kept = [];
		$valueEnds = [];
		$keptUpTo = 0;
		$nextMarker = strpos($output, $marker);
		$offset = 0;

		while ($nextMarker !== false)
		{
			$found = preg_match(self::SENSITIVE_ATTRIBUTE_PATTERN, $output, $match, PREG_OFFSET_CAPTURE, $offset);
			if ($found === false)
			{
				return null;
			}

			$offset = $found ? $match[0][1] + strlen($match[0][0]) : strlen($output);
			while ($nextMarker !== false && $nextMarker < $offset)
			{
				if ($nextMarker < $keptUpTo)
				{
					$kept[$nextMarker] = true;
				}
				$nextMarker = strpos($output, $marker, $nextMarker + strlen($marker));
			}

			if (!$found)
			{
				break;
			}

			$quote = $output[$offset] ?? '';
			$isQuoted = $quote === '"' || $quote === "'";
			$terminators = $isQuoted ? $quote : self::HTML_SPACE . '>';
			$contentStart = $isQuoted ? $offset + 1 : $offset;
			if (($valueEnds[$terminators] ?? -1) < $contentStart)
			{
				$valueEnds[$terminators] = $contentStart + strcspn($output, $terminators, $contentStart);
			}

			$isReplaced = $isQuoted
				&& $nextMarker === $contentStart
				&& $valueEnds[$quote] === $contentStart + strlen($marker)
				&& $contentStart + strlen($marker) < strlen($output)
				&& preg_match(self::NEVER_REPLACED_ATTRIBUTE_PATTERN, $match[1][0]) !== 1
				&& self::isAllowedAddress($value)
			;
			if (!$isReplaced)
			{
				$keptUpTo = max($keptUpTo, $valueEnds[$terminators]);
			}
		}

		return $kept;
	}

	private static function isAllowedAddress(string $value): bool
	{
		// html_entity_decode() skips references of control characters, and a browser drops those from a scheme
		$ascii = preg_replace_callback(
			'/&#(\d{1,3});/',
			static fn(array $match): string => (int)$match[1] < 128 ? chr((int)$match[1]) : $match[0],
			$value,
		);

		return !Sanitizer::hasDisallowedScheme(html_entity_decode((string)$ascii, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}

	/**
	 * Closes the output buffer opened right above the given level and prints its content with markers replaced.
	 *
	 * Plain buffers left open inside are flushed down to it first. If the buffer is gone, or any buffer above
	 * the level has a handler of its own, nothing is touched: such a buffer is not ours to close.
	 *
	 * @param int $bufferLevel Value of ob_get_level() before the buffer was opened.
	 * @param array $replace Marker => encoded value, see replaceInOutput().
	 * @return void
	 */
	public static function flushBuffer(int $bufferLevel, array $replace): void
	{
		$handlers = array_slice(ob_list_handlers(), $bufferLevel);
		if (array_unique($handlers) !== [self::PLAIN_BUFFER_HANDLER])
		{
			return;
		}

		for ($left = count($handlers); $left > 1; $left--)
		{
			ob_end_flush();
		}

		echo self::replaceInOutput((string)ob_get_clean(), $replace);
	}
}
