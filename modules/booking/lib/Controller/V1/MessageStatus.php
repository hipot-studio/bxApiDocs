<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1;

use Bitrix\Booking\Controller\V1\Response\MessageStatusGetResponse;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\BookingMessageRepositoryInterface;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\MessageSenderPicker;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Booking\Provider\BookingProvider;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;
use Bitrix\Booking\Provider\Params\GridParams;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;

class MessageStatus extends BaseController
{
	private const SEMANTIC_SECONDARY = 'secondary';
	private const SEMANTIC_PRIMARY = 'primary';
	private const SEMANTIC_SUCCESS = 'success';
	private const SEMANTIC_FAILURE = 'failure';

	private BookingProvider $bookingProvider;
	private MessageSenderPicker $messageSenderPicker;
	private BookingMessageRepositoryInterface $bookingMessageRepository;

	public function __construct(Request $request = null)
	{
		parent::__construct($request);

		$this->bookingProvider = new BookingProvider();
		$this->messageSenderPicker = Container::getMessageSenderPicker();
		$this->bookingMessageRepository = Container::getBookingMessageRepository();
	}

	public function getAction(int $bookingId): MessageStatusGetResponse|null
	{
		$booking = $this->bookingProvider->getList(
			gridParams: new GridParams(
				filter: new BookingFilter(['ID' => $bookingId]),
				select: new BookingSelect(['CLIENTS']),
			),
			userId: (int)CurrentUser::get()->getId(),
		)->getFirstCollectionItem();

		if (!$booking)
		{
			return null;
		}

		if ($booking->getClientCollection()->isEmpty())
		{
			return new MessageStatusGetResponse(
				title: Loc::getMessage('BOOKING_CONTROLLER_MESSAGE_STATUS_SMS_TO_CLIENT'),
				description: Loc::getMessage('BOOKING_CONTROLLER_MESSAGE_STATUS_CLIENT_NOT_SPECIFIED'),
				semantic: self::SEMANTIC_SECONDARY,
				isDisabled: true,
			);
		}

		$lastSentMessage = $this->bookingMessageRepository->getLastByBookingId($bookingId);

		$notSentResponse = new MessageStatusGetResponse(
			title: Loc::getMessage('BOOKING_CONTROLLER_MESSAGE_STATUS_SMS_TO_CLIENT'),
			description: Loc::getMessage('BOOKING_CONTROLLER_MESSAGE_STATUS_NOT_SENT'),
			semantic: self::SEMANTIC_SECONDARY,
		);
		if (!$lastSentMessage)
		{
			return $notSentResponse;
		}

		$messageSender = $this->messageSenderPicker->pickByBooking($booking);
		if (!$messageSender)
		{
			return $notSentResponse;
		}

		$messageStatus = $messageSender->getMessageStatus($lastSentMessage->getExternalMessageId());
		$title = NotificationType::getName($lastSentMessage->getNotificationType()->value);
		$description = $messageStatus->getName();

		/**
		 * Replace description and semantic for confirmation type of message in case it has been already confirmed
		 */
		if (
			$lastSentMessage->getNotificationType() === NotificationType::Confirmation
			&& $booking->isConfirmed()
		)
		{
			return new MessageStatusGetResponse(
				title: $title,
				description: Loc::getMessage('BOOKING_CONTROLLER_MESSAGE_STATUS_BOOKING_CONFIRMED'),
				semantic: self::SEMANTIC_SUCCESS,
			);
		}

		$semanticsMap = [
			\Bitrix\Booking\Internals\Service\Notifications\MessageStatus::SEMANTIC_SUCCESS => self::SEMANTIC_PRIMARY,
			\Bitrix\Booking\Internals\Service\Notifications\MessageStatus::SEMANTIC_FAILURE => self::SEMANTIC_FAILURE,
		];

		return new MessageStatusGetResponse(
			title: $title,
			description: $description,
			semantic: $semanticsMap[$messageStatus->getSemantic()],
		);
	}
}
