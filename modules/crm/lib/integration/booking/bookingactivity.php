<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Booking;

use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Main\Loader;

class BookingActivity
{
	public function getMessageSenderInfo(int $bookingId): ?array
	{
		if (!Loader::includeModule('booking'))
		{
			return null;
		}

		$sender = Container::getMessageSenderPicker()->pickByBookingId($bookingId);
		if (!$sender || !$sender->canUse())
		{
			return null;
		}

		$notificationTypes = [];
		foreach ($sender->getSupportedNotificationTypes() as $notificationType)
		{
			if ($notificationType === NotificationType::Cancellation)
			{
				continue;
			}

			$notificationTypes[] = [
				'code' => $notificationType->value,
				'name' => NotificationType::getName($notificationType),
			];
		}

		return [
			'senderCode' => $sender->getCode(),
			'notificationTypes' => $notificationTypes,
		];
	}
}
