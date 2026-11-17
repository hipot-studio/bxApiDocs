<?php

declare(strict_types=1);

namespace Bitrix\Landing\Security;

/**
 * URL-security helpers for the #system_* page addresses that reach block content:
 * the write-path check of a page/site/folder CODE and the render-side neutralization of the resulting URL.
 */
final class SyspageUrl
{
	/**
	 * Rejects a page/site/folder CODE holding a quote, interpolation, tag or call character or a control byte:
	 * a URL slug never legitimately holds any of these. This is a denylist, not an allow-list, to keep
	 * non-ASCII slugs, and it is byte-wise on purpose: every forbidden character is ASCII, so invalid UTF-8
	 * cannot make the check pass. Control bytes are rejected also because getPublicUrl feeds redirects.
	 */
	public static function isSafeCode(string $code): bool
	{
		return strpbrk($code, '\'"`$\\<>(){}') === false
			&& preg_match('/[\x00-\x1F\x7F]/', $code) === 0;
	}

	/**
	 * Neutralizes a system-page URL before it is put into block content: the HTML metacharacters (< > & ")
	 * become entities and the bytes ' $ ` \ are percent-encoded. A real system-page URL holds none of these
	 * bytes, so it keeps its value. This also covers CODE values stored before the write-path check.
	 */
	public static function sanitize(string $url): string
	{
		return strtr(
			\htmlspecialcharsbx($url, ENT_COMPAT | ENT_SUBSTITUTE),
			[
				"'" => '%27',
				'$' => '%24',
				'`' => '%60',
				'\\' => '%5C',
			]
		);
	}

	/**
	 * Replaces markers with their values, like preg_replace() over array_keys() and array_values(),
	 * and prints a less-than sign standing right before a marker as an entity.
	 *
	 * If a regular expression fails, the content is kept as it was before that step.
	 *
	 * @param string $content Content of the block.
	 * @param array $markers Regular expression of a marker => replacement, group references work in it.
	 * @return string
	 */
	public static function substitute(string $content, array $markers): string
	{
		foreach ($markers as $pattern => $replacement)
		{
			$pattern = (string)$pattern;
			$delimiter = $pattern[0];
			$bodyEnd = strrpos($pattern, $delimiter);

			// One marker at a time, guarded by SyspageUrlSubstitutionTest.
			$lessThanPattern = $delimiter . '<(?=' . substr($pattern, 1, $bodyEnd - 1) . ')'
				. substr($pattern, $bodyEnd);
			$neutralized = preg_replace($lessThanPattern, '&lt;', $content);
			if ($neutralized === null)
			{
				continue;
			}

			$content = preg_replace($pattern, (string)$replacement, $neutralized) ?? $neutralized;
		}

		return $content;
	}
}
