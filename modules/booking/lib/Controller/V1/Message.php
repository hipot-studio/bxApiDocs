<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1;

use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessage;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\MessageSenderPicker;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;

class Message extends BaseController
{
	private BookingRepositoryInterface $bookingRepository;
	private MessageSenderPicker $messageSenderPicker;

	public function __construct(Request $request = null)
	{
		parent::__construct($request);

		$this->bookingRepository = Container::getBookingRepository();
		$this->messageSenderPicker = Container::getMessageSenderPicker();
	}

	public function sendAction(int $bookingId, string $notificationType): BookingMessage|null
	{
		$notificationType = NotificationType::tryFrom($notificationType);
		if (!$notificationType)
		{
			$this->addError(new Error(Loc::getMessage('BOOKING_CONTROLLER_MESSAGE_ERROR')));

			return null;
		}

		$bookingCollection = $this->bookingRepository->getList(
			limit: 1,
			filter: new BookingFilter([
				'ID' => $bookingId,
				'HAS_CLIENTS' => true,
				'HAS_RESOURCES' => true,
			]),
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

		$booking = $bookingCollection->getFirstCollectionItem();
		if (!$booking)
		{
			$this->addError(new Error(Loc::getMessage('BOOKING_CONTROLLER_MESSAGE_ERROR')));

			return null;
		}

		$messageSender = $this->messageSenderPicker->pickByBooking($booking);
		if (!$messageSender)
		{
			$this->addError(new Error(Loc::getMessage('BOOKING_CONTROLLER_MESSAGE_ERROR')));

			return null;
		}

		$sendResult = $messageSender->send($booking, $notificationType);
		if (!$sendResult->isSuccess())
		{
			$this->addError(new Error(Loc::getMessage('BOOKING_CONTROLLER_MESSAGE_ERROR')));

			return null;
		}

		return (new BookingMessage())
			->setBookingId($bookingId)
			->setNotificationType($notificationType)
		;
	}
}
