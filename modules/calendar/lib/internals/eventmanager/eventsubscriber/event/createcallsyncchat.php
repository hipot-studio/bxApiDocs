<?php

namespace Bitrix\Calendar\Internals\EventManager\EventSubscriber\Event;

use Bitrix\Calendar\Application\Command\CreateEventCommand;
use Bitrix\Calendar\Application\Command\UpdateEventCommand;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Core\Mappers\Factory;
use Bitrix\Calendar\Event\Event\AfterCalendarEventCreated;
use Bitrix\Calendar\Event\Event\AfterCalendarEventEdited;
use Bitrix\Calendar\Internal\GuestLink\GuestLinkResolver;
use Bitrix\Calendar\Internal\GuestLink\LegacyDescriptionLink;
use Bitrix\Calendar\Internals\EventManager\EventSubscriber\EventSubscriberInterface;
use Bitrix\Calendar\Internals\EventManager\EventSubscriber\EventSubscriberResponseTrait;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;

final class CreateCallSyncChat implements EventSubscriberInterface
{
	use EventSubscriberResponseTrait;
	use CalendarEventSubscriberTrait;

	public function __invoke(Event $event): EventResult
	{
		if (!$this->isAvailable())
		{
			return $this->makeSuccessResponse();
		}

		/** @var CreateEventCommand|UpdateEventCommand $command */
		$command = $event->getParameter('command');
		$calendarEvent = $this->getCalendarEvent($event);
		if (!$calendarEvent || $calendarEvent->getSpecialLabel() !== Dictionary::EVENT_TYPE['call_sync'])
		{
			return $this->makeUndefinedResponse();
		}

		if ($calendarEvent->getMeetingDescription() === null)
		{
			return $this->makeUndefinedResponse();
		}

		$eventId = (int)$calendarEvent->getId();
		$organizerId = $calendarEvent->getEventHost()?->getId() ?? $command->getUserId();

		// Strip any legacy marked link from DESCRIPTION on every save (strip-on-save).
		// Runs before the attendee guards below so a legacy link is removed even when
		// the meeting no longer has external/collab guests (the chat-ensure path does).
		// Cheap precheck on the in-memory description skips the extra getById()+regex
		// for the common case (no legacy marker) - only marked events pay for the strip.
		if (LegacyDescriptionLink::isPresentIn((string)$calendarEvent->getDescription()))
		{
			$this->stripGuestLinkFromDescription($eventId, $organizerId);
		}

		$attendeeCodes = $calendarEvent->getAttendeesCollection()?->getAttendeesCodes() ?? [];
		$attendeeIds = \CCalendar::GetDestinationUsers($attendeeCodes);
		if (empty($attendeeIds))
		{
			return $this->makeUndefinedResponse();
		}

		$hasExternalUser = $this->hasExternalUser($attendeeIds);
		if (!$hasExternalUser)
		{
			return $this->makeSuccessResponse();
		}

		// Ensure the meeting chat exists (pre-feature behavior: unconditional,
		// no guest-link feature gate). The guest link itself is not created here -
		// it is resolved on demand (e.g. while building the invitation email).
		/** @var GuestLinkResolver $resolver */
		$resolver = ServiceLocator::getInstance()->get(GuestLinkResolver::class);
		// $eventId may be an attendee child copy; the resolver normalizes it to the
		// canonical PARENT_ID (getEvent()) so the chat/CHAT_ID always land on the
		// organizer's parent event - the same event web/mobile/email read the link
		// from. Pass the already-hydrated event only when it is itself canonical (no
		// PARENT_ID or self-referential) to skip the extra mapper load; attendee
		// copies are left for the resolver to load and normalize.
		$parentId = (int)($calendarEvent->getParentId() ?? 0);
		$canonicalEvent = ($parentId === 0 || $parentId === $eventId) ? $calendarEvent : null;
		$resolver->ensureChat($eventId, $organizerId, $canonicalEvent);

		return $this->makeSuccessResponse();
	}

	public function getEventClasses(): array
	{
		return [
			AfterCalendarEventCreated::class,
			AfterCalendarEventEdited::class,
		];
	}

	private function isAvailable(): bool
	{
		return Loader::includeModule('intranet') && Loader::includeModule('im');
	}

	private function hasExternalUser(mixed $attendeeIds): bool
	{
		$attendees = \CCalendar::GetUserList($attendeeIds);

		$hasExternalUser = false;
		foreach ($attendees as $attendee)
		{
			if (!empty($attendee['EXTERNAL_AUTH_ID']) || !empty($attendee['COLLAB_USER']))
			{
				$hasExternalUser = true;
				break;
			}
		}

		return $hasExternalUser;
	}

	private function stripGuestLinkFromDescription(int $eventId, int $organizerId): void
	{
		/** @var Factory $mapperFactory */
		$mapperFactory = ServiceLocator::getInstance()->get('calendar.service.mappers.factory');

		$calendarEvent = $mapperFactory->getEvent()->getById($eventId);
		if (!$calendarEvent)
		{
			return;
		}

		$currentDescription = (string)$calendarEvent->getDescription();
		$cleanedDescription = LegacyDescriptionLink::cutFrom($currentDescription);

		if ($cleanedDescription === $currentDescription)
		{
			return;
		}

		$calendarEvent->setDescription($cleanedDescription);

		$mapperFactory->getEvent()->update($calendarEvent, [
			'userId' => $organizerId,
			'overSaving' => true,
			'sendInvitations' => false,
			'sendEditNotification' => false,
		]);
	}
}
