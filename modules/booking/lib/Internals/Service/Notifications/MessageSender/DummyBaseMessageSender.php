<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\MessageSender;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Repository\BookingMessageRepositoryInterface;
use Bitrix\Booking\Internals\Service\Notifications\MessageStatus;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Main\Event;

class DummyBaseMessageSender extends BaseMessageSender
{
	public function __construct(
		BookingMessageRepositoryInterface $bookingMessageRepository,
		private readonly BookingDataExtractor $bookingDataExtractor,
	)
	{
		parent::__construct($bookingMessageRepository);
	}

	public function getCode(): string
	{
		return 'dummy';
	}

	protected function doSend(Booking $booking, NotificationType $notificationType): MessageSendResult
	{
		(new Event(
			'booking',
			'onDummyMessageSenderSendMessage',
			[
				'NOTIFICATION_TYPE' => $notificationType->value,
				'VARIABLES' => [
					'DATE_FROM' => $this->bookingDataExtractor->getDateFrom($booking),
					'DATE_TO' => $this->bookingDataExtractor->getDateTo($booking),
					'DATE_TIME_FROM' => $this->bookingDataExtractor->getDateTimeFrom($booking),
					'DATE_TIME_TO' => $this->bookingDataExtractor->getDateTimeTo($booking),
					'RESOURCE_TYPE_NAME' => $this->bookingDataExtractor->getResourceTypeName($booking),
					'RESOURCE_NAME' => $this->bookingDataExtractor->getResourceName($booking),
					'CLIENT_NAME' => $this->bookingDataExtractor->getClientName($booking),
					'MANAGER_NAME' => $this->bookingDataExtractor->getManagerName($booking),
					'COMPANY_NAME' => $this->bookingDataExtractor->getCompanyName(),
					'CONFIRMATION_LINK' => $this->bookingDataExtractor->getConfirmationLink($booking),
					'DELAYED_CONFIRMATION_LINK' => $this->bookingDataExtractor->getDelayedConfirmationLink($booking),
					'FEEDBACK_LINK' => $this->bookingDataExtractor->getFeedbackLink(),
					'SERVICES' => $this->bookingDataExtractor->getServices($booking),
				],
			]
		))->send();

		return (new MessageSendResult())->setId((string)random_int(1, 1000));
	}

	public function getMessageStatus(string $messageId): MessageStatus
	{
		return MessageStatus::success('Sent');
	}

	public function canUse(): bool
	{
		return true;
	}

	public function getSupportedNotificationTypes(): array
	{
		return NotificationType::cases();
	}
}
