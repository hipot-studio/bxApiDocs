<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Agent;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\Notifications\Agent\DataSource\BaseDataSource;
use Bitrix\Booking\Internals\Service\Notifications\Agent\DataSource\DataSourceCancellation;
use Bitrix\Booking\Internals\Service\Notifications\Agent\DataSource\DataSourceConfirmation;
use Bitrix\Booking\Internals\Service\Notifications\Agent\DataSource\DataSourceDelayed;
use Bitrix\Booking\Internals\Service\Notifications\Agent\DataSource\DataSourceInfo;
use Bitrix\Booking\Internals\Service\Notifications\Agent\DataSource\DataSourceReminder;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;

class NotificationAgent
{
	public static function execute(): string
	{
		$notificationTypes = Container::getMessageSenderNotification()->getAllSupportedNotificationTypes();
		foreach ($notificationTypes as $notificationType)
		{
			$dataSource = self::makeDataSource($notificationType);
			if (!$dataSource)
			{
				continue;
			}

			(new BookingHandlerService())->handleBookings(
				$dataSource->getBookingIds(),
				static function (Booking $booking) use ($notificationType) {
					Container::getMessageSenderPicker()->pickByBooking($booking)?->send($booking, $notificationType);
				},
				$notificationType === NotificationType::Cancellation,
			);
		}

		return '\\' . self::class . '::execute();';
	}

	private static function makeDataSource(NotificationType $notificationType): BaseDataSource|null
	{
		return match ($notificationType)
		{
			NotificationType::Info => new DataSourceInfo(),
			NotificationType::Confirmation => new DataSourceConfirmation(),
			NotificationType::Reminder => new DataSourceReminder(),
			NotificationType::Delayed => new DataSourceDelayed(),
			NotificationType::Cancellation => new DataSourceCancellation(),
			default => null,
		};
	}
}
