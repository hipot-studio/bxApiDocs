<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\ExternalData\ExternalDataCollection;
use Bitrix\Booking\Entity\ExternalData\ExternalDataItem;
use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Entity\Resource\ResourceLinkedEntityCollection;
use Bitrix\Booking\Internals\Integration\Calendar\CalendarEvent;
use Bitrix\Booking\Internals\Model\Enum\EntityType;
use Bitrix\Booking\Internals\Model\Enum\ResourceLinkedEntityType;
use Bitrix\Booking\Internals\Repository\ORM\BookingExternalDataRepository;

class EventForBookingService
{
	private const CALENDAR_MODULE_ID = 'calendar';
	private const EVENT_ENTITY_TYPE = 'EVENT';

	private CalendarEvent $calendarEventService;

	public function __construct(
		private readonly BookingExternalDataRepository $externalDataRepository,
		CalendarEvent|null $calendarEventService = null,
	)
	{
		$this->calendarEventService = $calendarEventService ?? new CalendarEvent();
	}

	public function onBookingCreated(Booking $booking): void
	{
		if ($this->getLinkedEvent($booking))
		{
			return;
		}

		if ($booking->getClientCollection()->isEmpty())
		{
			return;
		}

		$linkedUserEntities = $this->getLinkedUsers($booking->getResourceCollection());
		if ($linkedUserEntities->isEmpty())
		{
			return;
		}

		$primaryClient = $booking->getClientCollection()->getPrimaryClient();
		if (!$primaryClient)
		{
			return;
		}

		$eventId = $this->calendarEventService->create(
			datePeriod: $booking->getDatePeriod(),
			linkedUserEntities: $linkedUserEntities,
			client: $primaryClient,
		);
		if (!$eventId)
		{
			return;
		}

		$this->linkToBooking($eventId, $booking);
	}

	public function onBookingUpdated(Booking $prevBooking, Booking $currentBooking): void
	{
		$linkedEvent = $this->getLinkedEvent($prevBooking);
		if (!$linkedEvent)
		{
			$this->onBookingCreated($currentBooking);

			return;
		}

		if ($this->isClientsChanged($prevBooking, $currentBooking))
		{
			$this->calendarEventService->delete((int)$linkedEvent->getValue());
			$this->unlinkFromBooking($prevBooking->getId(), $linkedEvent);
			$this->onBookingCreated($currentBooking);

			return;
		}

		$linkedUserEntities = $this->getLinkedUsers($currentBooking->getResourceCollection());
		if ($linkedUserEntities->isEmpty())
		{
			$this->onBookingDeleted($currentBooking);

			return;
		}

		if (!$this->shouldUpdateEvent($prevBooking, $currentBooking))
		{
			return;
		}

		$this->calendarEventService->update(
			(int)$linkedEvent->getValue(),
			$currentBooking->getDatePeriod(),
			$linkedUserEntities,
		);
	}

	public function onBookingDeleted(Booking $booking): void
	{
		$linkedEvent = $this->getLinkedEvent($booking);
		if (!$linkedEvent)
		{
			return;
		}

		$this->calendarEventService->delete((int)$linkedEvent->getValue());
	}

	public function onBookingResourceUpdated(Booking $booking): void
	{
		$linkedEvent = $this->getLinkedEvent($booking);

		if (!$linkedEvent)
		{
			$this->onBookingCreated($booking);

			return;
		}

		$linkedUserEntities = $this->getLinkedUsers($booking->getResourceCollection());
		if ($linkedUserEntities->isEmpty())
		{
			$this->onBookingDeleted($booking);

			return;
		}

		$this->calendarEventService->update(
			(int)$linkedEvent->getValue(),
			$booking->getDatePeriod(),
			$linkedUserEntities,
		);
	}

	private function getLinkedEvent(Booking $booking): ExternalDataItem|null
	{
		return $booking->getExternalDataCollection()
			->getByModuleAndType(self::CALENDAR_MODULE_ID, self::EVENT_ENTITY_TYPE)
			->getFirstCollectionItem()
		;
	}

	private function getLinkedUsers(ResourceCollection $resourceCollection): ResourceLinkedEntityCollection
	{
		$userEntities = [];

		/** @var Resource $resource */
		foreach ($resourceCollection as $resource)
		{
			$linkedEntities = $resource->getEntityCollection();
			foreach ($linkedEntities as $linkedEntity)
			{
				if ($linkedEntity->getEntityType() !== ResourceLinkedEntityType::User)
				{
					continue;
				}
				$userEntities[$linkedEntity->getEntityId()] = $linkedEntity;
			}
		}

		return new ResourceLinkedEntityCollection(...$userEntities);
	}

	private function linkToBooking(int $eventId, Booking $booking): void
	{
		$linkedEvent = new ExternalDataItem();
		$linkedEvent->setModuleId(self::CALENDAR_MODULE_ID);
		$linkedEvent->setEntityTypeId(self::EVENT_ENTITY_TYPE);
		$linkedEvent->setValue((string)$eventId);

		$booking->getExternalDataCollection()->add($linkedEvent);
		$this->externalDataRepository->link(
			entityId: $booking->getId(),
			entityType: EntityType::Booking,
			collection: new ExternalDataCollection($linkedEvent),
		);
	}

	private function unlinkFromBooking(int $bookingId, ExternalDataItem $linkedEvent): void
	{
		$this->externalDataRepository->unLink(
			entityId: $bookingId,
			entityType: EntityType::Booking,
			collection: new ExternalDataCollection($linkedEvent),
		);
	}

	private function shouldUpdateEvent(Booking $prevBooking, Booking $currentBooking): bool
	{
		$prevDatePeriod = $prevBooking->getDatePeriod();
		$currentDatePeriod = $currentBooking->getDatePeriod();

		$isDifferentDate = $prevDatePeriod->getDateFrom()->getTimestamp() !== $currentDatePeriod->getDateFrom()->getTimestamp()
			|| $prevDatePeriod->getDateTo()->getTimestamp() !== $currentDatePeriod->getDateTo()->getTimestamp()
		;

		if ($isDifferentDate)
		{
			return true;
		}

		return !$this->getLinkedUsers($prevBooking->getResourceCollection())
			->isEqual($this->getLinkedUsers($currentBooking->getResourceCollection()))
		;
	}

	private function isClientsChanged(Booking $prevBooking, Booking $currentBooking): bool
	{
		return !$prevBooking->getClientCollection()
			->diff($currentBooking->getClientCollection())
			->isEmpty()
		;
	}
}
