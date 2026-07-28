<?php

namespace Bitrix\Calendar\Internals\EventManager\EventSubscriber\Event;

use Bitrix\Calendar\Application\Command\CreateEventCommand;
use Bitrix\Calendar\Application\Command\UpdateEventCommand;
use Bitrix\Calendar\Core\Event\Event as CalendarEvent;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Core\Mappers\Factory;
use Bitrix\Calendar\Event\Event\AfterCalendarEventCreated;
use Bitrix\Calendar\Event\Event\AfterCalendarEventEdited;
use Bitrix\Calendar\Internals\EventManager\EventSubscriber\EventSubscriberInterface;
use Bitrix\Calendar\Internals\EventManager\EventSubscriber\EventSubscriberResponseTrait;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Guest\GuestLinkService;
use Bitrix\Im\V2\SharingLink\GuestChatLink;
use Bitrix\Intranet\Secretary;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

final class CreateCallSyncChat implements EventSubscriberInterface
{
	use EventSubscriberResponseTrait;
	use CalendarEventSubscriberTrait;

	private const GUEST_LINK_URL_MARKER = '#cal-sync-guest';

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

		$meetingDescription = $calendarEvent->getMeetingDescription();
		if ($meetingDescription === null)
		{
			return $this->makeUndefinedResponse();
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

		$organizerId = $calendarEvent->getEventHost()?->getId() ?? $command->getUserId();
		$chatId = (int)($meetingDescription->getFields()['CHAT_ID'] ?? 0);

		if (!empty($chatId) && !Chat::getInstance($chatId)->isExist())
		{
			$chatId = 0;
		}

		if (empty($chatId))
		{
			$chatId = Secretary::createCalendarChat(
				[
					'ID' => $calendarEvent->getId(),
					'TITLE' => $calendarEvent->getName() ?? '',
					'DATE_FROM' => $command->getDateFrom(),
					'DT_SKIP_TIME' => $command->isSkipTime() ? 'Y' : 'N',
					'USER_IDS' => $attendeeIds,
					'MEETING' => $meetingDescription->getFields(),
					'CREATED_BY' => $organizerId,
				],
				$organizerId,
			);
		}

		if ($chatId > 0)
		{
			$this->appendGuestLinkToDescription($calendarEvent->getId(), $chatId, $organizerId);
		}

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

	private function appendGuestLinkToDescription(int $eventId, int $chatId, int $organizerId): void
	{
		$chat = Chat::getInstance($chatId);
		if (!$chat->isExist())
		{
			return;
		}

		$linkResult = GuestLinkService::getInstance()->getOrCreateLink(
			chat: $chat,
			authorId: $organizerId
		);

		if (!$linkResult->isSuccess())
		{
			return;
		}

		/** @var GuestChatLink $guestChatLink */
		$guestChatLink = $linkResult->getResult();

		/** @var Factory $mapperFactory */
		$mapperFactory = ServiceLocator::getInstance()->get('calendar.service.mappers.factory');

		/** @var CalendarEvent|null $calendarEvent */
		$calendarEvent = $mapperFactory->getEvent()->getById($eventId);
		if (!$calendarEvent)
		{
			return;
		}

		$currentDescription = (string)$calendarEvent->getDescription();

		$cleanedDescription = $this->cutGuestLinkFromDescription($currentDescription);
		$newDescription = $this->addGuestLinkToDescription(
			$guestChatLink->getInviteUrl(),
			$cleanedDescription,
		);

		if ($newDescription === $currentDescription)
		{
			return;
		}

		$calendarEvent->setDescription($newDescription);

		$mapperFactory->getEvent()->update($calendarEvent, [
			'userId' => $organizerId,
			'overSaving' => true,
			'sendInvitations' => false,
			'sendEditNotification' => false,
		]);
	}

	private function cutGuestLinkFromDescription(string $description): string
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

	private function addGuestLinkToDescription(string $url, string $description): string
	{
		$markedUrl = $url . self::GUEST_LINK_URL_MARKER;

		$linkText = (string)Loc::getMessage(
			'CALENDAR_CALL_SYNC_GUEST_LINK_HINT',
			[
				'#MARKED_URL#' => $markedUrl,
				'#URL#' => $url
			],
		);

		if ($linkText === '')
		{
			return $description;
		}

		$description = trim($description);
		if ($description !== '')
		{
			return $linkText . "\r\n\r\n" . $description;
		}

		return $linkText;
	}
}
