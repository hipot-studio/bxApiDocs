<?php
declare(strict_types=1);

namespace Bitrix\Im\Integration\Bizproc;

use Bitrix\Im\V2\Message;

class Splitter
{
	private const PAIRED_TAGS = [
		'b',
		'i',
		'u',
		's',
		'url',
		'color',
		'size',
		'quote',
		'code',
		'list',
		'table',
	];

	private const CUT_ATTEMPTS = 3;

	/**
	 * @return list<string>
	 */
	public static function split(string $text): array
	{
		$limit = Message::MESSAGE_MAX_LENGTH;
		$parts = [];
		$rest = $text;

		while ($rest !== '')
		{
			if (mb_strlen($rest) <= $limit)
			{
				$parts[] = $rest;

				break;
			}

			[$cut, $openedTags] = self::findCut($rest, $limit);

			$chunk = mb_substr($rest, 0, $cut);
			$rest = mb_substr($rest, $cut);

			if ($openedTags === [])
			{
				$chunk = trim($chunk) !== '' ? trim($chunk) : $chunk;
				$rest = ltrim($rest);
			}
			else
			{
				$chunk .= self::closingTags($openedTags);
				$rest = self::openingTags($openedTags) . $rest;
			}

			$parts[] = $chunk;
		}

		return $parts;
	}

	/**
	 * @return array{0: int, 1: list<array{tag: string, raw: string, position: int}>}
	 */
	private static function findCut(string $text, int $limit): array
	{
		$reserve = 0;

		for ($attempt = 0; $attempt < self::CUT_ATTEMPTS; $attempt++)
		{
			$width = $limit - $reserve;
			if ($width <= 0)
			{
				break;
			}

			$cut = self::findBoundary(mb_substr($text, 0, $width), $width);
			$openedTags = self::findUnclosedPairedTags(mb_substr($text, 0, $cut));

			if ($openedTags === [])
			{
				return [$cut, []];
			}

			if ($openedTags[0]['position'] > 0)
			{
				return [$openedTags[0]['position'], []];
			}

			$closingLength = mb_strlen(self::closingTags($openedTags));
			if ($closingLength <= $reserve)
			{
				return [$cut, $openedTags];
			}

			$reserve = $closingLength;
		}

		return [$limit, []];
	}

	private static function findBoundary(string $window, int $fallback): int
	{
		$cut = mb_strrpos($window, "\n\n");
		if ($cut === false)
		{
			$cut = mb_strrpos($window, "\n");
		}
		if ($cut === false)
		{
			$cut = mb_strrpos($window, ' ');
		}

		return $cut === false || $cut === 0 ? $fallback : $cut;
	}

	/**
	 * @return list<array{tag: string, raw: string, position: int}>
	 */
	private static function findUnclosedPairedTags(string $fragment): array
	{
		$pattern = '/\[(\/?)(' . implode('|', self::PAIRED_TAGS) . ')(?:=[^\]]*)?\]/i';
		if (!preg_match_all($pattern, $fragment, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER))
		{
			return [];
		}

		$openedTags = [];
		foreach ($matches as $match)
		{
			$tag = mb_strtolower($match[2][0]);

			if ($match[1][0] === '/')
			{
				if ($openedTags !== [] && end($openedTags)['tag'] === $tag)
				{
					array_pop($openedTags);
				}

				continue;
			}

			$openedTags[] = [
				'tag' => $tag,
				'raw' => $match[0][0],
				'position' => mb_strlen(substr($fragment, 0, $match[0][1])),
			];
		}

		return array_values($openedTags);
	}

	/**
	 * @param list<array{tag: string, raw: string, position: int}> $openedTags
	 */
	private static function closingTags(array $openedTags): string
	{
		$tags = '';
		foreach (array_reverse($openedTags) as $openedTag)
		{
			$tags .= '[/' . $openedTag['tag'] . ']';
		}

		return $tags;
	}

	/**
	 * @param list<array{tag: string, raw: string, position: int}> $openedTags
	 */
	private static function openingTags(array $openedTags): string
	{
		$tags = '';
		foreach ($openedTags as $openedTag)
		{
			$tags .= $openedTag['raw'];
		}

		return $tags;
	}
}
