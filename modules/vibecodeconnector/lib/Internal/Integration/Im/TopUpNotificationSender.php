<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im;

use Bitrix\Main\Loader;

class TopUpNotificationSender
{
	public function send(int $fromUserId, int $toUserId, string $tag, \Closure $message): bool
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		return $this->sendNotification([
			'TO_USER_ID' => $toUserId,
			'FROM_USER_ID' => $fromUserId,
			'NOTIFY_TYPE' => IM_NOTIFY_FROM,
			'NOTIFY_MODULE' => 'vibecodeconnector',
			'NOTIFY_EVENT' => 'vibe_balance_top_up_request',
			'NOTIFY_TAG' => $tag,
			'NOTIFY_MESSAGE' => $message,
			'NOTIFY_MESSAGE_OUT' => $message,
			'PARAMS' => [
				'COMPONENT_ID' => 'DefaultEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => '#AUTHOR#',
					'PLAIN_TEXT' => $message,
				],
			],
		]);
	}

	protected function sendNotification(array $fields): bool
	{
		return (bool)\CIMNotify::Add($fields);
	}
}
