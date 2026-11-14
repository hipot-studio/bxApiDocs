<?php

namespace Bitrix\Calendar\Sharing\JoinLink;

use Bitrix\Main\Localization\Loc;

/**
 * Formats the calendar guest-link line inside an event DESCRIPTION.
 *
 * Pure formatting: removes a previously inserted marked line, prepends the
 * localized guest-link line and keeps the rest of the description below. The
 * marker is specific to the calendar guest link, so it never matches an
 * arbitrary user URL. Knows nothing about IM, EventLink or controllers.
 */
final class EventDescriptionLinkFormatter
{
	private const GUEST_LINK_URL_MARKER = '#cal-sharing-guest';
	private const MESSAGE_KEY = 'CALENDAR_SHARING_GUEST_LINK_HINT';

	public function format(string $url, string $description): string
	{
		$cleaned = $this->cutGuestLink($description);

		return $this->prependGuestLink($url, $cleaned);
	}

	private function cutGuestLink(string $description): string
	{
		if ($description === '')
		{
			return '';
		}

		$marker = preg_quote(self::GUEST_LINK_URL_MARKER, '|');
		$pattern = '|^[^\r\n]*\[URL=[^]]*' . $marker . '[^]]*].+?\[/URL][\r\n]*|ims';
		$cleaned = preg_replace($pattern, '', $description);
		if ($cleaned === null)
		{
			return $description;
		}

		return ltrim($cleaned);
	}

	private function prependGuestLink(string $url, string $description): string
	{
		$markedUrl = $url . self::GUEST_LINK_URL_MARKER;

		$line = (string)Loc::getMessage(
			self::MESSAGE_KEY,
			[
				'#MARKED_URL#' => $markedUrl,
				'#URL#' => $url,
			],
		);

		if ($line === '')
		{
			return $description;
		}

		$description = trim($description);
		if ($description !== '')
		{
			return $line . "\r\n\r\n" . $description;
		}

		return $line;
	}
}
