<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM\Mapper;

use Bitrix\Booking\Internals\Model\EO_BookingMessage;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessage;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;

class BookingMessageMapper
{
	public function convertFromOrm(EO_BookingMessage $ormBookingMessage): BookingMessage
	{
		return
			(new BookingMessage())
				->setId($ormBookingMessage->getId())
				->setBookingId($ormBookingMessage->getBookingId())
				->setNotificationType(NotificationType::tryFrom($ormBookingMessage->getNotificationType()))
				->setSenderCode($ormBookingMessage->getSenderCode())
				->setExternalMessageId($ormBookingMessage->getExternalMessageId())
			;
	}
}
