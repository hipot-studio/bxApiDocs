<?php

namespace Bitrix\ImConnector\InteractiveMessage\Connectors\Max;

use Bitrix\ImConnector\InteractiveMessage;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class Output extends InteractiveMessage\Output
{
	protected const BUTTON_TYPE_LINK = 'link';

	public function nativeMessageProcessing($message): array
	{
		if (empty($message['params']['isPaymentLink']))
		{
			return $message;
		}

		$url = $message['params']['url'] ?? null;
		if (empty($url))
		{
			return $message;
		}

		$message['inlineKeyboard'] = [
			[
				[
					'type' => self::BUTTON_TYPE_LINK,
					'text' => Loc::getMessage('IMCONNECTOR_INTERACTIVE_MESSAGE_MAX_PAYMENT_BUTTON_TITLE'),
					'url' => $url,
				],
			],
		];

		return $message;
	}
}
