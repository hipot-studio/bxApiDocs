<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\MessageSender;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Exception\ErrorBuilder;
use Bitrix\Booking\Internals\Integration\Pull\PushEvent;
use Bitrix\Booking\Internals\Integration\Pull\PushService;
use Bitrix\Booking\Internals\Repository\BookingMessageRepositoryInterface;
use Bitrix\Booking\Internals\Service\Notifications\BookingMessageStatus;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessage;
use Bitrix\Booking\Internals\Service\Notifications\MessageStatus;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Booking\Internals\Service\Journal\EventProcessor\PushPull\PushPullCommandType;
use Bitrix\Main\Error;

abstract class BaseMessageSender
{
	public function __construct(
		protected readonly BookingMessageRepositoryInterface $bookingMessageRepository,
		private readonly PushService $pushService,
	)
	{
	}

	/**
	 * Sends a notification to the client.
	 * Creates a BookingMessage record, calls doSend(), and on success
	 * updates the record with the external message ID.
	 * On failure, the record is deleted.
	 */
	public function send(Booking $booking, NotificationType $notificationType): MessageSendResult
	{
		$validationResult = $this->validateCanSend($notificationType);
		if ($validationResult !== null)
		{
			return $validationResult;
		}

		$bookingMessage = $this->createBookingMessage($booking, $notificationType);

		try
		{
			$result = $this->doSend($bookingMessage, $booking);
		}
		catch (\Throwable $e)
		{
			$this->bookingMessageRepository->delete($bookingMessage->getId());

			throw $e;
		}

		$externalMessageId = $result->getId();
		if (!$result->isSuccess())
		{
			$this->bookingMessageRepository->delete($bookingMessage->getId());

			return $result;
		}

		if ($externalMessageId === null)
		{
			$this->bookingMessageRepository->delete($bookingMessage->getId());
			$result->addError(new Error('External message ID is missing after successful send'));

			return $result;
		}

		$currentBookingMessage = $this->bookingMessageRepository->getById($bookingMessage->getId());
		if ($currentBookingMessage === null)
		{
			return $result;
		}

		$currentBookingMessage
			->setExternalMessageId($externalMessageId)
			->setSentAt(time())
		;
		$this->bookingMessageRepository->save($currentBookingMessage);

		$this->sendPushEvent($currentBookingMessage, $booking);

		return $result;
	}

	/**
	 * Retries sending a previously failed message.
	 * Increments retry count on every attempt (success or failure).
	 * On success, updates the existing record with the new external message ID
	 * and resets the status to initial. On failure, sets a fallback nextRetryAt.
	 */
	public function retry(Booking $booking, BookingMessage $failedMessage): void
	{
		if (
			!$this->canUse()
			|| !in_array(
				$failedMessage->getNotificationType(),
				$this->getSupportedNotificationTypes(),
				true,
			)
		)
		{
			$this->incrementRetryCount($failedMessage);

			return;
		}

		try
		{
			$result = $this->doSend($failedMessage, $booking);
		}
		catch (\Throwable $e)
		{
			$this->incrementRetryCount($failedMessage);

			throw $e;
		}

		if (!$result->isSuccess() || $result->getId() === null)
		{
			$this->incrementRetryCount($failedMessage);

			return;
		}

		$currentBookingMessage = $this->bookingMessageRepository->getById($failedMessage->getId());
		if ($currentBookingMessage === null)
		{
			return;
		}

		// nextRetryAt differs if a fresh markFailed ran during doSend
		// (retry runs strictly after the original nextRetryAt, so the new
		// timestamp is always greater than the old one).
		$callbackAlreadyFinalized = (
			$currentBookingMessage->getStatus() !== BookingMessageStatus::Failed
			|| $currentBookingMessage->getNextRetryAt() !== $failedMessage->getNextRetryAt()
		);

		$currentBookingMessage
			->setExternalMessageId($result->getId())
			->setRetryCount($currentBookingMessage->getRetryCount() + 1)
			->setSentAt(time())
		;

		if (!$callbackAlreadyFinalized)
		{
			$currentBookingMessage
				->setStatus($this->getInitialMessageStatus())
				->setNextRetryAt(null)
			;
		}

		$this->bookingMessageRepository->save($currentBookingMessage);

		$this->sendPushEvent($currentBookingMessage, $booking);
	}

	/**
	 * Returns the initial message status when a record is first created.
	 * Senders with asynchronous delivery (e.g. AiCall) override this
	 * to return Pending; the default is Success (delivery assumed immediate).
	 */
	public function getInitialMessageStatus(): BookingMessageStatus
	{
		return BookingMessageStatus::Success;
	}

	/**
	 * Returns whether this sender is available for use
	 * (e.g. required modules installed, license valid).
	 */
	abstract public function canUse(): bool;

	/**
	 * Returns the unique sender code used to identify this sender
	 * in the resource notification settings.
	 */
	abstract public function getCode(): string;

	/**
	 * Queries the external system for the current delivery status
	 * of a previously sent message.
	 */
	abstract public function getMessageStatus(string $messageId): MessageStatus;

	/**
	 * Returns the notification types this sender can handle.
	 *
	 * @return NotificationType[]
	 */
	abstract public function getSupportedNotificationTypes(): array;

	abstract protected function doSend(
		BookingMessage $bookingMessage,
		Booking $booking,
	): MessageSendResult;

	/**
	 * Validates that the sender is available and supports
	 * the given notification type. Returns null if valid,
	 * or a MessageSendResult with errors otherwise.
	 */
	private function validateCanSend(NotificationType $notificationType): MessageSendResult|null
	{
		if (!$this->canUse())
		{
			return (new MessageSendResult())->addError(
				ErrorBuilder::build('Sender is not available')
			);
		}

		if (!in_array($notificationType, $this->getSupportedNotificationTypes(), true))
		{
			return (new MessageSendResult())->addError(
				ErrorBuilder::build('Notification type is not supported by the sender')
			);
		}

		return null;
	}

	private function createBookingMessage(
		Booking $booking,
		NotificationType $notificationType,
	): BookingMessage
	{
		$bookingMessage = (new BookingMessage())
			->setBookingId($booking->getId())
			->setNotificationType($notificationType)
			->setSenderCode($this->getCode())
			->setExternalMessageId('')
			->setStatus($this->getInitialMessageStatus())
			->setSentAt(time())
		;

		$this->bookingMessageRepository->save($bookingMessage);

		return $bookingMessage;
	}

	private function sendPushEvent(BookingMessage $bookingMessage, Booking $booking): void
	{
		$this->pushService->sendEvent(
			new PushEvent(
				command: PushPullCommandType::MessageSent->value,
				tag: PushPullCommandType::MessageSent->getTag(),
				params: [
					'message' => $bookingMessage->toArray(),
				],
				entityId: $booking->getId(),
			),
		);
	}

	private function incrementRetryCount(BookingMessage $message): void
	{
		$message
			->setRetryCount($message->getRetryCount() + 1)
			->setNextRetryAt(time() + 10 * 60)
		;
		$this->bookingMessageRepository->save($message);
	}
}
