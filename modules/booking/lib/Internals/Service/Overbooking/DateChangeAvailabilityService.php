<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Overbooking;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\DatePeriod;
use Bitrix\Booking\Internals\Exception\Booking\BookingNotFoundException;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Internals\Exception\InvalidArgumentException;
use DateTimeImmutable;

class DateChangeAvailabilityService
{
	public function __construct(
		private readonly BookingRepositoryInterface $bookingRepository,
		private readonly OverlapPolicy $overlapPolicy,
	)
	{
	}

	public function canChangeDate(int $bookingId, int $dateFromTs, int $dateToTs): bool
	{
		$booking = $this->bookingRepository->getById(
			id: $bookingId,
			withCounters: false,
			withClientsData: false,
			withExternalData: false,
			withSkus: false,
		);

		if ($booking === null)
		{
			throw new BookingNotFoundException();
		}

		$currentDatePeriod = $booking->getDatePeriod();
		if ($currentDatePeriod === null)
		{
			throw new InvalidArgumentException('Booking date period is not specified');
		}

		$newDatePeriod = $this->createDatePeriod($dateFromTs, $dateToTs);
		$uncoveredDatePeriods = $newDatePeriod->exclude($currentDatePeriod);

		if ($uncoveredDatePeriods->isEmpty())
		{
			return true;
		}

		foreach ($uncoveredDatePeriods as $uncoveredDatePeriod)
		{
			$probeBooking = $this->createDraftBooking($booking, $uncoveredDatePeriod);
			$intersections = $this->bookingRepository->getIntersectionsList($probeBooking);
			$intersectionResult = $this->overlapPolicy->getIntersectionsList($probeBooking, $intersections);

			if (!$intersectionResult->isSuccess())
			{
				return false;
			}
		}

		return true;
	}

	private function createDraftBooking(Booking $sourceBooking, DatePeriod $datePeriod): Booking
	{
		return (new Booking())
			->setId($sourceBooking->getId())
			->setDatePeriod($datePeriod)
			->setResourceCollection(clone $sourceBooking->getResourceCollection())
		;
	}

	private function createDatePeriod(int $dateFromTs, int $dateToTs): DatePeriod
	{
		return new DatePeriod(
			new DateTimeImmutable('@' . $dateFromTs),
			new DateTimeImmutable('@' . $dateToTs),
		);
	}
}
