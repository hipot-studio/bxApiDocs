<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Agent;

use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;
use Bitrix\Booking\Provider\Params\Booking\BookingSort;

class BookingHandlerService
{
	private BookingRepositoryInterface $bookingRepository;

	public function __construct()
	{
		$this->bookingRepository = Container::getBookingRepository();
	}

	public function handleBookings(array $ids, callable $fn, bool $includeDeleted = false): void
	{
		if (empty($ids))
		{
			return;
		}

		$bookingCollection = $this->bookingRepository->getList(
			filter: new BookingFilter($this->makeFilter($ids, $includeDeleted)),
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
			$this->bookingRepository->withSkus($bookingCollection);
			$this->bookingRepository->withClientData($bookingCollection);
		}

		foreach ($bookingCollection as $booking)
		{
			$fn($booking);
		}
	}

	private function makeFilter(array $ids, bool $includeDeleted = false): array
	{
		$filter = [
			'ID' => $ids,
		];
		if ($includeDeleted)
		{
			$filter['INCLUDE_DELETED'] = true;
		}

		return $filter;
	}
}
