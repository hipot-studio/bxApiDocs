<?php

namespace Bitrix\Landing\Markup\Form;

use Bitrix\Landing\Markup\Validator;

/**
 * Finds CRM form markers and foreign `<form>` elements in the entry document of a markup package.
 *
 * A lightweight tag walk, not a DOM parser: the content of `script`, `style`, `textarea` and `title`
 * and comments are skipped, quoted attribute values may hold `>`. The detector of the validation and the
 * deterministic cut-out of foreign forms share it, so both see the same ranges.
 */
class FormMarkupScanner
{
	public const ATTRIBUTE_INLINE = 'data-bx-crm-form';
	public const ATTRIBUTE_POPUP = 'data-bx-crm-form-popup';

	public const MODE_INLINE = 'inline';
	public const MODE_POPUP = 'popup';

	private const WHITESPACE = " \t\n\r\f";
	private const RAW_TEXT_TAGS = ['script', 'style', 'textarea', 'title'];
	private const VOID_TAGS = [
		'area',
		'base',
		'br',
		'col',
		'embed',
		'hr',
		'img',
		'input',
		'link',
		'meta',
		'param',
		'source',
		'track',
		'wbr',
	];

	/**
	 * Markers in the order of the document. A marker with an invalid token is not a marker,
	 * neither is one put on a `<form>` (that is a foreign form).
	 * @return list<array{token: string, mode: string, tagStart: int, tagEnd: int}>
	 *     tagEnd is the offset right after the start tag
	 */
	public function findMarkers(string $html): array
	{
		$modes = [
			self::ATTRIBUTE_INLINE => self::MODE_INLINE,
			self::ATTRIBUTE_POPUP => self::MODE_POPUP,
		];

		$markers = [];
		foreach ($this->walkTags($html) as $tag)
		{
			if ($tag['closing'] || $tag['name'] === 'form')
			{
				continue;
			}
			foreach ($modes as $attribute => $mode)
			{
				$token = trim((string)($tag['attributes'][$attribute] ?? ''));
				if ($token === '' || !preg_match(Validator::TOKEN_REGEX, $token))
				{
					continue;
				}
				$markers[] = [
					'token' => $token,
					'mode' => $mode,
					'tagStart' => $tag['start'],
					'tagEnd' => $tag['end'],
				];
			}
		}

		return $markers;
	}

	/**
	 * Ranges of `<form>…</form>` in the order of the document. A form nested into an open one
	 * belongs to the outer range, as the browser ignores it. An unclosed form ends where the browser
	 * ends it: right before the end tag of its parent or of any outer element, else at the end of the
	 * document. So the fields of the form go with it, and the rest of the page stays.
	 * @return list<array{start: int, end: int, line: int}> end is exclusive, line is 1-based
	 */
	public function findForeignForms(string $html): array
	{
		$ranges = [];
		$openTag = null;
		$formDepth = 0;
		$openNames = [];
		// depths of the open elements by name: the nearest one to close is found without a walk over the stack
		$depthsByName = [];
		foreach ($this->walkTags($html) as $tag)
		{
			$name = $tag['name'];
			if (!$tag['closing'])
			{
				if ($name === 'form')
				{
					if ($openTag !== null)
					{
						continue;
					}
					$openTag = $tag;
					$formDepth = count($openNames);
				}
				elseif (in_array($name, self::VOID_TAGS, true))
				{
					continue;
				}
				$depthsByName[$name][] = count($openNames);
				$openNames[] = $name;

				continue;
			}

			$depths = $depthsByName[$name] ?? [];
			if (!$depths)
			{
				continue;
			}
			$depth = $depths[array_key_last($depths)];

			if ($openTag !== null && $depth <= $formDepth)
			{
				$end = $name === 'form' ? $tag['end'] : $tag['start'];
				$ranges[] = $this->buildRange($html, $openTag['start'], $end);
				$openTag = null;
			}

			while (count($openNames) > $depth)
			{
				array_pop($depthsByName[array_pop($openNames)]);
			}
		}

		if ($openTag !== null)
		{
			$ranges[] = $this->buildRange($html, $openTag['start'], strlen($html));
		}

		return $ranges;
	}

	/**
	 * Offset of the first end tag of the element, the start of its "</", outside of comments and raw text.
	 */
	public function findEndTag(string $html, string $name): ?int
	{
		$name = strtolower($name);
		foreach ($this->walkTags($html) as $tag)
		{
			if ($tag['closing'] && $tag['name'] === $name)
			{
				return $tag['start'];
			}
		}

		return null;
	}

	/**
	 * @return array{start: int, end: int, line: int}
	 */
	private function buildRange(string $html, int $start, int $end): array
	{
		return [
			'start' => $start,
			'end' => $end,
			'line' => substr_count($html, "\n", 0, $start) + 1,
		];
	}

	/**
	 * Start and end tags of the markup, outside of comments and of the raw text of
	 * `script`, `style`, `textarea` and `title`. An unclosed comment or raw text element hides the rest.
	 * @return \Generator<array{name: string, closing: bool, start: int, end: int, attributes: array<string, string>}>
	 */
	private function walkTags(string $html): \Generator
	{
		$position = 0;
		while (($open = strpos($html, '<', $position)) !== false)
		{
			if (substr_compare($html, '<!--', $open, 4) === 0)
			{
				// "<!-->" and "<!--->" are complete comments too
				$close = strpos($html, '-->', $open + 2);
				if ($close === false)
				{
					return;
				}
				$position = $close + 3;
				continue;
			}

			$next = $html[$open + 1] ?? '';
			if ($next === '!' || $next === '?')
			{
				$close = strpos($html, '>', $open + 2);
				if ($close === false)
				{
					return;
				}
				$position = $close + 1;
				continue;
			}

			$closing = $next === '/';
			$nameStart = $open + ($closing ? 2 : 1);
			if (!preg_match('#\G[a-zA-Z][^\s/>]*#', $html, $match, 0, $nameStart))
			{
				$position = $open + 1;
				continue;
			}

			$name = strtolower($match[0]);
			[$attributes, $end] = $this->readAttributes($html, $nameStart + strlen($match[0]));

			yield [
				'name' => $name,
				'closing' => $closing,
				'start' => $open,
				'end' => $end,
				'attributes' => $attributes,
			];

			$position = $end;
			if (!$closing && in_array($name, self::RAW_TEXT_TAGS, true))
			{
				if (!preg_match('#</' . $name . '[\s/>]#i', $html, $match, PREG_OFFSET_CAPTURE, $end))
				{
					return;
				}
				$position = $match[0][1];
			}
		}
	}

	/**
	 * Attributes of a tag up to its end. The first occurrence of a repeated attribute wins,
	 * as in the browser. A tag with no closing ">" runs to the end of the content.
	 * @return array{0: array<string, string>, 1: int} the attributes and the offset after the tag
	 */
	private function readAttributes(string $html, int $position): array
	{
		$length = strlen($html);
		$attributes = [];
		while (true)
		{
			$position += strspn($html, self::WHITESPACE . '/', $position);
			if ($position >= $length)
			{
				return [$attributes, $length];
			}
			if ($html[$position] === '>')
			{
				return [$attributes, $position + 1];
			}

			// the first character of a name may be "=", as in the browser
			$nameLength = 1 + strcspn($html, self::WHITESPACE . '/>=', $position + 1);
			$name = strtolower(substr($html, $position, $nameLength));
			$position += $nameLength;
			$position += strspn($html, self::WHITESPACE, $position);

			$value = '';
			if (($html[$position] ?? '') === '=')
			{
				$position++;
				$position += strspn($html, self::WHITESPACE, $position);
				$quote = $html[$position] ?? '';
				if ($quote === '"' || $quote === '\'')
				{
					$close = strpos($html, $quote, $position + 1);
					if ($close === false)
					{
						return [$attributes, $length];
					}
					$value = substr($html, $position + 1, $close - $position - 1);
					$position = $close + 1;
				}
				else
				{
					$valueLength = strcspn($html, self::WHITESPACE . '>', $position);
					$value = substr($html, $position, $valueLength);
					$position += $valueLength;
				}
			}

			$attributes[$name] ??= $value;
		}
	}
}
