<?php

namespace Bitrix\Calendar\Sync\Util;

use Bitrix\Calendar\Core\Event\Event;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\UserField\ResourceBooking;
use Bitrix\Main\Localization\Loc;

IncludeModuleLangFile(__FILE__);

class EventDescription
{
	public function prepareForExport(Event $event): ?string
	{
		$languageId = \CCalendar::getUserLanguageId($event->getOwner()?->getId());

		$description = $event->getDescription() ?? '';
		if ($event->getSpecialLabel() === Dictionary::EVENT_TYPE['booking'])
		{
			$description = $this->addBookingInfo($description, $event, $languageId);
		}
		$description = $this->addAttendeesInfo($description, $event, $languageId);
		$description = $this->addSpecialInfo($description, $event, $languageId);

		return $description;
	}

	/**
	 * @param string $description
	 * @param string $languageId
	 *
	 * @return string
	 */
	public function prepareAfterImport(string $description, string $languageId): string
	{
		$withoutBookingDescription = (new \Bitrix\Calendar\Integration\Booking\EventDescription())
			->clearOutFromDescription($description, $languageId)
		;
		$withoutAttendeesDescription = $this->removeAttendeesInfo($withoutBookingDescription, $languageId);

		return $this->removeSpecialInfo($withoutAttendeesDescription, $languageId);
	}

	/**
	 * @param string $description
	 * @param string $languageId
	 *
	 * @return string
	 */
	private function removeAttendeesInfo(string $description, string $languageId): string
	{
		return (new AttendeesDescription($languageId))
			->cutAttendeesFromDescription($description)
			;
	}

	private function addAttendeesInfo(string $description, Event $event, string $languageId): string
	{
		if (
			$event->getAttendeesCollection()
			&& ($attendees = $event->getAttendeesCollection()->getAttendeesCodes())
			&& count($attendees) > 1
		)
		{
			$description = (new AttendeesDescription($languageId))
				->addAttendeesToDescription($attendees, $description, $event->getParentId())
			;
		}

		return $description;
	}

	private function addBookingInfo(string $description, Event $event, string $languageId): string
	{
		$bookingInfo = (new \Bitrix\Calendar\Integration\Booking\EventDescription())
			->getDescription($event->getId(), $languageId);

		$result = [];
		if ($bookingInfo !== '')
		{
			$result[] = $bookingInfo;
		}

		if ($description !== '')
		{
			$result[] = $description;
		}

		return implode("\r\n\r\n", $result);
	}

	/**
	 * @param string $description
	 * @param Event $event
	 * @param string $languageId
	 *
	 * @return string
	 */
	private function addSpecialInfo(string $description, Event $event, string $languageId): string
	{
		$padding = "\r\n\r\n";
		if (!$event->getDescription())
		{
			$padding = '';
		}

		// temporary this functionality is turned off

		// if ($this->isGuest($event) || $this->isReservation($event))
		// {
		// 	$description .= $padding . Loc::getMessage('CALENDAR_EXPORT_EVENT_LOCK', null, $languageId);
		// }
		// elseif ($this->isEventWithAttendees($event))
		// {
		// 	$description .= $padding . Loc::getMessage('CALENDAR_EXPORT_EVENT_MEETING', null, $languageId);
		// }

		return $description;
	}

	/**
	 * @param Event $event
	 *
	 * @return bool
	 */
	private function isGuest(Event $event): bool
	{
		return $event->getId() !== $event->getParentId();
	}

	/**
	 * @param Event $event
	 *
	 * @return bool
	 */
	private function isReservation(Event $event): bool
	{
		return $event->getSpecialLabel() === ResourceBooking::EVENT_LABEL;
	}

	/**
	 * @param Event $event
	 *
	 * @return bool
	 */
	private function isEventWithAttendees(Event $event): bool
	{
		return $event->getAttendeesCollection() !== null
			&& count($event->getAttendeesCollection()->getAttendeesCodes()) > 1;
	}

	/**
	 * @param string $description
	 * @param string $languageId
	 * @return array|string|string[]
	 */
	private function removeSpecialInfo(string $description, string $languageId)
	{
		return str_replace(
			[
				Loc::getMessage('CALENDAR_EXPORT_EVENT_LOCK', null, $languageId),
				Loc::getMessage('CALENDAR_EXPORT_EVENT_MEETING', null, $languageId)
			],
			'',
			$description
		);
	}
}
