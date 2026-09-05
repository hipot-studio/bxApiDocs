<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Message;

class QuoteTrimmer
{
	public static function stripQuotedPlain(string $plain): string
	{
		if ($plain === '')
		{
			return $plain;
		}

		$body = str_replace("\r\n", "\n", $plain);

		$cutPos = self::firstPlainQuotePosition($body);
		if ($cutPos === null)
		{
			return $plain;
		}

		$before = rtrim(substr($body, 0, $cutPos));

		return $before === '' ? $plain : $before;
	}

	private static function firstPlainQuotePosition(string $body): ?int
	{
		if (
			preg_match('/(?:^>.*$\n?){2,}/m', $body, $matches, PREG_OFFSET_CAPTURE) === 1
			&& self::isTailAllQuotedOrBlank($body, (int)$matches[0][1])
		)
		{
			return (int)$matches[0][1];
		}

		return null;
	}

	private static function isTailAllQuotedOrBlank(string $body, int $offset): bool
	{
		$tail = substr($body, $offset);
		foreach (explode("\n", $tail) as $line)
		{
			$trimmed = ltrim($line);
			if ($trimmed === '')
			{
				continue;
			}

			if ($trimmed[0] !== '>')
			{
				return false;
			}
		}

		return true;
	}
}
