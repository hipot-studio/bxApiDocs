<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Transcription\Util;

final class EmotionParser
{
	public static function stripEmotionBlocks(string $text): string
	{
		$matches = self::collectEmotionMatches($text);

		for ($i = count($matches) - 1; $i >= 0; $i--)
		{
			$bbCodeBlock = $matches[$i];

			$text =
				mb_substr($text, 0, $bbCodeBlock['start'], 'UTF-8')
				. mb_substr($text, $bbCodeBlock['end'], null, 'UTF-8')
			;
		}

		return self::clearText($text);
	}

	private static function collectEmotionMatches(string $text): array
	{
		$openPattern = '(?:\s*\[(?:color=[^\]]+|i|size=[^\]]+)\]\s*)+';
		$labelPattern = '(?P<label>[^\[\]\r\n]{1,128}?)';
		$closePattern = '(?:\s*\[(?:\/(?:color|i|size))\]\s*)+';

		$pattern = '/(?P<full>' . $openPattern . $labelPattern . $closePattern . ')/isu';

		$found = [];
		if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0)
		{
			foreach ($matches as $bbCodeBlock)
			{
				$full = $bbCodeBlock['full'][0];
				$startByte = (int)$bbCodeBlock['full'][1];
				$label = $bbCodeBlock['label'][0];
				$startChar = mb_strlen(substr($text, 0, $startByte), 'UTF-8');
				$fullCharLen = mb_strlen($full, 'UTF-8');
				$endChar = $startChar + $fullCharLen;

				$found[] = [
					'start' => $startChar,
					'end' => $endChar,
					'full' => $full,
					'label' => $label,
				];
			}
		}

		usort($found, static fn(array $a, array $b) => $a['start'] <=> $b['start']);

		return $found;
	}

	private static function clearText(string $text): string
	{
		$text = preg_replace('/\[(?:br)\s*\/?]/i', "\n", $text) ?? $text;
		$text = preg_replace('/\[(?!br\b)(\/)?[a-z]+(?:=[^\]]+)?\]/i', '', $text) ?? $text;
		$text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
		$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

		return trim($text);
	}
}