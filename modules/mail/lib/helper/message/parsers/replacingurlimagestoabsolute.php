<?php

namespace Bitrix\Mail\Helper\Message\Parsers;

final class ReplacingUrlImagesToAbsolute
{
	// both bounds cap the scan of a single src="" and keep it from exhausting pcre.backtrack_limit:
	// the window covers the url prefix before /bitrix/tools, and every extra digit allowed in the file
	// id makes the engine rerun the tail. A real prefix and a real fileId are far shorter than either
	private const SCAN_WINDOW_MAX = 512;
	private const FILE_ID_DIGITS_MAX = 10;

	// the lookahead keeps a longer id from being matched by its first FILE_ID_DIGITS_MAX digits,
	// which would replace the link of a foreign attachment
	private const PATTERN = '/src="[^"]{0,' . self::SCAN_WINDOW_MAX . '}?\/bitrix\/tools\/crm_show_file\.php'
		. '\?fileId=(\d{1,' . self::FILE_ID_DIGITS_MAX . '})(?!\d)[^"]*+"/i';

	public static function parse(string $body, int $imageId, string $url): string
	{
		$result = preg_replace_callback(
			self::PATTERN,
			function ($matches) use ($imageId, $url)
			{
				if ((int)$matches[1] === $imageId)
				{
					return 'src="' . htmlspecialcharsbx($url) . '"';
				}

				return $matches[0];
			},
			$body,
		);

		// preg_replace_callback returns null on a PCRE error, e.g. the backtrack limit on a long body
		return $result ?? $body;
	}
}