<?php

declare(strict_types=1);

namespace Bitrix\Booking\Command\Booking;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\Booking\BookingCollection;
use Bitrix\Booking\Entity\ExternalData\ItemType\CrmDealItemType;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Exception\Booking\BookingNotFoundException;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Exception\InvalidArgumentException;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Internals\Repository\TransactionHandlerInterface;
use Bitrix\Booking\Internals\Service\BookingService;
use Bitrix\Booking\Internals\Service\DealForBookingService;
use Bitrix\Booking\Internals\Service\Journal\JournalEvent;
use Bitrix\Booking\Internals\Service\Journal\JournalServiceInterface;
use Bitrix\Booking\Internals\Service\Journal\JournalType;
use Bitrix\Booking\Provider\BookingProvider;
use Bitrix\Booking\Service\BookingFeature;

class CreateDealForBookingCommandHandler
{
	private readonly BookingRepositoryInterface $bookingRepository;
	private readonly TransactionHandlerInterface $transactionHandler;
	private readonly DealForBookingService $dealForBookingService;
	private readonly BookingService $bookingService;
	private readonly JournalServiceInterface $journalService;

	public function __construct()
	{
		$this->bookingRepository = Container::getBookingRepository();
		$this->transactionHandler = Container::getTransactionHandler();
		$this->dealForBookingService = Container::getDealForBookingService();
		$this->bookingService = Container::getBookingService();
		$this->journalService = Container::getJournalService();
	}

	public function __invoke(CreateDealForBookingCommand $command): Booking
	{
		$this->checkFeatures();

		$booking = $this->bookingRepository->getById(
			id: $command->bookingId,
			userId: $command->updatedBy,
		);

		if (!$booking)
		{
			throw new BookingNotFoundException();
		}

		return $this->transactionHandler->handle(
			fn: function () use ($booking, $command) {
				if (
					!$booking
						->getExternalDataCollection()
						->filterByType((new CrmDealItemType())->buildFilter())
						->isEmpty()
				)
				{
					throw new InvalidArgumentException('Deal is already linked to the booking');
				}

				$this->dealForBookingService->createAndLinkDeal($booking, $command->updatedBy);
				(new BookingProvider())->withExternalData(new BookingCollection($booking));

				$this->journalService->append(
					new JournalEvent(
						entityId: $booking->getId(),
						type: JournalType::BookingUpdated,
						data: [
							'updatedBy' => $command->updatedBy,
							'allowOverbooking' => true,
							'booking' => $booking->toArray(),
							'currentUserId' => $command->updatedBy,
							'prevBooking' => $booking->toArray(),
							'isOverbooking' => $this->bookingService
								->checkIntersection($booking, true)
								->hasIntersections(),
						],
					),
				);

				return $booking;
			},
		);
	}

	private function checkFeatures(): void
	{
		if (!BookingFeature::isFeatureEnabled(BookingFeature::FEATURE_ID_BOOKING))
		{
			throw new Exception('Feature is not available');
		}
	}
}
