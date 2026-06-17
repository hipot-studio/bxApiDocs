<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\AiAssistant;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Internals\Service\AiAssistant\Mapper\BookingMapper;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;
use DateTimeImmutable;

class ContextProvider
{
	public function __construct(
		private readonly BookingRepositoryInterface $bookingRepository,
		private readonly DateTimeService $dateTimeService,
		private readonly BookingMapper $bookingMapper,
	)
	{
	}

	public function getContext(int $bookingId): array|null
	{
		$booking = $this->getBooking($bookingId);

		return [
			'currentDateTime' => $this->getCurrentDateTime($booking),
			'booking' => $booking ? $this->bookingMapper->mapFromEntity($booking) : [],
		];
	}

	private function getCurrentDateTime(Booking|null $booking): string
	{
		$timezone = $booking?->getDatePeriod()?->getDateFrom()?->getTimezone();
		$now = new DateTimeImmutable('now', $timezone);

		return $this->dateTimeService->formatDateTime($now);
	}

	private function getBooking(int $bookingId): Booking|null
	{
		if (!$bookingId)
		{
			return null;
		}

		$list = $this->bookingRepository->getList(
			filter: new BookingFilter([
				'ID' => $bookingId,
				'INCLUDE_DELETED' => true,
			]),
			select: (new BookingSelect([
				'CLIENTS',
				'SKUS',
				'RESOURCES',
			]))->prepareSelect(),
		);

		$this->bookingRepository->withClientData($list);
		$this->bookingRepository->withSkus($list);

		return $list->getFirstCollectionItem();
	}
}
