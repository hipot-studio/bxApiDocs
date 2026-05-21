<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\MessageSender;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Main\Config\Option;

class MessageSenderPicker
{
	public function __construct(
		private readonly BookingRepositoryInterface $bookingRepository,
	)
	{
	}

	/**
	 * @return BaseMessageSender[]
	 */
	public function getSenders(): array
	{
		$result = [
			Container::getCrmMessageSender(),
		];

		if ((bool)Option::get('booking', 'ai_call_message_sender_enabled', false))
		{
			$result[] = Container::getAiCallMessageSender();
		}

		return $result;
	}

	public function pickByBookingId(int $id): BaseMessageSender|null
	{
		$booking = $this->bookingRepository->getById(
			id: $id,
			withCounters: false,
			withExternalData: false,
			withSkus: false,
		);
		if (!$booking)
		{
			return null;
		}

		return $this->pickByBooking($booking);
	}

	public function pickByBooking(Booking $booking): BaseMessageSender|null
	{
		return $this->pickByCode($booking->getPrimaryResource()?->getSenderCode());
	}

	private function pickByCode(string|null $code): BaseMessageSender|null
	{
		$senders = $this->getSenders();
		foreach ($senders as $sender)
		{
			if ($sender->getCode() === $code)
			{
				return $sender;
			}
		}

		return null;
	}
}
