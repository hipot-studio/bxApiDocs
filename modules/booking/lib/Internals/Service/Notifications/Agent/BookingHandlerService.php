<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Agent;

use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;
use Bitrix\Booking\Provider\Params\Booking\BookingSort;

class BookingHandlerService
{
	public function handleBookings(array $ids, callable $fn): void
	{
		if (empty($ids))
		{
			return;
		}

		$bookingRepository = Container::getBookingRepository();
		$bookingCollection = $bookingRepository->getList(
			filter: new BookingFilter([
				'ID' => $ids,
			]),
			sort: (new BookingSort([
				'ID' => 'ASC',
			]))->prepareSort(),
			select: (new BookingSelect([
				'EXTERNAL_DATA',
				'CLIENTS',
				'RESOURCES',
				'SKUS',
			]))->prepareSelect(),
		);

		if (!$bookingCollection->isEmpty())
		{
			$bookingRepository->withSkus($bookingCollection);
			$bookingRepository->withClientData($bookingCollection);
		}

		foreach ($bookingCollection as $booking)
		{
			$fn($booking);
		}
	}
}
