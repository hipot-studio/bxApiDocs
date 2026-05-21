<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

use Bitrix\Booking\Entity\Booking\BookingCollection;
use Bitrix\Booking\Entity\DatePeriod;
use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;
use Bitrix\Booking\Provider\TimeProvider;
use DateTimeImmutable;
use DateInterval;

class ResourceAvailabilityService
{
	public function __construct(
		private readonly BookingRepositoryInterface $bookingRepository,
	)
	{
	}

	public function getAvailableDatePeriodForResourceCollection(
		DateTimeImmutable  $dateFrom,
		ResourceCollection $resourceCollection,
	): array
	{
		foreach ($resourceCollection as $resource)
		{
			$datePeriod = $this->getAvailableDatePeriodForResource($dateFrom, $resource);
			if ($datePeriod)
			{
				return [
					$datePeriod,
					$resource,
				];
			}
		}

		return [null, null];
	}

	public function getAvailableDatePeriodForResource(
		DateTimeImmutable $dateFrom,
		Resource $resource,
	): DatePeriod|null
	{
		foreach ($resource->getSlotRanges() as $slotRange)
		{
			if (!in_array($dateFrom->format('D'), $slotRange->getWeekDays(), true))
			{
				continue;
			}

			$datePeriod = new DatePeriod(
				dateFrom: $dateFrom,
				dateTo: $dateFrom->add(new DateInterval('PT' . $slotRange->getSlotSize() . 'M')),
			);

			if (!$slotRange->makeDatePeriod($dateFrom)->contains($datePeriod))
			{
				continue;
			}

			$bookingCollection = $this->bookingRepository->getList(
				filter: new BookingFilter(
					[
						'RESOURCE_ID' => [
							$resource->getId(),
						],
						'WITHIN' => [
							'DATE_FROM' => $datePeriod->getDateFrom()->getTimestamp(),
							'DATE_TO' => $datePeriod->getDateTo()->getTimestamp(),
						],
					]
				),
				select: (new BookingSelect(['RESOURCES']))->prepareSelect(),
			);

			$isAnyBookingOverlapped = false;
			foreach ($bookingCollection as $booking)
			{
				if ($booking->doEventsIntersect($datePeriod))
				{
					$isAnyBookingOverlapped = true;

					break;
				}
			}
			if ($isAnyBookingOverlapped)
			{
				continue;
			}

			return $datePeriod;
		}

		return null;
	}

	public function getAvailableDatesForResourceCollection(
		DatePeriod $searchPeriod,
		ResourceCollection $resourceCollection,
		int|null $rescheduleBookingId = null,
	): array
	{
		$rescheduleBooking = $rescheduleBookingId ? $this->bookingRepository->getById($rescheduleBookingId) : null;

		$resourcesCollections = [];
		foreach ($resourceCollection as $resource)
		{
			$resourcesCollections[] = new ResourceCollection($resource);
		}

		$foundDatesResponse = (new TimeProvider())->getMultiResourceEachDayFirstOccurrence(
			resourceCollections: $resourcesCollections,
			eventCollection: $this->getBookingsForPeriod(
				$resourceCollection,
				$searchPeriod,
				$rescheduleBooking?->getId()
			),
			searchDates: $searchPeriod->getDateTimeCollection(),
			sizeInMinutes: $rescheduleBooking?->getDatePeriod()?->diffMinutes(),
		);

		return array_map(
			static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'),
			$foundDatesResponse['foundDates']->getItems(),
		);
	}

	private function getBookingsForPeriod(
		ResourceCollection $resourceCollection,
		DatePeriod $searchPeriod,
		int|null $excludeBookingId = null
	): BookingCollection
	{
		$filter = [
			'RESOURCE_ID' => $resourceCollection->getEntityIds(),
			'WITHIN' => [
				'DATE_FROM' => $searchPeriod->getDateFrom()->getTimestamp(),
				'DATE_TO' => $searchPeriod->getDateTo()->getTimestamp(),
			],
		];
		if ($excludeBookingId)
		{
			$filter['!ID'] = $excludeBookingId;
		}

		return $this->bookingRepository->getList(
			filter: new BookingFilter($filter),
			select: (new BookingSelect(['RESOURCES']))->prepareSelect(),
		);
	}

	public function getAvailableSlotsForResourceCollection(
		ResourceCollection $resourceCollection,
		DateTimeImmutable $date,
		int|null $rescheduleBookingId = null,
	): array
	{
		$from = $date->setTime(0, 0);
		$to = $from->add(new DateInterval('P1D'));

		$searchPeriod = new DatePeriod($from, $to);

		$foundSlots = [];
		/** @var Resource $resource */
		foreach ($resourceCollection as $resource)
		{
			$occurrencesDatePeriodCollection = (new TimeProvider())->getOccurrences(
				slotRanges: $resource->getSlotRanges(),
				bookingCollection: $this->getBookingsForPeriod(
					new ResourceCollection($resource),
					$searchPeriod,
					$rescheduleBookingId,
				),
				searchPeriod: $searchPeriod,
				stepSize: 30,
			);

			foreach ($occurrencesDatePeriodCollection as $occurrenceDatePeriod)
			{
				if ($occurrenceDatePeriod->getDateFrom()->getTimestamp() < time())
				{
					continue;
				}

				$foundSlots[$occurrenceDatePeriod->getDateFrom()->format('H:i')] = true;
			}
		}

		$slots = array_keys($foundSlots);
		sort($slots);

		return $slots;
	}

	public function isRescheduleAvailableForBookingResources(
		ResourceCollection $resourceCollection,
		DatePeriod $datePeriod,
		int $bookingId,
	): bool
	{
		/** @var Resource $resource */
		foreach ($resourceCollection as $resource)
		{
			foreach ($resource->getSlotRanges() as $slotRange)
			{
				$dateFrom = $datePeriod->getDateFrom();
				if (!in_array($datePeriod->getDateFrom()->format('D'), $slotRange->getWeekDays(), true))
				{
					continue;
				}

				if (!$slotRange->makeDatePeriod($dateFrom)->contains($datePeriod))
				{
					continue;
				}

				$bookingCollection = $this->bookingRepository->getList(
					filter: new BookingFilter([
						'RESOURCE_ID' => [
							$resource->getId(),
						],
						'WITHIN' => [
							'DATE_FROM' => $datePeriod->getDateFrom()->getTimestamp(),
							'DATE_TO' => $datePeriod->getDateTo()->getTimestamp(),
						],
						'!ID' => $bookingId,
					]),
					select: (new BookingSelect(['RESOURCES']))->prepareSelect(),
				);

				$isAnyBookingOverlapped = false;
				foreach ($bookingCollection as $booking)
				{
					if ($booking->doEventsIntersect($datePeriod))
					{
						$isAnyBookingOverlapped = true;

						break;
					}
				}
				if ($isAnyBookingOverlapped)
				{
					continue;
				}

				return true;
			}
		}

		return false;
	}
}
