<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ORM\BookingMessageRepository;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessage;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;

abstract class BaseBookingTool extends ToolContract
{
	protected BookingRepositoryInterface $bookingRepository;
	protected BookingMessageRepository $bookingMessageRepository;

	protected BookingMessage|null $bookingMessage = null;
	protected Booking|null $contextBooking = null;

	public function __construct(TracedLogger $tracedLogger)
	{
		parent::__construct($tracedLogger);

		$this->bookingRepository = Container::getBookingRepository();
		$this->bookingMessageRepository = Container::getBookingMessageRepository();
	}

	protected function createFailureResponse(string $message): string
	{
		return "Failed to execute the tool '{$this->getName()}': {$message}";
	}

	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		$bookingMessage = $this->bookingMessageRepository->getById($userId);
		if (!$bookingMessage)
		{
			return false;
		}

		$this->bookingMessage = $bookingMessage;
		$this->setContextBookingByMessage($bookingMessage);

		return (bool)$this->contextBooking;
	}

	protected function hasAccessToBooking(Booking $booking): bool
	{
		$bookingPrimaryClient = $booking->getPrimaryClient();
		$contextBookingPrimaryClient = $this->contextBooking->getPrimaryClient();

		if (
			!$bookingPrimaryClient
			|| !$contextBookingPrimaryClient
		)
		{
			return false;
		}

		return $bookingPrimaryClient->isEqual($contextBookingPrimaryClient);
	}

	private function setContextBookingByMessage(BookingMessage $bookingMessage): void
	{
		$bookingId = $bookingMessage->getBookingId();
		if (!$bookingId)
		{
			return;
		}

		$booking = $this->bookingRepository->getList(
			filter: new BookingFilter([
				'ID' => $bookingId,
				'INCLUDE_DELETED' => true,
			]),
			select: (new BookingSelect(['CLIENTS']))->prepareSelect(),
		)->getFirstCollectionItem();

		if (
			!$booking
			|| $booking->getClientCollection()->isEmpty()
		)
		{
			return;
		}

		$this->contextBooking = $booking;
	}
}
