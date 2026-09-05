<?php

declare(strict_types=1);

namespace Bitrix\Landing\Security;

/**
 * URL-security helpers for the #system_* page addresses that reach block content
 * Block::view() eval()s at public render (Mantis 253773): the write-path check of a
 * page/site/folder CODE and the render-side neutralization of the resulting URL.
 */
final class SyspageUrl
{
	/**
	 * Rejects a page/site/folder CODE carrying characters able to break out of a PHP string
	 * literal or an HTML context once the CODE is substituted into a #system_* URL and the block
	 * content is eval()'d at public render (Mantis 253773). A URL slug never legitimately holds
	 * any of these, so this is a denylist of quote/interpolation/tag/call metacharacters, not an
	 * allow-list (which would drop legitimate non-ASCII slugs). Byte-wise on purpose: every
	 * forbidden character is ASCII and strpbrk needs no unicode modifier that could fail open on
	 * invalid UTF-8. Any control byte is rejected too - a URL slug never holds one, and a raw \r\n
	 * would be CRLF/header injection for the adjacent Location/localRedirect sinks that also consume
	 * getPublicUrl.
	 */
	public static function isSafeCode(string $code): bool
	{
		return strpbrk($code, '\'"`$\\<>(){}') === false
			&& preg_match('/[\x00-\x1F\x7F]/', $code) === 0;
	}

	/**
	 * Neutralizes a system-page URL before it is inlined into block content that Block::view()
	 * eval()s at public render (Mantis 253773). htmlspecialcharsbx already encodes the HTML
	 * metacharacters (< > & "); a URL is also inlined into single- and double-quoted PHP string
	 * literals, so the remaining string-breakout / interpolation bytes ' $ ` \ are percent-encoded
	 * too. A real system-page URL holds none of these, so a legitimate URL is returned unchanged.
	 * This is the render-side backstop for CODE values already stored before write-path validation.
	 */
	public static function sanitize(string $url): string
	{
		return strtr(
			\htmlspecialcharsbx($url),
			[
				"'" => '%27',
				'$' => '%24',
				'`' => '%60',
				'\\' => '%5C',
			]
		);
	}
}
