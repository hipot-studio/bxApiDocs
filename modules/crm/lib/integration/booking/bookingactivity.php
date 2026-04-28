<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Booking;

use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Main\Loader;

class BookingActivity
{
	public function getMessageMenuItems(int $bookingId): array
	{
		if (!Loader::includeModule('booking'))
		{
			return [];
		}

		$sender = Container::getMessageSenderPicker()->pickByBookingId($bookingId);
		if (!$sender || !$sender->canUse())
		{
			return [];
		}

		$supportedNotificationTypes = $sender->getSupportedNotificationTypes();

		$result = [];
		foreach ($supportedNotificationTypes as $notificationType)
		{
			$result[] = [
				'code' => $notificationType->value,
				'name' => NotificationType::getName($notificationType),
				'params' => [
					'notificationType' => $notificationType->value,
				],
			];
		}

		return $result;
	}
}
