<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command\Notification;

use Bitrix\Disk\Driver;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Result;

class NotifyThroughImSimpleSystemNotificationCommandHandler
{
	/**
	 * @param NotifyThroughImSimpleSystemNotificationCommand $command
	 * @return Result
	 * @throws LoaderException
	 */
	public function __invoke(NotifyThroughImSimpleSystemNotificationCommand $command): Result
	{
		$result = new Result();

		if (!Loader::includeModule('im'))
		{
			$result->addError(new Error('IM module is not installed'));

			return $result;
		}

		$userIds = $command->recipients;

		$userIds = array_unique($userIds);

		foreach ($userIds as $userId)
		{
			if ($userId <= 0)
			{
				$result->addError(new Error('Invalid user Id: ' . $userId));

				continue;
			}

			$notificationId = \CIMNotify::add([
				'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
				'NOTIFY_MODULE' => Driver::INTERNAL_MODULE_ID,
				'FROM_USER_ID' => 0,
				'TO_USER_ID' => $userId,
				'NOTIFY_TITLE' => $command->title,
				'NOTIFY_MESSAGE' => $command->message,
			]);

			if ($notificationId === false)
			{
				$result->addError(new Error('Failed to send IM notification to user with Id ' . $userId));
			}
		}

		return $result;
	}
}