<?php

declare(strict_types=1);

namespace Bitrix\Landing\Markup\Submission;

/**
 * The name of a created markup page and of its browser tab: the short `pageTitle` of the package
 * meta. A package without it gets the head of the site title before a separator, so
 * "VEGA — stretch ceilings with a starry sky" names the page "VEGA".
 */
final class PageTitle
{
	private const SEPARATORS = [' — ', ' – ', ' - ', ' | ', ': '];
	private const MIN_HEAD_LENGTH = 2;

	public static function fromMeta(array $meta): string
	{
		$pageTitle = trim((string)($meta['pageTitle'] ?? ''));
		if ($pageTitle !== '')
		{
			return $pageTitle;
		}

		$title = trim((string)($meta['title'] ?? ''));
		$headLength = null;
		foreach (self::SEPARATORS as $separator)
		{
			$position = mb_strpos($title, $separator);
			if ($position !== false && ($headLength === null || $position < $headLength))
			{
				$headLength = $position;
			}
		}

		if ($headLength === null)
		{
			return $title;
		}

		$head = trim(mb_substr($title, 0, $headLength));

		return mb_strlen($head) >= self::MIN_HEAD_LENGTH ? $head : $title;
	}
}
