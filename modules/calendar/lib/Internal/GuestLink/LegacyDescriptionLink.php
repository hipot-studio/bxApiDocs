<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\GuestLink;

/**
 * The legacy guest link the call_sync flow used to write into the event DESCRIPTION before the
 * feature moved the link to a requisite of its own.
 *
 * Sole owner of the marker and of the pattern that cuts the marked line out. The same string
 * surgery is needed in three unrelated places - the strip-on-save subscriber, the one-shot
 * scrubber and the description channels that strip the line in memory - and a legacy format
 * kept in three copies drifts apart on the first change.
 */
final class LegacyDescriptionLink
{
	/** Marker that identifies a legacy guest link inside DESCRIPTION. */
	public const MARKER = '#cal-sync-guest';

	/**
	 * Cheap precheck for callers that would otherwise pay for a mapper load or a regex on a
	 * description that carries no legacy link at all - the common case.
	 */
	public static function isPresentIn(string $description): bool
	{
		return str_contains($description, self::MARKER);
	}

	/**
	 * Returns the description without the marked link line, and the input unchanged when there
	 * is nothing to cut - callers compare the two to learn whether anything changed.
	 */
	public static function cutFrom(string $description): string
	{
		if ($description === '')
		{
			return '';
		}

		$marker = preg_quote(self::MARKER, '|');
		$pattern = '|^[^\r\n]*\[URL=[^]\r\n]*' . $marker . '[^]\r\n]*][^\r\n]+?\[/URL][\r\n]*|im';
		$cleaned = preg_replace($pattern, '', $description);
		if ($cleaned === null)
		{
			return $description;
		}

		return $cleaned;
	}
}
