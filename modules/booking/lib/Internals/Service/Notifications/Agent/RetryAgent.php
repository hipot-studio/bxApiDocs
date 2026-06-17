<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Agent;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\Notifications\Agent\DataSource\BaseDataSource;
use Bitrix\Booking\Internals\Service\Notifications\BookingMessageStatus;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessage;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessageCollection;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;

class RetryAgent
{
	private const MAX_RETRIES = 3;
	private const BATCH_LIMIT = 100;

	public static function execute(): string
	{
		$repository = Container::getBookingMessageRepository();
		$failedMessages = $repository->getFailedForRetry(self::MAX_RETRIES, self::BATCH_LIMIT);

		$groupedByType = self::groupByNotificationType($failedMessages);

		foreach ($groupedByType as $typeValue => $messages)
		{
			self::processGroup(NotificationType::from($typeValue), $messages);
		}

		return '\\' . self::class . '::execute();';
	}

	/**
	 * @return array<string, BookingMessage[]>
	 */
	private static function groupByNotificationType(BookingMessageCollection $messages): array
	{
		$grouped = [];
		foreach ($messages as $message)
		{
			$type = $message->getNotificationType();
			if ($type)
			{
				$grouped[$type->value][] = $message;
			}
		}

		return $grouped;
	}

	/**
	 * @param BookingMessage[] $messages
	 */
	private static function processGroup(NotificationType $notificationType, array $messages): void
	{
		$dataSource = BaseDataSource::make($notificationType);
		if (!$dataSource)
		{
			return;
		}

		$bookingIdToMessages = [];
		foreach ($messages as $message)
		{
			$bookingIdToMessages[$message->getBookingId()][] = $message;
		}

		$eligibleIds = $dataSource->filterEligibleForRetry(array_keys($bookingIdToMessages));
		$eligibleIdsMap = array_flip($eligibleIds);

		$repository = Container::getBookingMessageRepository();
		foreach ($bookingIdToMessages as $bookingId => $bookingMessages)
		{
			if (!isset($eligibleIdsMap[$bookingId]))
			{
				foreach ($bookingMessages as $message)
				{
					$message->setStatus(BookingMessageStatus::Cancelled);
					$message->setNextRetryAt(null);
					$repository->save($message);
				}
			}
		}

		$readyIds = $dataSource->filterReadyToSendNow($eligibleIds);
		if (empty($readyIds))
		{
			return;
		}

		$readyIdsMap = array_flip($readyIds);

		(new BookingHandlerService())->handleBookings(
			$readyIds,
			static function (Booking $booking) use ($bookingIdToMessages, $readyIdsMap) {
				if (!isset($readyIdsMap[$booking->getId()]))
				{
					return;
				}

				$bookingMessages = $bookingIdToMessages[$booking->getId()] ?? [];
				foreach ($bookingMessages as $failedMessage)
				{
					Container::getMessageSenderPicker()
						->pickByCode($failedMessage->getSenderCode())
						?->retry($booking, $failedMessage);
				}
			},
			$notificationType === NotificationType::Cancellation,
		);
	}
}
