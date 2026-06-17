<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Agent;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\Notifications\Agent\DataSource\BaseDataSource;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;

class NotificationAgent
{
	public static function execute(): string
	{
		$notificationTypes = Container::getMessageSenderNotification()->getAllSupportedNotificationTypes();
		foreach ($notificationTypes as $notificationType)
		{
			$dataSource = BaseDataSource::make($notificationType);
			if (!$dataSource)
			{
				continue;
			}

			(new BookingHandlerService())->handleBookings(
				$dataSource->getBookingIdsForSend(),
				static function (Booking $booking) use ($notificationType) {
					Container::getMessageSenderPicker()->pickByBooking($booking)?->send($booking, $notificationType);
				},
				$notificationType === NotificationType::Cancellation,
			);
		}

		return '\\' . self::class . '::execute();';
	}
}
