<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\MessageSender;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Main\Loader;

class MessageSenderPicker
{
	public function __construct(
		private readonly BookingRepositoryInterface $bookingRepository,
	)
	{
	}

	public function canUseAnySender(): bool
	{
		$senders = $this->getSenders();

		foreach ($senders as $sender)
		{
			if ($sender->canUse())
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return BaseMessageSender[]
	 */
	public function getSenders(): array
	{
		return [
			Container::getCrmMessageSender(),
		];
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
		return $this->getDefaultProvider();
	}

	private function getDefaultProvider(): BaseMessageSender
	{
		if (!Loader::includeModule('crm'))
		{
			return Container::getDummyBaseMessageSender();
		}

		return Container::getCrmMessageSender();
	}
}
