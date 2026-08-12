<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Integration\Booking;

use Bitrix\Main\Loader;

class EventDescription
{
	public function getDescription(int $eventId, string $languageId): string
	{
		if (!$this->isAvailable())
		{
			return '';
		}

		return (new \Bitrix\Booking\Service\EventDescription($languageId))->getDescription($eventId);
	}

	public function clearOutFromDescription(string $description, string $languageId): string
	{
		if (!$this->isAvailable())
		{
			return $description;
		}

		return (new \Bitrix\Booking\Service\EventDescription($languageId))->clearOutFromDescription($description);
	}

	private function isAvailable(): bool
	{
		return Loader::includeModule('booking');
	}
}
