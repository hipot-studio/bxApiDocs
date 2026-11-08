<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im;

final class BbCodeSanitizer
{
	private const MAX_PASSES = 8;
	private const TAG_PATTERN = '/\[\/?(?:ATTACH|B|CALL|CHAT|CODE|COLOR|CONTEXT|DIALOG|DISK|DISLIKE'
		. '|H1|H2|I|ICODE|ICON|IMG|LIKE|LIST|PCH|PUT|RATING|S|SEND|SIZE|SOURCE'
		. '|TABLE|TD|TH|TIMESTAMP|TR|U|URL|USER)(?:[ =][^\[\]]*)?\]/i';
	private const LINE_BREAK_PATTERN = '/\[\/?(?:BR|HR)\]/i';

	public function sanitize(string $text): string
	{
		for ($pass = 0; $pass < self::MAX_PASSES; $pass++)
		{
			$previousText = $text;
			$text = (string)preg_replace(self::LINE_BREAK_PATTERN, "\n", $text);
			$text = (string)preg_replace(self::TAG_PATTERN, '', $text);
			if ($text === $previousText)
			{
				return $text;
			}
		}

		return str_replace('[', '&#91;', $text);
	}
}
