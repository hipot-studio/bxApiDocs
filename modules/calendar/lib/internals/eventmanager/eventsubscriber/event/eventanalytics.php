<?php

namespace Bitrix\Calendar\Internals\EventManager\EventSubscriber\Event;

use Bitrix\Calendar\Application\Command\CreateEventCommand;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Event\Event\AfterCalendarEventCreated;
use Bitrix\Calendar\Internals\EventManager\EventSubscriber\EventSubscriberInterface;
use Bitrix\Calendar\Internals\EventManager\EventSubscriber\EventSubscriberResponseTrait;
use Bitrix\Calendar\Internals\Helper\Analytics;
use Bitrix\Calendar\Util;
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
		$isGroupSection = $section?->getType() === Dictionary::CALENDAR_TYPE['group'];
		$groupId = $isGroupSection ? $section?->getOwnerId() : null;

		$isGroupEvent = $isGroupSection && $groupId;

		/** @var CreateEventCommand $command */
		$command = $event->getParameter('command');

		if ($isGroupEvent)
		{
			$type = Analytics::EVENT_TYPE['group'];
			$sectionKey = Analytics::SECTION['project'];
			$subSection = $command->getAnalyticsSubSection() ?? Analytics::SUB_SECTION['calendar_project'];
		}
		else
		{
			$type = Analytics::EVENT_TYPE['single'];
			$sectionKey = Analytics::SECTION['calendar'];
			$subSection = $command->getAnalyticsSubSection() ?? Analytics::SUB_SECTION['calendar_personal'];
		}

		$userId = \CCalendar::getCurUserId();
		$userType = match (true)
		{
			Util::isCollabUser($userId) => Analytics::USER_TYPES['collaber'],
			Util::isExtranetUser($userId) => Analytics::USER_TYPES['extranet'],
			default => Analytics::USER_TYPES['intranet'],
		};

		Analytics::getInstance()->onEventCreate(
			type: $type,
			section: $sectionKey,
			subSection: $subSection,
			userType: $userType,
			groupId: $groupId,
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
