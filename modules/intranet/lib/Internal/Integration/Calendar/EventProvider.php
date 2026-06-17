<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Calendar;

use Bitrix\Calendar\Core\Event\Event;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Core\Mappers\Factory;
use Bitrix\Main\Loader;
use Throwable;

class EventProvider
{
	public function resolveGroupId(array $event): int
	{
		if (!Loader::includeModule('calendar'))
		{
			return 0;
		}

		$eventId = (int)($event['ID'] ?? 0);
		if ($eventId <= 0)
		{
			return 0;
		}

		$calendarEvent = $this->getById($eventId);
		if ($calendarEvent === null)
		{
			return 0;
		}

		$rootEventId = $this->resolveRootEventId($event, $calendarEvent);
		if ($rootEventId <= 0)
		{
			return 0;
		}

		if ($rootEventId !== $eventId)
		{
			$calendarEvent = $this->getById($rootEventId);
			if ($calendarEvent === null)
			{
				return 0;
			}
		}

		return $this->extractGroupIdFromCalendarEvent($calendarEvent);
	}

	private function getById(int $eventId): ?Event
	{
		if ($eventId <= 0)
		{
			return null;
		}

		try
		{
			return (new Factory())->getEvent()->getById($eventId);
		}
		catch (Throwable)
		{
			return null;
		}
	}

	private function resolveRootEventId(array $event, Event $calendarEvent): int
	{
		$eventId = (int)$event['ID'];
		$payloadRecurrenceId = (int)($event['RECURRENCE_ID'] ?? 0);
		if ($payloadRecurrenceId > 0 && $payloadRecurrenceId !== $eventId)
		{
			return $payloadRecurrenceId;
		}

		$payloadParentId = (int)($event['PARENT_ID'] ?? 0);
		if ($payloadParentId > 0 && $payloadParentId !== $eventId)
		{
			return $payloadParentId;
		}

		$calendarRecurrenceId = $calendarEvent->getRecurrenceId() ?? 0;
		if ($calendarRecurrenceId > 0 && $calendarRecurrenceId !== $eventId)
		{
			return $calendarRecurrenceId;
		}

		$calendarParentId = $calendarEvent->getParentId() ?? 0;
		if ($calendarParentId > 0 && $calendarParentId !== $eventId)
		{
			return $calendarParentId;
		}

		return $eventId;
	}

	private function extractGroupIdFromCalendarEvent(Event $calendarEvent): int
	{
		if (!$this->isGroupCalendar((string)$calendarEvent->getCalendarType()))
		{
			return 0;
		}

		return $calendarEvent->getOwner()?->getId() ?? 0;
	}

	private function isGroupCalendar(string $calendarType): bool
	{
		return $calendarType === Dictionary::CALENDAR_TYPE['group'];
	}
}
