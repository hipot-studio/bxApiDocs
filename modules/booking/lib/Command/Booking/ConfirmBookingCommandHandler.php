<?php

declare(strict_types=1);

namespace Bitrix\Booking\Command\Booking;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Exception\Booking\ConfirmBookingException;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Internals\Repository\TransactionHandlerInterface;
use Bitrix\Booking\Internals\Service\Journal\JournalEvent;
use Bitrix\Booking\Internals\Service\Journal\JournalServiceInterface;
use Bitrix\Booking\Internals\Service\Journal\JournalType;

class ConfirmBookingCommandHandler
{
	private BookingRepositoryInterface $bookingRepository;
	private TransactionHandlerInterface $transactionHandler;
	private JournalServiceInterface $journalService;

	public function __construct()
	{
		$this->bookingRepository = Container::getBookingRepository();
		$this->transactionHandler = Container::getTransactionHandler();
		$this->journalService = Container::getJournalService();
	}

	public function __invoke(ConfirmBookingCommand $command): Booking
	{
		$booking = $this->bookingRepository->getById($command->id);
		if (!$booking)
		{
			throw new ConfirmBookingException(
				message: 'booking not found',
				code: ConfirmBookingException::CODE_BOOKING_NOT_FOUND,
			);
		}

		if ($booking->isConfirmed())
		{
			throw new ConfirmBookingException(
				message: 'already confirmed',
				code: ConfirmBookingException::CODE_BOOKING_CONFIRMATION_ALREADY_CONFIRMED,
			);
		}

		return $this->transactionHandler->handle(
			fn: function() use ($command, $booking) {

				// update booking with confirmed flag
				$booking->setConfirmed(true);
				$this->bookingRepository->save($booking);

				// fire new BookingConfirmed event
				$this->journalService->append(
					new JournalEvent(
						entityId: $booking->getId(),
						type: JournalType::BookingConfirmed,
						data: array_merge(
							$command->toArray(),
							[
								'booking' => $booking->toArray(),
								'currentUserId' => $command->updatedBy,
							],
						),
					),
				);

				return $booking;
			},
			errType: ConfirmBookingException::class,
		);
	}
}
