<?php

namespace Bitrix\Mail\Helper\Message\Parsers;

final class CharsetCleaner
{
	// bounds keep a body with no closing '>' from costing the whole pcre.backtrack_limit, and each
	// sits far above real markup: 'text/html; ' precedes the charset, the longest IANA charset name
	// is 44 characters, and a real <meta> carries only a handful of short attributes after it
	private const CONTENT_PREFIX_MAX = 256;
	private const CHARSET_VALUE_MAX = 256;
	private const META_TAIL_MAX = 512;

	private const PATTERNS = [
		'/<meta\s+http-equiv\s*=\s*["\']?Content-Type["\']?\s+content\s*=\s*["\']?'
			. '[^"\']{0,' . self::CONTENT_PREFIX_MAX . '}charset\s*=\s*'
			. '[^"\'>]{1,' . self::CHARSET_VALUE_MAX . '}+["\']?[^>]{0,' . self::META_TAIL_MAX . '}+>/i',
		'/<meta\s+charset\s*=\s*["\']\s*[^"\']{1,' . self::CHARSET_VALUE_MAX . '}+\s*["\']'
			. '[^>]{0,' . self::META_TAIL_MAX . '}+>/i',
	];

	public static function parse(string $body): string
	{
		// preg_replace returns null on a PCRE error, e.g. the backtrack limit on a long body
		return preg_replace(self::PATTERNS, '', $body) ?? $body;
	}
}