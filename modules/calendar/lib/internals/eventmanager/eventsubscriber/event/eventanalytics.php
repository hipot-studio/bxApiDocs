<?php

namespace Bitrix\Calendar\Internals\EventManager\EventSubscriber\Event;

use Bitrix\Calendar\Application\Command\CreateEventCommand;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Event\Event\AfterCalendarEventCreated;
use Bitrix\Calendar\Internal\Integration\Socialnetwork\CollabService;
use Bitrix\Calendar\Internals\EventManager\EventSubscriber\EventSubscriberInterface;
use Bitrix\Calendar\Internals\EventManager\EventSubscriber\EventSubscriberResponseTrait;
use Bitrix\Calendar\Internals\Helper\Analytics;
use Bitrix\Calendar\Util;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class EventAnalytics implements EventSubscriberInterface
{
	use EventSubscriberResponseTrait;
	use CalendarEventSubscriberTrait;

	/**
	 * @param Event $event
	 *
	 * @return EventResult
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public function __invoke(Event $event): EventResult
	{
		$calendarEvent = $this->getCalendarEvent($event);
		if (!$calendarEvent)
		{
			return $this->makeUndefinedResponse();
		}

		$section = $calendarEvent->getSection();
		$collabId = $section?->getOwner()?->getId();
		$isGroupSection = $section?->getType() === Dictionary::CALENDAR_TYPE['group'];

		$isSharingCollab = $calendarEvent->getSpecialLabel() === Dictionary::EVENT_TYPE['shared_collab'];
		$isCollabProjectEvent = $isGroupSection
			&& $collabId
			&& ServiceLocator::getInstance()->get(CollabService::class)->hasCollabers((int)$collabId)
		;

		if (!$isSharingCollab && !$isCollabProjectEvent)
		{
			return $this->makeSuccessResponse();
		}

		/** @var CreateEventCommand $command */
		$command = $event->getParameter('command');
		$userType = Analytics::USER_TYPES['intranet'];
		if (Util::isCollabUser(\CCalendar::getCurUserId()))
		{
			$userType = Analytics::USER_TYPES['collaber'];
		}

		Analytics::getInstance()->onEventCreate(
			section: Analytics::SECTION['collab'],
			subSection: $command->getAnalyticsSubSection(),
			userType: $userType,
			collabId: $collabId,
			chatId: $command->getAnalyticsChatId(),
		);

		return $this->makeSuccessResponse();
	}

	public function getEventClasses(): array
	{
		return [
			AfterCalendarEventCreated::class,
		];
	}
}
