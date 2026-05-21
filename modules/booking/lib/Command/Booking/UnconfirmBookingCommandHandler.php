<?php

declare(strict_types=1);

namespace Bitrix\Booking\Command\Booking;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Exception\Booking\UnconfirmBookingException;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Internals\Repository\TransactionHandlerInterface;
use Bitrix\Booking\Internals\Service\Journal\JournalEvent;
use Bitrix\Booking\Internals\Service\Journal\JournalServiceInterface;
use Bitrix\Booking\Internals\Service\Journal\JournalType;

class UnconfirmBookingCommandHandler
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

	public function __invoke(UnconfirmBookingCommand $command): Booking
	{
		$booking = $this->bookingRepository->getById($command->id);
		if (!$booking)
		{
			throw new UnconfirmBookingException(
				message: 'booking not found',
				code: UnconfirmBookingException::CODE_BOOKING_NOT_FOUND,
			);
		}

		if (!$booking->isConfirmed())
		{
			throw new UnconfirmBookingException(
				message: 'already unconfirmed',
				code: UnconfirmBookingException::CODE_BOOKING_UNCONFIRMATION_ALREADY_UNCONFIRMED,
			);
		}

		return $this->transactionHandler->handle(
			fn: function() use ($command, $booking) {

				// update booking with unconfirmed flag
				$booking->setConfirmed(false);
				$this->bookingRepository->save($booking);

				// fire new BookingUnconfirmed event
				$this->journalService->append(
					new JournalEvent(
						entityId: $booking->getId(),
						type: JournalType::BookingUnconfirmed,
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
			errType: UnconfirmBookingException::class,
		);
	}
}
