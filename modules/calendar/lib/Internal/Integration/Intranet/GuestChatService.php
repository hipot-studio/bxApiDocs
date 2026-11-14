<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Integration\Intranet;

use Bitrix\Intranet\Secretary;
use Bitrix\Main\Loader;

/**
 * Calendar-side wrapper around the intranet secretary meeting chat API.
 */
class GuestChatService
{
	public function isAvailable(): bool
	{
		return Loader::includeModule('intranet');
	}

	/**
	 * Creates a meeting chat for the event and returns the chat id (0 on failure).
	 */
	public function createCalendarChat(
		array $eventFields,
		int $organizerId,
		array $parentEventFields = [],
	): int
	{
		if (!$this->isAvailable())
		{
			return 0;
		}

		return Secretary::createCalendarChat($eventFields, $organizerId, $parentEventFields);
	}
}
