<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\MessageSender;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Exception\ErrorBuilder;
use Bitrix\Booking\Internals\Integration\Pull\PushEvent;
use Bitrix\Booking\Internals\Integration\Pull\PushService;
use Bitrix\Booking\Internals\Repository\BookingMessageRepositoryInterface;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessage;
use Bitrix\Booking\Internals\Service\Notifications\MessageStatus;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Booking\Internals\Service\Journal\EventProcessor\PushPull\PushPullCommandType;

abstract class BaseMessageSender
{
	public function __construct(
		protected readonly BookingMessageRepositoryInterface $bookingMessageRepository,
	)
	{
	}

	public function send(Booking $booking, NotificationType $notificationType): MessageSendResult
	{
		if (!$this->canUse())
		{
			return (new MessageSendResult())->addError(
				ErrorBuilder::build('Sender is not available')
			);
		}

		$result = $this->doSend($booking, $notificationType);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$bookingMessage = (new BookingMessage())
			->setBookingId($booking->getId())
			->setNotificationType($notificationType)
			->setSenderCode($this->getCode())
			->setExternalMessageId($result->getId())
		;

		$this->bookingMessageRepository->save($bookingMessage);

		(new PushService())->sendEvent(
			new PushEvent(
				command: PushPullCommandType::MessageSent->value,
				tag: PushPullCommandType::MessageSent->getTag(),
				params: [
					'message' => $bookingMessage->toArray(),
				],
				entityId: $booking->getId(),
			),
		);

		return $result;
	}

	abstract protected function doSend(Booking $booking, NotificationType $notificationType): MessageSendResult;

	abstract public function canUse(): bool;

	abstract public function getCode(): string;

	abstract public function getMessageStatus(string $messageId): MessageStatus;

	/**
	 * @return NotificationType[]
	 */
	abstract public function getSupportedNotificationTypes(): array;
}
