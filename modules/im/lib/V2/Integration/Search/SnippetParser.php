<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search;

use Bitrix\Im\V2\Integration\Search\Result\SnippetSegment;

/**
 * Cuts the ready BODY_FORMATED string of a search result row into segments.
 *
 * The markers are safe to split on: CSearch::PrepareSearchResult() puts them in after the text has
 * been escaped, so a literal <b> of the message is already &lt;b&gt; by then and cannot be mistaken
 * for a marker of the highlighting.
 */
class SnippetParser
{
	private const OPEN_MARKER = '<b>';
	private const CLOSE_MARKER = '</b>';

	/**
	 * @return SnippetSegment[]
	 */
	public function parse(string $bodyFormated): array
	{
		if ($bodyFormated === '')
		{
			return [];
		}

		$segments = [];
		$highlighted = false;

		foreach ($this->splitByMarkers($bodyFormated) as $part)
		{
			if ($part === self::OPEN_MARKER)
			{
				$highlighted = true;

				continue;
			}

			if ($part === self::CLOSE_MARKER)
			{
				$highlighted = false;

				continue;
			}

			$text = htmlspecialcharsback($part);
			if ($text === '')
			{
				continue;
			}

			$segments[] = new SnippetSegment($text, $highlighted);
		}

		return $segments;
	}

	/**
	 * @return string[] Parts of the string with the markers kept as parts of their own.
	 */
	private function splitByMarkers(string $bodyFormated): array
	{
		$parts = preg_split(
			'#(' . self::OPEN_MARKER . '|' . self::CLOSE_MARKER . ')#',
			$bodyFormated,
			-1,
			PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
		);

		return $parts === false ? [$bodyFormated] : $parts;
	}
}
