<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Booking;

use Bitrix\Crm\Dto\Booking\Booking\BookingFieldsMapper;
use Bitrix\Crm\Dto\Booking\Booking\BookingStatusEnum;
use Bitrix\Crm\Dto\Booking\WaitListItem\WaitListItemFieldsMapper;
use Bitrix\Crm\Timeline\Booking\Controller;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Crm\Activity;

class EventHandler
{
	public static function onBookingAdd(Event $event): void
	{
		if (!Loader::includeModule('booking'))
		{
			return;
		}

		$bookingFields = BookingFieldsMapper::mapFromBookingArray(
			booking: $event->getParameter('booking')->toArray(),
			isOverbooking: $event->getParameter('isOverbooking') ?? false,
		);
		Activity\Provider\Booking\Booking::onBookingAdded($bookingFields);
	}

	public static function onBookingUpdate(Event $event): void
	{
		if (!Loader::includeModule('booking'))
		{
			return;
		}

		$bookingFields = BookingFieldsMapper::mapFromBookingArray(
			booking: $event->getParameter('booking')->toArray(),
			isOverbooking: $event->getParameter('isOverbooking') ?? false,
		);
		Activity\Provider\Booking\Booking::onBookingUpdated($bookingFields);
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

		$waitListItemFields = WaitListItemFieldsMapper::mapFromWaitListItemArray(
			$event->getParameter('waitListItem')->toArray(),
		);

		Activity\Provider\Booking\WaitListItem::onWaitListItemAdded($waitListItemFields);
	}

	public static function onWaitListItemUpdate(Event $event): void
	{
		if (!Loader::includeModule('booking'))
		{
			return;
		}

		$waitListItemFields = WaitListItemFieldsMapper::mapFromWaitListItemArray(
			$event->getParameter('waitListItem')->toArray(),
		);

		Activity\Provider\Booking\WaitListItem::onWaitListItemUpdated($waitListItemFields);
	}

	public static function onWaitListItemDelete(Event $event): void
	{
		if (!Loader::includeModule('booking'))
		{
			return;
		}

		$waitListItemFields = WaitListItemFieldsMapper::mapFromWaitListItemArray(
			$event->getParameter('waitListItem')->toArray(),
		);

		Activity\Provider\Booking\WaitListItem::onWaitListItemDeleted(
			$waitListItemFields,
			$event->getParameter('removedBy'),
		);
	}

	public static function onBookingStatusUpdated(Event $event): void
	{
		if (!Loader::includeModule('booking'))
		{
			return;
		}

		$bookingData = $event->getParameter('booking')?->toArray();
		if (!$bookingData)
		{
			return;
		}

		$status = BookingStatusEnum::tryFrom($event->getParameter('status'));
		if (!$status)
		{
			return;
		}

		$bookingFields = BookingFieldsMapper::mapFromBookingArray(booking: $bookingData);

		if ($status->supportLogMessage())
		{
			(new Controller())->onBookingStatusUpdated(
				booking: $bookingFields,
				status: $status,
			);
		}

		if ($status->supportActivityStatusUpdate())
		{
			Activity\Provider\Booking\Booking::onBookingStatusUpdated(
				booking: $bookingFields,
				status: $status,
			);
		}

		if ($status->supportToDoActivity())
		{
			(new Activity\Provider\Booking\BookingToDo())->createForBooking($bookingFields, $status);
		}
	}
}
