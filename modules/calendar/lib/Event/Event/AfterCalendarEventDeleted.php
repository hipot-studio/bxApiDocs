<?php

namespace Bitrix\Calendar\Event\Event;

use Bitrix\Calendar\Event\Enum\EventType;
use Bitrix\Calendar\Internals\EventManager\BaseEvent;
use Bitrix\Calendar\Internals\EventManager\EventSubscriber;

final class AfterCalendarEventDeleted extends BaseEvent
{
	public function __construct(
		private readonly int $calendarEventId,
		private readonly int $seriesRootId = 0,
		private readonly int $creatorId = 0,
		private readonly int $ownerId = 0,
		private readonly int $chatId = 0,
	)
	{
	}

	public static function getEventType(): string
	{
		return EventType::AFTER_CALENDAR_EVENT_DELETED;
	}

	protected function getEventParams(): array
	{
		return [
			'eventId' => $this->calendarEventId,
			'seriesRootId' => $this->seriesRootId,
			'creatorId' => $this->creatorId,
			'ownerId' => $this->ownerId,
			'chatId' => $this->chatId,
		];
	}

	protected function getSubscribers(): array
	{
		return [
			new EventSubscriber\Event\CheckIsOpenEvent(),
			new EventSubscriber\Event\RevokeGuestLinkAfterDelete(),
		];
	}
}
