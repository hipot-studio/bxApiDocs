<?php

namespace Bitrix\Mail\Helper\Message\Parsers;

final class CharsetCleaner
{
	public static function parse(string $body): string
	{
		return preg_replace(
			[
				'/<meta\s+http-equiv\s*=\s*["\']Content-Type["\']\s+content\s*=\s*["\'][^"]*charset\s*=\s*[^"\']+[^>]*>/i',
				'/<meta\s+charset\s*=\s*["\']\s*[^"\']+\s*["\'][^>]*>/i',
			],
			'',
			$body
		);
	}
}