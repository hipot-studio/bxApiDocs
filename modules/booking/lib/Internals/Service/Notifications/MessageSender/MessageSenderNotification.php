<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\MessageSender;

use Bitrix\Booking\Internals\Service\Notifications\NotificationType;

class MessageSenderNotification
{
	public function __construct(
		private readonly MessageSenderPicker $senderPicker,
	)
	{
	}

	/**
	 * @return NotificationType[]
	 */
	public function getAllSupportedNotificationTypes(): array
	{
		$result = [];

		$senders = $this->senderPicker->getSenders();
		foreach ($senders as $sender)
		{
			$notificationTypes = $sender->getSupportedNotificationTypes();
			foreach ($notificationTypes as $notificationType)
			{
				$result[$notificationType->value] = $notificationType;
			}
		}

		return array_values($result);
	}

	/**
	 * @return BaseMessageSender[]
	 */
	public function getSendersByNotificationType(NotificationType $notificationType): array
	{
		$result = [];

		$senders = $this->senderPicker->getSenders();
		foreach ($senders as $sender)
		{
			if (!in_array($notificationType, $sender->getSupportedNotificationTypes(), true))
			{
				continue;
			}

			$result[] = $sender;
		}

		return $result;
	}
}
