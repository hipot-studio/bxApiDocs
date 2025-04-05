<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Booking;

use Bitrix\Booking\Interfaces\ProviderInterface;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Crm\Activity;

class EventHandler
{
	public static function onGetProviderEventHandler(): ProviderInterface|null
	{
		if (!Loader::includeModule('booking'))
		{
			return null;
		}

		return new Provider();
	}

	public static function onBookingAdd(Event $event): void
	{
		if (!Loader::includeModule('booking'))
		{
			return;
		}

		Activity\Provider\Booking\Booking::onBookingAdded($event->getParameter('booking')->toArray());
	}

	public static function onBookingUpdate(Event $event): void
	{
		if (!Loader::includeModule('booking'))
		{
			return;
		}

		$updatedBooking = $event->getParameter('booking');

		Activity\Provider\Booking\Booking::onBookingUpdated($updatedBooking->toArray());
	}

	public static function onBookingDelete(Event $event): void
	{
		if (!Loader::includeModule('booking'))
		{
			return;
		}

		Activity\Provider\Booking\Booking::onBookingDeleted($event->getParameter('bookingId'));
	}

	public static function onWaitListItemAdd(Event $event): void
	{
		if (!Loader::includeModule('booking'))
		{
			return;
		}

		Activity\Provider\Booking\WaitListItem::onWaitListItemAdded(
			$event->getParameter('waitListItem')->toArray()
		);
	}

	public static function onWaitListItemUpdate(Event $event): void
	{
		if (!Loader::includeModule('booking'))
		{
			return;
		}

		Activity\Provider\Booking\WaitListItem::onWaitListItemUpdated(
			$event->getParameter('waitListItem')->toArray()
		);
	}

	public static function onWaitListItemDelete(Event $event): void
	{
		if (!Loader::includeModule('booking'))
		{
			return;
		}

		Activity\Provider\Booking\WaitListItem::onWaitListItemDeleted(
			$event->getParameter('waitListItem')->toArray(),
			$event->getParameter('removedBy'),
		);
	}
}
