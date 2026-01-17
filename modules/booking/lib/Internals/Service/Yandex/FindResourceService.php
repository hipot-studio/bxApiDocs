<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\DatePeriod;
use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Internals\Exception\Yandex\ResourceNotFoundException;
use Bitrix\Booking\Internals\Exception\Yandex\SlotUnavailableException;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;
use Bitrix\Booking\Provider\Params\Resource\ResourceFilter;
use Bitrix\Booking\Provider\Params\Resource\ResourceSelect;
use DateTimeImmutable;
use DateInterval;

class FindResourceService
{
	public function __construct(
		private readonly BookingRepositoryInterface $bookingRepository,
		private readonly ResourceRepositoryInterface $resourceRepository,
	)
	{
	}

	/**
	 * @throws ResourceNotFoundException
	 * @throws SlotUnavailableException
	 */
	public function findResource(
		array $resourceFilter,
		DateTimeImmutable $dateFrom,
		Booking|null $existingBooking = null,
	): FindResourceServiceResult
	{
		$resourceCollection = $this->resourceRepository->getList(
			filter: new ResourceFilter($resourceFilter),
			select: (new ResourceSelect(['SETTINGS']))->prepareSelect(),
		);

		if ($resourceCollection->isEmpty())
		{
			throw new ResourceNotFoundException();
		}

		/** @var Resource $resource */
		foreach ($resourceCollection as $resource)
		{
			foreach ($resource->getSlotRanges() as $slotRange)
			{
				if (!in_array($dateFrom->format('D'), $slotRange->getWeekDays(), true))
				{
					continue;
				}

				$durationMinutes = $existingBooking
					? $existingBooking->getDatePeriod()->diffMinutes()
					: $slotRange->getSlotSize()
				;
				$datePeriod = new DatePeriod(
					dateFrom: $dateFrom,
					dateTo: $dateFrom->add(new DateInterval('PT' . $durationMinutes . 'M')),
				);

				if (!$slotRange->makeDatePeriod($dateFrom)->contains($datePeriod))
				{
					continue;
				}

				$bookingFilter = [
					'RESOURCE_ID' => [
						$resource->getId(),
					],
					'WITHIN' => [
						'DATE_FROM' => $datePeriod->getDateFrom()->getTimestamp(),
						'DATE_TO' => $datePeriod->getDateTo()->getTimestamp(),
					],
				];
				if ($existingBooking)
				{
					$bookingFilter['!ID'] = $existingBooking->getId();
				}

				$bookingCollection = $this->bookingRepository->getList(
					filter: new BookingFilter($bookingFilter),
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

				return new FindResourceServiceResult(
					resource: $resource,
					datePeriod: $datePeriod,
				);
			}
		}

		throw new SlotUnavailableException();
	}
}
