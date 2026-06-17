<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM\Mapper;

use Bitrix\Booking\Internals\Service\Notifications\BookingMessageStatus;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessage;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;

class BookingMessageMapper
{
	public function convertFromRow(array $row): BookingMessage
	{
		return
			(new BookingMessage())
				->setId((int)$row['ID'])
				->setBookingId((int)$row['BOOKING_ID'])
				->setNotificationType(NotificationType::tryFrom($row['NOTIFICATION_TYPE']))
				->setSenderCode($row['SENDER_CODE'])
				->setExternalMessageId($row['EXTERNAL_MESSAGE_ID'] ?? null)
				->setStatus(BookingMessageStatus::tryFrom(
					$row['STATUS'] ?? BookingMessageStatus::Success->value
				))
				->setRetryCount((int)($row['RETRY_COUNT'] ?? 0))
				->setNextRetryAt($row['NEXT_RETRY_AT']?->getTimestamp())
				->setSentAt($row['SENT_AT']?->getTimestamp())
		;
	}
}
